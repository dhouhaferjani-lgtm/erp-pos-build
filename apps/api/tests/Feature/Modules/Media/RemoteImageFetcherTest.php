<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use Tests\TestCase;

final class RemoteImageFetcherTest extends TestCase
{
    /**
     * @param  array<int, string>  $ips
     */
    private function fetcher(array $ips, PinnedImageDownloaderInterface $downloader): RemoteImageFetcher
    {
        $resolver = new class($ips) implements HostResolverInterface
        {
            /** @param array<int, string> $ips */
            public function __construct(private array $ips) {}

            /** @return array<int, string> */
            public function resolve(string $host): array
            {
                return $this->ips;
            }
        };

        return new RemoteImageFetcher($resolver, $downloader);
    }

    public function test_happy_path_pins_first_ip_and_returns_downloader_result(): void
    {
        $expected = new FetchedImage('/tmp/serum.png', 'image/png', 'serum.png', 123);
        $downloader = new RecordingPinnedImageDownloader($expected);

        $result = $this->fetcher(['41.226.11.20', '41.226.11.21'], $downloader)
            ->fetch('https://pharma-shop.tn/img/serum.png');

        $this->assertSame($expected, $result);
        $this->assertTrue($downloader->called);
        $this->assertSame('https://pharma-shop.tn/img/serum.png', $downloader->url);
        $this->assertSame('pharma-shop.tn', $downloader->host);
        $this->assertSame('41.226.11.20', $downloader->pinnedIp);
        $this->assertSame(443, $downloader->port);
        $this->assertSame(10_485_760, $downloader->maxBytes);
    }

    public function test_rejects_non_https_before_download(): void
    {
        $downloader = new RecordingPinnedImageDownloader;

        try {
            $this->fetcher(['41.226.11.20'], $downloader)->fetch('http://pharma-shop.tn/x.png');
            $this->fail('Expected RemoteImageFetchException.');
        } catch (RemoteImageFetchException) {
            $this->assertFalse($downloader->called);
        }
    }

    public function test_rejects_unresolvable_host_before_download(): void
    {
        $downloader = new RecordingPinnedImageDownloader;

        try {
            $this->fetcher([], $downloader)->fetch('https://pharma-shop.tn/x.png');
            $this->fail('Expected RemoteImageFetchException.');
        } catch (RemoteImageFetchException) {
            $this->assertFalse($downloader->called);
        }
    }

    public function test_rejects_host_resolving_to_private_ip_before_download(): void
    {
        $downloader = new RecordingPinnedImageDownloader;

        try {
            $this->fetcher(['10.0.0.5'], $downloader)->fetch('https://evil.example/x.png');
            $this->fail('Expected RemoteImageFetchException.');
        } catch (RemoteImageFetchException) {
            $this->assertFalse($downloader->called);
        }
    }

    public function test_rejects_when_any_resolved_ip_is_private(): void
    {
        $downloader = new RecordingPinnedImageDownloader;

        try {
            $this->fetcher(['41.226.11.20', '10.0.0.5'], $downloader)->fetch('https://pharma-shop.tn/x.png');
            $this->fail('Expected RemoteImageFetchException.');
        } catch (RemoteImageFetchException) {
            $this->assertFalse($downloader->called);
        }
    }
}

/**
 * Records the arguments {@see RemoteImageFetcher} delegates with, and asserts (via
 * `$called`) that guard failures short-circuit BEFORE any download is attempted.
 */
final class RecordingPinnedImageDownloader implements PinnedImageDownloaderInterface
{
    public bool $called = false;

    public ?string $url = null;

    public ?string $host = null;

    public ?string $pinnedIp = null;

    public ?int $port = null;

    public ?int $maxBytes = null;

    public function __construct(private ?FetchedImage $result = null) {}

    public function download(string $url, string $host, string $pinnedIp, int $port, int $maxBytes): FetchedImage
    {
        $this->called = true;
        $this->url = $url;
        $this->host = $host;
        $this->pinnedIp = $pinnedIp;
        $this->port = $port;
        $this->maxBytes = $maxBytes;

        return $this->result ?? new FetchedImage('/tmp/x', 'image/png', 'x.png', 1);
    }
}
