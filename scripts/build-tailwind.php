<?php

declare(strict_types=1);

require_once __DIR__.'/TailwindPlatform.php';

const TAILWIND_VERSION = 'v4.1.14';
const TAILWIND_ASSETS = [
    'linux-arm64' => ['tailwindcss-linux-arm64', '314941f5f6e143e74e740c587ad1fbaaede5462572dd330bbe0937e611e966db'],
    'linux-arm64-musl' => ['tailwindcss-linux-arm64-musl', '0924f5b717d76ecc08e3ea1dbc7bb071f9b4a71dddff6243e82d8e96d3bf6c17'],
    'linux-x64' => ['tailwindcss-linux-x64', 'bc34c301b080b6e6b98ed24118419833f966f6f347e556945d6557d36a44a56e'],
    'linux-x64-musl' => ['tailwindcss-linux-x64-musl', '245b149dc7699079c255bd0aa4bdc6917d9b80364b0505e84430e156afa35a96'],
    'macos-arm64' => ['tailwindcss-macos-arm64', 'e722b752f51def86d42e886b4c1171f2d09a4be1a7487a0a51e4aff8e7603ce3'],
    'macos-x64' => ['tailwindcss-macos-x64', '67b25b6103fa7677637e5a5de3327fec3335da316d90d3fdb1a4cd72bda41c0a'],
    'windows-x64' => ['tailwindcss-windows-x64.exe', 'ae892cdb0817fbe6b692fc67bb1339a728f21116020e620bc4b94d87d6ba1fee'],
];

$root = dirname(__DIR__);
$platform = TailwindPlatform::current();

if (! isset(TAILWIND_ASSETS[$platform])) {
    throw new RuntimeException("No pinned Tailwind build for [{$platform}].");
}

[$asset, $checksum] = TAILWIND_ASSETS[$platform];
$runtime = $root.'/runtime/tailwind';
$binary = $runtime.'/'.$asset;

if (! is_dir($runtime) && ! mkdir($runtime, 0755, true) && ! is_dir($runtime)) {
    throw new RuntimeException("Unable to create [{$runtime}].");
}

if (! is_file($binary) || hash_file('sha256', $binary) !== $checksum) {
    $download = $binary.'.download';
    $url = 'https://github.com/tailwindlabs/tailwindcss/releases/download/'.TAILWIND_VERSION.'/'.$asset;
    $context = stream_context_create(['http' => [
        'follow_location' => true,
        'header' => "User-Agent: campfire-tailwind-build\r\n",
        'timeout' => 120,
    ]]);
    $source = fopen($url, 'rb', false, $context);
    $destination = fopen($download, 'wb');

    if ($source === false || $destination === false) {
        @unlink($download);
        throw new RuntimeException("Unable to download [{$url}].");
    }

    try {
        if (stream_copy_to_stream($source, $destination) === false) {
            throw new RuntimeException("Unable to download [{$url}].");
        }
    } finally {
        fclose($source);
        fclose($destination);
    }

    $actual = hash_file('sha256', $download);
    if (! hash_equals($checksum, $actual)) {
        @unlink($download);
        throw new RuntimeException("Tailwind checksum mismatch for [{$asset}].");
    }

    if (! rename($download, $binary)) {
        @unlink($download);
        throw new RuntimeException("Unable to install [{$binary}].");
    }
}

if (PHP_OS_FAMILY !== 'Windows' && ! chmod($binary, 0755)) {
    throw new RuntimeException("Unable to make [{$binary}] executable.");
}

$temporaryOutput = $runtime.'/app.css';
$process = proc_open(
    [$binary, '-i', $root.'/resources/css/app.css', '-o', $temporaryOutput, '--minify'],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $root,
);

if (! is_resource($process) || proc_close($process) !== 0 || ! is_file($temporaryOutput)) {
    throw new RuntimeException('Tailwind failed to build the application stylesheet.');
}

$fingerprint = substr(hash_file('sha256', $temporaryOutput), 0, 8);
$filename = "app-{$fingerprint}.css";
$output = $root.'/public/assets/'.$filename;

if (! is_file($output) || hash_file('sha256', $output) !== hash_file('sha256', $temporaryOutput)) {
    if (! copy($temporaryOutput, $output)) {
        throw new RuntimeException("Unable to write [{$output}].");
    }
}

$manifestPath = $root.'/public/assets/.manifest.json';
$manifestJson = file_get_contents($manifestPath);
$manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
$previous = $manifest['app.css'] ?? null;

if ($previous !== $filename) {
    if ($previous === null) {
        $manifestJson = preg_replace('/}\s*$/', ', "app.css": "'.$filename.'"}'.PHP_EOL, $manifestJson, 1, $count);
    } else {
        $manifestJson = preg_replace('/"app\.css"\s*:\s*"[^"]+"/', '"app.css": "'.$filename.'"', $manifestJson, 1, $count);
    }

    if ($manifestJson === null || $count !== 1 || file_put_contents($manifestPath, $manifestJson) === false) {
        throw new RuntimeException('Unable to update the asset manifest.');
    }

    if (is_string($previous) && str_starts_with($previous, 'app-') && $previous !== $filename) {
        @unlink($root.'/public/assets/'.$previous);
    }
}

echo "Built public/assets/{$filename}\n";
