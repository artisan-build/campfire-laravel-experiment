<?php

namespace App\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The ffmpeg/ffprobe pair Campfire uses for video posters and video metadata.
 *
 * Laravel Cloud's PHP runtime has neither binary, so the build step installs a pinned static build
 * into the application root with campfire:provision-ffmpeg. This class is both halves of that: where
 * the binaries are (which App\Support\Media asks on every call) and how they get there.
 *
 * Three traps are paid for here rather than rediscovered. The archive expands to roughly 292 MB and
 * almost all of it is ffplay, documentation, manual pages and presets Campfire never reads, so the
 * extract is trimmed before it is installed. The install goes under base_path() because only the
 * application root is the deploy artifact — a sibling directory looks persistent on a live instance
 * and is gone on the next deploy. And the download is checked against the release's own published
 * SHA-256 before anything is made executable.
 */
final class FfmpegRuntime
{
    private readonly string $osFamily;

    private readonly string $machine;

    /**
     * Both arguments default to this machine, so the container resolves it correctly with no binding
     * and a test can still ask what a Linux arm64 instance would see.
     */
    public function __construct(?string $osFamily = null, ?string $machine = null)
    {
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->machine = $machine ?? php_uname('m');
    }

    public static function make(): self
    {
        return new self;
    }

    /**
     * Where provisioning installs the binaries, and the first place Media looks for them.
     */
    public function directory(): string
    {
        return rtrim((string) config('campfire.ffmpeg.directory'), '/');
    }

    public function path(string $binary): string
    {
        return $this->directory().'/'.$binary;
    }

    /**
     * Whether a pinned build exists for this platform at all.
     *
     * This gates installed(), and it has to: a bind-mounted container install — which is how the
     * README says to verify the build step — leaves Linux ELF binaries in the directory, and macOS
     * reports those as is_executable() because the permission bits say so. Without this check the
     * host's next test run resolves them and dies with exit 126.
     */
    public function vendorable(): bool
    {
        return isset(((array) config('campfire.ffmpeg.assets'))[$this->platform()]);
    }

    public function installed(string $binary): bool
    {
        if (! $this->vendorable()) {
            return false;
        }

        $path = $this->path($binary);

        return is_file($path) && is_executable($path);
    }

    public function provisioned(): bool
    {
        return $this->installed('ffmpeg') && $this->installed('ffprobe');
    }

    /**
     * The platform key into config('campfire.ffmpeg.assets').
     */
    public function platform(): string
    {
        return strtolower($this->osFamily).'-'.match ($this->machine) {
            'aarch64', 'arm64' => 'arm64',
            'x86_64', 'amd64', 'x64' => 'x86_64',
            default => $this->machine,
        };
    }

    public function asset(): string
    {
        $assets = (array) config('campfire.ffmpeg.assets');
        $platform = $this->platform();

        if (! $this->vendorable()) {
            throw new RuntimeException(
                "No pinned ffmpeg build for [{$platform}]. Campfire only vendors the Linux builds its "
                .'deploy artifact runs on; install ffmpeg yourself and Media will find it on PATH.'
            );
        }

        return (string) $assets[$platform];
    }

    /**
     * Download, verify, extract, trim and install. Returns the extracted and installed byte sizes.
     *
     * @return array{asset: string, extracted: int, installed: int}
     */
    public function install(): array
    {
        $this->assertToolsAvailable();

        $asset = $this->asset();
        $tag = (string) config('campfire.ffmpeg.release_tag');
        $base = "https://github.com/BtbN/FFmpeg-Builds/releases/download/{$tag}";

        // Staged beside the install so the whole operation lives inside the application root.
        $staging = dirname($this->directory()).'-staging';
        $archive = $staging.'/'.$asset;
        $extract = $staging.'/extract';

        $this->removeDirectory($staging);
        $this->makeDirectory($extract);

        try {
            $expected = $this->publishedChecksum("{$base}/checksums.sha256", $asset);

            Http::retry([250, 500, 1000])
                ->connectTimeout(15)
                ->timeout(600)
                ->sink($archive)
                ->get("{$base}/{$asset}")
                ->throw();

            $actual = hash_file('sha256', $archive);

            if (! is_string($actual) || ! hash_equals($expected, $actual)) {
                throw new RuntimeException("Checksum mismatch for {$asset}; refusing to install it.");
            }

            $this->extract($archive, $extract);
            $extracted = $this->directorySize($extract);
            $this->trim($extract);

            $installed = $this->directorySize($extract.'/bin');
            $this->replace($extract.'/bin', $this->directory());

            return ['asset' => $asset, 'extracted' => $extracted, 'installed' => $installed];
        } finally {
            $this->removeDirectory($staging);
        }
    }

    /**
     * tar and xz do the unpacking; neither is guaranteed to be in a PHP build image.
     */
    private function assertToolsAvailable(): void
    {
        foreach ([['tar', '--version'], ['xz', '--version']] as $command) {
            if (Process::timeout(10)->run($command)->failed()) {
                throw new RuntimeException(
                    "Missing required `{$command[0]}` executable. Install it in the build image "
                    .'before running campfire:provision-ffmpeg.'
                );
            }
        }
    }

    private function publishedChecksum(string $url, string $asset): string
    {
        $body = Http::retry([250, 500, 1000])->connectTimeout(15)->timeout(120)->get($url)->throw()->body();

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_ends_with($line, ' '.$asset) && ! str_ends_with($line, '*'.$asset)) {
                continue;
            }

            $hash = strtolower(strtok($line, " \t") ?: '');

            if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                return $hash;
            }
        }

        throw new RuntimeException("The release publishes no SHA-256 for {$asset}.");
    }

    private function extract(string $archive, string $into): void
    {
        $result = Process::timeout(600)->run(['tar', '-xf', $archive, '-C', $into, '--strip-components=1']);

        if ($result->failed()) {
            throw new RuntimeException('Unable to extract ffmpeg: '.trim($result->errorOutput()));
        }
    }

    /**
     * Keep ffmpeg and ffprobe, executable; drop everything else. ffplay alone is another 90 MB.
     */
    private function trim(string $extract): void
    {
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            $path = $extract.'/bin/'.$binary;

            if (! is_file($path)) {
                throw new RuntimeException("The extracted archive has no bin/{$binary}.");
            }

            if (! chmod($path, 0o755)) {
                throw new RuntimeException("Unable to make bin/{$binary} executable.");
            }
        }

        foreach (glob($extract.'/bin/*') ?: [] as $path) {
            if (! in_array(basename($path), ['ffmpeg', 'ffprobe'], true)) {
                @unlink($path);
            }
        }

        foreach (['doc', 'man', 'presets', 'include', 'lib'] as $directory) {
            $this->removeDirectory($extract.'/'.$directory);
        }
    }

    private function replace(string $from, string $to): void
    {
        $this->makeDirectory(dirname($to));
        $this->removeDirectory($to);

        if (! rename($from, $to)) {
            throw new RuntimeException("Unable to install ffmpeg at {$to}.");
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0o755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create directory {$path}.");
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    private function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            if ($entry->isFile() && ! $entry->isLink()) {
                $size += $entry->getSize();
            }
        }

        return $size;
    }
}
