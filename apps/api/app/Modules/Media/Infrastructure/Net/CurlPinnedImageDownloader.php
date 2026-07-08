<?php

declare(strict_types=1);

namespace App\Modules\Media\Infrastructure\Net;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard;

/**
 * Default {@see PinnedImageDownloaderInterface} implementation over raw cURL.
 *
 * Security properties enforced here:
 *  - the socket is pinned to a caller-validated IP via CURLOPT_RESOLVE (Host/SNI
 *    preserved) so a DNS rebind between validation and connect cannot redirect it;
 *  - redirects are DENIED (CURLOPT_FOLLOWLOCATION => false) — a redirecting URL is
 *    treated as unfetchable rather than re-resolved;
 *  - the response body is streamed to a temp file and the transfer is aborted the
 *    moment it would exceed $maxBytes (WRITEFUNCTION returns a short count);
 *  - after transfer the connected peer IP (CURLINFO_PRIMARY_IP) MUST equal the
 *    pinned IP, the declared content-type MUST be in the allowlist, and the
 *    leading bytes MUST match the declared image type's magic bytes.
 *
 * SCHEME-TRUST BOUNDARY: this downloader does NOT enforce the URL scheme, host
 * safety, or port. CURLOPT_PROTOCOLS deliberately permits http (so the plain-HTTP
 * built-in-server integration test can exercise the real transfer). Callers MUST
 * pre-validate every URL with {@see ExternalUrlGuard::assertHttpsHostAllowed}
 * — as {@see RemoteImageFetcher} does —
 * before handing it here. The RESOLVE pin is built for the exact ($host, $port)
 * the caller passes; the caller is responsible for passing the URL's real port.
 */
final class CurlPinnedImageDownloader implements PinnedImageDownloaderInterface
{
    private const CONNECT_TIMEOUT = 5;

    private const TRANSFER_TIMEOUT = 15;

    private const MIME_JPEG = 'image/jpeg';

    private const MIME_PNG = 'image/png';

    private const MIME_WEBP = 'image/webp';

    /** @var list<string> */
    private const ALLOWED_MIME = [self::MIME_JPEG, self::MIME_PNG, self::MIME_WEBP];

    private const MAGIC_JPEG = "\xFF\xD8\xFF";

    private const MAGIC_PNG = "\x89PNG\r\n\x1a\n";

    private const MAGIC_RIFF = 'RIFF';

    private const MAGIC_WEBP = 'WEBP';

    public function download(string $url, string $host, string $pinnedIp, int $port, int $maxBytes): FetchedImage
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'enrimg_');
        if ($tempPath === false) {
            throw new RemoteImageFetchException('Could not allocate temp storage for download.');
        }

        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            @unlink($tempPath);
            throw new RemoteImageFetchException('Could not open temp storage for download.');
        }

        $written = 0;
        $exceeded = false;

        $ch = curl_init();
        if ($ch === false) {
            fclose($handle);
            @unlink($tempPath);
            throw new RemoteImageFetchException('Could not initialise cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            // Pin for the ACTUAL connection port — a hardcoded 443 here would be
            // silently ignored by libcurl for any other port, defeating the pin.
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedIp}"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TRANSFER_TIMEOUT,
            // Restrict to HTTP(S) — blocks file://, gopher://, dict:// and other
            // SSRF-friendly schemes. The fetcher already enforces https upstream;
            // permitting plain http here keeps the transfer testable against a
            // local built-in server without weakening the scheme guard that matters.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($_ch, string $chunk) use ($handle, $maxBytes, &$written, &$exceeded): int {
                $length = strlen($chunk);
                if ($written + $length > $maxBytes) {
                    $exceeded = true;

                    // Returning anything other than the chunk length aborts the
                    // transfer with CURLE_WRITE_ERROR — this is the streamed size cap.
                    return -1;
                }

                $written += $length;
                fwrite($handle, $chunk);

                return $length;
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($handle);

        try {
            if ($exceeded) {
                throw new RemoteImageFetchException("Image exceeds maximum size of {$maxBytes} bytes.");
            }

            if ($ok === false || $errno !== 0) {
                throw new RemoteImageFetchException("Transfer failed (cURL {$errno}): ".curl_strerror($errno));
            }

            if ($status < 200 || $status >= 300) {
                throw new RemoteImageFetchException("Fetch failed: HTTP {$status}.");
            }

            // Re-verify the socket actually connected to the pinned IP.
            if ($primaryIp !== $pinnedIp) {
                throw new RemoteImageFetchException("Connected peer IP {$primaryIp} does not match pinned IP {$pinnedIp}.");
            }

            $mime = strtolower(trim(explode(';', (string) $contentType)[0]));
            if (! in_array($mime, self::ALLOWED_MIME, true)) {
                throw new RemoteImageFetchException("Disallowed content-type: {$mime}.");
            }

            $size = @filesize($tempPath);
            if ($size === false || $size === 0) {
                throw new RemoteImageFetchException('Downloaded image is empty.');
            }

            $leading = (string) @file_get_contents($tempPath, false, null, 0, 16);
            $sniffed = $this->sniff($leading);
            if ($sniffed === null || $sniffed !== $mime) {
                throw new RemoteImageFetchException('Magic bytes do not match declared image type.');
            }

            return new FetchedImage(
                tempPath: $tempPath,
                mime: $mime,
                filename: $this->filenameFrom($url),
                byteSize: $size,
            );
        } catch (RemoteImageFetchException $e) {
            @unlink($tempPath);
            throw $e;
        }
    }

    private function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, self::MAGIC_JPEG)) {
            return self::MIME_JPEG;
        }

        if (str_starts_with($bytes, self::MAGIC_PNG)) {
            return self::MIME_PNG;
        }

        if (str_starts_with($bytes, self::MAGIC_RIFF) && substr($bytes, 8, 4) === self::MAGIC_WEBP) {
            return self::MIME_WEBP;
        }

        return null;
    }

    private function filenameFrom(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);

        return $base !== '' ? $base : 'image';
    }
}
