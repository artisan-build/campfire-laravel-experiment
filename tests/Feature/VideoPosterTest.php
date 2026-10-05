<?php

namespace Tests\Feature;

use App\Models\Blob;
use App\Support\BlobStorage;
use App\Support\FfmpegRuntime;
use App\Support\Media;
use App\Support\MessageWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A video attachment's poster, against the real ffmpeg.
 *
 * Grouped so the CI lane that has the vendored runtime can be pointed at it. Every test here skips
 * when no ffmpeg is reachable, because Campfire's documented behaviour without one is "no poster"
 * rather than a failure.
 */
#[Group('ffmpeg')]
class VideoPosterTest extends TestCase
{
    private function ffmpeg(): string
    {
        $ffmpeg = (new Media)->binary('ffmpeg');

        if ($ffmpeg === null) {
            $this->markTestSkipped('No ffmpeg on this runtime; run campfire:provision-ffmpeg first.');
        }

        return $ffmpeg;
    }

    /**
     * A one-second clip, written by the same ffmpeg under test so no fixture has to be committed.
     *
     * `mpeg4` deliberately, not H.264: the pinned LGPL build has `libopenh264` and no `libx264`, a
     * Homebrew build has `libx264` and no `libopenh264`, and `mpeg4` is a native encoder every build
     * has. The pinned build's H.264 *decoding* — which is what users actually upload — is asserted
     * separately against the `-decoders` table.
     */
    private function clip(): string
    {
        $path = sys_get_temp_dir().'/campfire-clip-'.bin2hex(random_bytes(6)).'.mp4';

        $process = new Process([
            $this->ffmpeg(), '-nostdin', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=10:duration=1',
            '-pix_fmt', 'yuv420p', '-c:v', 'mpeg4', $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
            $this->fail('Could not build an mp4 fixture with the ffmpeg under test: '.$process->getErrorOutput());
        }

        return $path;
    }

    private function upload(string $clip): UploadedFile
    {
        return new class($clip, 'clip.mp4', 'video/mp4', null, true) extends UploadedFile
        {
            public function getMimeType(): string
            {
                return 'video/mp4';
            }
        };
    }

    public function test_a_video_attachment_gets_a_poster_variant_written_to_the_disk(): void
    {
        [$user, $room] = $this->fixture();
        $disk = Storage::disk('local');
        $clip = $this->clip();

        try {
            app(MessageWriter::class)->create($room, $user, ['attachment' => $this->upload($clip)]);

            $blob = Blob::sole();
            $this->assertSame('video/mp4', $blob->content_type);

            // The poster is the only thing in the blob's variant directory, and it has to be a real
            // image: a passing exists() on a zero-byte file would prove nothing.
            $variants = $disk->allFiles(app(BlobStorage::class)->variantDirectory($blob));
            $this->assertCount(1, $variants, 'A video attachment must produce exactly one poster variant');
            $this->assertStringEndsWith('.webp', $variants[0]);

            $poster = (string) $disk->get($variants[0]);
            $this->assertGreaterThan(100, strlen($poster));
            $this->assertSame('RIFF', substr($poster, 0, 4), 'The poster must be a WebP file');
            $this->assertSame('WEBP', substr($poster, 8, 4));
        } finally {
            @unlink($clip);
        }
    }

    public function test_ffprobe_reads_the_duration_and_dimensions_a_video_blob_records(): void
    {
        [$user, $room] = $this->fixture();
        $clip = $this->clip();

        try {
            app(MessageWriter::class)->create($room, $user, ['attachment' => $this->upload($clip)]);

            $metadata = json_decode((string) Blob::sole()->metadata, true);

            $this->assertTrue($metadata['video'] ?? false, 'ffprobe must report the video stream');
            $this->assertSame(320, $metadata['width'] ?? null);
            $this->assertSame(240, $metadata['height'] ?? null);
            $this->assertGreaterThan(0.5, $metadata['duration'] ?? 0);
        } finally {
            @unlink($clip);
        }
    }

    public function test_the_vendored_runtime_is_what_answers_when_it_is_provisioned(): void
    {
        $runtime = app(FfmpegRuntime::class);

        if (! $runtime->provisioned()) {
            $this->markTestSkipped('No vendored runtime; this asserts the build step, not PATH.');
        }

        // Exactly the claim campfire:doctor makes on a Cloud instance, whose PATH has no ffmpeg.
        $this->assertSame($runtime->path('ffmpeg'), (new Media)->binary('ffmpeg'));
        $this->assertSame($runtime->path('ffprobe'), (new Media)->binary('ffprobe'));

        $version = new Process([$runtime->path('ffmpeg'), '-hide_banner', '-version']);
        $version->setTimeout(30);
        $version->mustRun();
        $this->assertStringContainsString('ffmpeg version', $version->getOutput());
    }

    public function test_the_vendored_build_decodes_the_codecs_browsers_actually_upload(): void
    {
        $runtime = app(FfmpegRuntime::class);

        if (! $runtime->provisioned()) {
            $this->markTestSkipped('No vendored runtime; this asserts the pinned build, not PATH.');
        }

        // Read the table. `ffmpeg -h decoder=<name>` exits 0 for codecs that do not exist, so it can
        // never be used to probe capability — the trap Slate paid for with a Cloud deploy.
        $decoders = new Process([$runtime->path('ffmpeg'), '-hide_banner', '-decoders']);
        $decoders->setTimeout(30);
        $decoders->mustRun();
        $table = $decoders->getOutput();

        // video/mp4, video/webm and video/quicktime in App\Support\Media::VIDEO_TYPES cover these.
        foreach (['h264', 'hevc', 'vp8', 'vp9', 'av1', 'mpeg4'] as $codec) {
            $this->assertMatchesRegularExpression(
                '/^\s*V[.FSXBD]+\s+'.preg_quote($codec, '/').'\s/m',
                $table,
                "The pinned build must decode {$codec}",
            );
        }
    }
}
