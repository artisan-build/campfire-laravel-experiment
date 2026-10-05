<?php

namespace App\Support;

use App\Models\Blob;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Derived images.
 *
 * Upstream shelled out to libvips for every variant and to ffmpeg/ffprobe for video, and neither is
 * on Laravel Cloud's PHP runtime. Images go through intervention/image on the PHP extension that is
 * there (Imagick when present, otherwise GD), so the common path needs no binary at all. Video
 * posters still need ffmpeg and degrade to "no poster" when it is absent.
 */
final class Media
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public function manager(): ImageManager
    {
        return new ImageManager(extension_loaded('imagick') ? new ImagickDriver : new GdDriver);
    }

    /**
     * Where ffmpeg/ffprobe actually are, or null when this runtime has neither.
     *
     * The vendored runtime wins over PATH. Laravel Cloud's PHP image ships no ffmpeg, so
     * campfire:provision-ffmpeg installs one into the deploy artifact during the build step and
     * config('campfire.ffmpeg.directory') points here — no environment variable, and no reliance on
     * the build having prepended anything to PATH, which is exactly what silently failed when the
     * Rust sibling tried this. PATH is still consulted second, so a developer's own ffmpeg works.
     */
    public function binary(string $name): ?string
    {
        $runtime = app(FfmpegRuntime::class);

        if ($runtime->installed($name)) {
            return $runtime->path($name);
        }

        return (new ExecutableFinder)->find($name);
    }

    public function previewable(?Blob $blob): bool
    {
        if (! $blob) {
            return false;
        }

        return in_array($blob->content_type, self::IMAGE_TYPES, true)
            || (in_array($blob->content_type, self::VIDEO_TYPES, true) && $this->binary('ffmpeg') !== null);
    }

    /**
     * Returns the variant's path on the storage disk.
     */
    public function variant(Blob $blob, array $variation): string
    {
        $size = $variation['resize_to_limit'] ?? [1200, 800];
        if (! is_array($size) || count($size) !== 2) {
            abort(422);
        }
        [$width, $height] = array_map('intval', $size);
        abort_unless($width > 0 && $height > 0 && $width <= 4096 && $height <= 4096, 422);
        $format = $variation['format'] ?? 'webp';
        abort_unless(in_array($format, ['webp', 'png', 'jpeg'], true), 422);

        $storage = app(BlobStorage::class);
        $digest = hash('sha256', json_encode([$blob->key, $width, $height, $format]));
        $path = $storage->variantDirectory($blob).'/'.$digest.'.'.$format;

        if ($storage->disk()->exists($path)) {
            return $path;
        }

        abort_unless($storage->disk()->exists($storage->path($blob)), 404);
        $source = $this->download($blob);

        try {
            if (in_array($blob->content_type, self::VIDEO_TYPES, true)) {
                $source = $this->poster($source, $width, $height);
            } elseif (! in_array($blob->content_type, self::IMAGE_TYPES, true)) {
                abort(422);
            }

            try {
                $image = $this->manager()->read($source)->scaleDown($width, $height);
            } catch (DecoderException $error) {
                // A file the browser called an image that the imaging library cannot read. Upstream's
                // libvips accepted some of these; refusing the upload with 422 beats a 500.
                abort(422, 'That image could not be read.');
            }

            $encoded = match ($format) {
                'png' => $image->toPng(),
                'jpeg' => $image->toJpeg(85),
                default => $image->toWebp(85),
            };

            // Concurrent requests for the same variation each encode it and write identical bytes;
            // object storage has no partial write to guard against, so no lock is needed.
            $storage->disk()->write($path, (string) $encoded);

            return $path;
        } finally {
            foreach (glob($this->temporaryPrefix($blob).'*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    public function analyze(Blob $blob, ?string $localPath = null): array
    {
        $metadata = ['identified' => true, 'analyzed' => true];
        $path = $localPath ?? $this->download($blob);

        try {
            if (str_starts_with($blob->content_type ?? '', 'image/')) {
                $size = @getimagesize($path);
                if ($size) {
                    $metadata['width'] = $size[0];
                    $metadata['height'] = $size[1];
                }
            }

            if (str_starts_with($blob->content_type ?? '', 'video/') || str_starts_with($blob->content_type ?? '', 'audio/')) {
                $metadata += $this->probe($path);
            }
        } finally {
            if ($localPath === null) {
                @unlink($path);
            }
        }

        return $metadata;
    }

    private function probe(string $path): array
    {
        $ffprobe = $this->binary('ffprobe');
        if (! $ffprobe) {
            return [];
        }

        $process = new Process([$ffprobe, '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_streams', '-show_format', '-of', 'json', $path]);
        $process->setTimeout(20);

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            return [];
        }

        $information = json_decode($process->getOutput(), true) ?: [];
        $metadata = ['duration' => (float) ($information['format']['duration'] ?? 0)];
        foreach ($information['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $metadata['width'] = $stream['width'] ?? null;
                $metadata['height'] = $stream['height'] ?? null;
                $metadata['video'] = true;
            } elseif (($stream['codec_type'] ?? '') === 'audio') {
                $metadata['audio'] = true;
            }
        }

        return $metadata;
    }

    private function poster(string $source, int $width, int $height): string
    {
        $ffmpeg = $this->binary('ffmpeg');
        abort_unless($ffmpeg, 422);

        $poster = $source.'.poster.png';
        $process = new Process([$ffmpeg, '-nostdin', '-y', '-protocol_whitelist', 'file,pipe', '-i', $source, '-vf', 'thumbnail,scale='.$width.':'.$height.':force_original_aspect_ratio=decrease', '-frames:v', '1', $poster]);
        $process->setTimeout(20);
        $process->mustRun();

        return $poster;
    }

    private function download(Blob $blob): string
    {
        $storage = app(BlobStorage::class);
        $path = $this->temporaryPrefix($blob).bin2hex(random_bytes(4));
        $handle = fopen($path, 'wb');
        $stream = $storage->disk()->readStream($storage->path($blob));
        abort_unless($stream !== false && $stream !== null, 404);

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($handle);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    private function temporaryPrefix(Blob $blob): string
    {
        return sys_get_temp_dir().'/campfire-'.$blob->key.'-';
    }
}
