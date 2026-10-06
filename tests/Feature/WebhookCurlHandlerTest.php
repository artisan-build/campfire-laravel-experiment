<?php

namespace Tests\Feature;

use App\Support\BoundedResponseStream;
use App\Support\WebhookDestinations;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

final class WebhookCurlHandlerTest extends TestCase
{
    public function test_real_curl_handler_connects_to_the_pin_and_caps_the_same_unknown_length_response(): void
    {
        [$process, $port, $directory, $marker] = $this->startServer();

        try {
            $policy = new WebhookDestinations;
            $destination = ['host' => 'pinned.invalid', 'port' => $port, 'ip' => '127.0.0.1'];

            $okPath = $directory.'/ok.response';
            $okSink = $this->sink($okPath);
            $response = Http::connectTimeout(2)->timeout(5)
                ->withOptions($policy->requestOptions($destination, $okSink))
                ->post("http://pinned.invalid:{$port}/ok", ['proof' => 'pin']);

            $this->assertSame(200, $response->status());
            $this->assertSame('served-by-127.0.0.1', file_get_contents($okPath));
            $okSink->close();

            $redirectPath = $directory.'/redirect.response';
            $redirectSink = $this->sink($redirectPath);
            $redirect = Http::connectTimeout(2)->timeout(5)
                ->withOptions($policy->requestOptions($destination, $redirectSink))
                ->post("http://pinned.invalid:{$port}/redirect", ['proof' => 'redirect']);
            $this->assertSame(302, $redirect->status());
            $redirectSink->close();

            $largePath = $directory.'/large.response';
            $largeSink = $this->sink($largePath);
            $error = $this->capture(fn () => Http::connectTimeout(2)->timeout(10)
                ->withOptions($policy->requestOptions($destination, $largeSink))
                ->post("http://pinned.invalid:{$port}/oversize", ['proof' => 'cap']));

            $this->assertInstanceOf(Throwable::class, $error);
            $this->assertTrue($largeSink->exceeded());
            $this->assertLessThanOrEqual(WebhookDestinations::MAX_RESPONSE_BYTES, filesize($largePath));
            $largeSink->close();
            $this->assertStringContainsString("/ok\n", (string) file_get_contents($marker));
            $this->assertStringContainsString("/redirect\n", (string) file_get_contents($marker));
            $this->assertStringContainsString("/oversize\n", (string) file_get_contents($marker));
            $this->assertStringNotContainsString("/private\n", (string) file_get_contents($marker));
        } finally {
            proc_terminate($process);
            proc_close($process);
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function sink(string $path): BoundedResponseStream
    {
        $resource = fopen($path, 'w+b');
        $this->assertIsResource($resource);

        return new BoundedResponseStream(Utils::streamFor($resource));
    }

    /** @return array{0: resource, 1: int, 2: string, 3: string} */
    private function startServer(): array
    {
        $directory = sys_get_temp_dir().'/campfire-webhook-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $marker = $directory.'/requests.log';
        $router = $directory.'/router.php';
        $maximum = WebhookDestinations::MAX_RESPONSE_BYTES;
        file_put_contents($router, <<<PHP
<?php
file_put_contents('$marker', parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH)."\\n", FILE_APPEND);
header('Content-Type: text/plain');
header_remove('Content-Length');
if (parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/redirect') {
    header('Location: http://127.0.0.1:'.\$_SERVER['SERVER_PORT'].'/private', true, 302);
    return;
}
if (parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/oversize') {
    for (\$sent = 0; \$sent <= $maximum; \$sent += 8192) {
        echo str_repeat('x', 8192);
        flush();
    }
    return;
}
echo 'served-by-127.0.0.1';
PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $log = $directory.'/server.log';
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], [
            ['pipe', 'r'],
            ['file', $log, 'a'],
            ['file', $log, 'a'],
        ], $pipes, $directory);
        $this->assertIsResource($process);

        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(20_000);
        }
        $this->assertTrue($ready, (string) @file_get_contents($log));

        return [$process, $port, $directory, $marker];
    }

    private function capture(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $error) {
            return $error;
        }

        return null;
    }
}
