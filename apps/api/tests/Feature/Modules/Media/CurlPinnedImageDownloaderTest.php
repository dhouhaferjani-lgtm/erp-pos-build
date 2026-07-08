<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Infrastructure\Net\CurlPinnedImageDownloader;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Exercises the real cURL transfer against a throwaway PHP built-in server so
 * the streamed size cap, redirect denial, peer-IP verification and magic-byte
 * sniff run against genuine socket behaviour rather than a fake.
 */
#[Group('integration')]
final class CurlPinnedImageDownloaderTest extends TestCase
{
    private ?string $docRoot = null;

    /** @var resource|null */
    private $serverProc = null;

    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProc)) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
            $this->serverProc = null;
        }

        if ($this->docRoot !== null && is_dir($this->docRoot)) {
            foreach (glob($this->docRoot.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->docRoot);
            $this->docRoot = null;
        }

        parent::tearDown();
    }

    private function validPngBytes(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->assertNotFalse($bytes);

        return $bytes;
    }

    /**
     * Boots a `php -S` server serving $this->docRoot via a small router that
     * lets us emit redirects and custom headers. Skips the test if no port binds.
     */
    private function bootServer(string $router): void
    {
        $this->docRoot = sys_get_temp_dir().'/enrimg_it_'.bin2hex(random_bytes(6));
        mkdir($this->docRoot, 0700, true);
        $routerPath = $this->docRoot.'/router.php';
        file_put_contents($routerPath, $router);

        // Probe for a free port.
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            $this->markTestSkipped('Cannot bind a local TCP port in this environment.');
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name === false) {
            $this->markTestSkipped('Cannot determine a free local port.');
        }
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $routerPath];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->docRoot);
        if (! is_resource($proc)) {
            $this->markTestSkipped('Could not launch PHP built-in server via proc_open.');
        }
        $this->serverProc = $proc;

        // Wait for the server to accept connections.
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $e1, $e2, 0.1);
            if ($client !== false) {
                fclose($client);
                $ready = true;
                break;
            }
            usleep(100_000);
        }
        if (! $ready) {
            $this->markTestSkipped('PHP built-in server did not come up in time.');
        }
    }

    private function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function test_downloads_valid_png_and_verifies_peer_ip(): void
    {
        $png = $this->validPngBytes();
        $this->bootServer($this->routerServingBytes('image/png', $png));

        $downloader = new CurlPinnedImageDownloader;
        $result = $downloader->download($this->url('/serum.png'), '127.0.0.1', '127.0.0.1', 10_485_760);

        $this->assertFileExists($result->tempPath);
        $this->assertSame('image/png', $result->mime);
        $this->assertSame('serum.png', $result->filename);
        $this->assertSame(strlen($png), $result->byteSize);
        $this->assertStringEqualsFile($result->tempPath, $png);
        @unlink($result->tempPath);
    }

    public function test_aborts_when_body_exceeds_max_bytes(): void
    {
        $big = str_repeat('A', 4096);
        $this->bootServer($this->routerServingBytes('image/png', $big));

        $downloader = new CurlPinnedImageDownloader;
        $this->expectException(RemoteImageFetchException::class);
        // maxBytes far below the 4096-byte body -> WRITEFUNCTION aborts the transfer.
        $downloader->download($this->url('/big.png'), '127.0.0.1', '127.0.0.1', 512);
    }

    public function test_denies_redirect(): void
    {
        $this->bootServer($this->routerRedirecting());

        $downloader = new CurlPinnedImageDownloader;
        $this->expectException(RemoteImageFetchException::class);
        $downloader->download($this->url('/redirect'), '127.0.0.1', '127.0.0.1', 10_485_760);
    }

    public function test_rejects_image_content_type_with_non_image_bytes(): void
    {
        $this->bootServer($this->routerServingBytes('image/png', 'this-is-not-a-png'));

        $downloader = new CurlPinnedImageDownloader;
        $this->expectException(RemoteImageFetchException::class);
        $downloader->download($this->url('/fake.png'), '127.0.0.1', '127.0.0.1', 10_485_760);
    }

    private function routerServingBytes(string $contentType, string $body): string
    {
        $b64 = base64_encode($body);

        return <<<PHP
        <?php
        header('Content-Type: {$contentType}');
        echo base64_decode('{$b64}');
        PHP;
    }

    private function routerRedirecting(): string
    {
        return <<<'PHP'
        <?php
        if (str_contains($_SERVER['REQUEST_URI'], '/redirect')) {
            header('Location: /target.png', true, 302);
            exit;
        }
        header('Content-Type: image/png');
        echo "\xFF\xD8\xFF";
        PHP;
    }
}
