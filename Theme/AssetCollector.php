<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\EmailToThemeBundle\Theme\Model\CollectedAsset;
use MauticPlugin\EmailToThemeBundle\Theme\Model\CollectionResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds the images an email uses and reads their bytes so they can be copied into a theme.
 *
 * Images on this Mautic are read from disk (only from media/ and themes/). Other images are
 * downloaded over http(s) from public addresses only, with a size limit. Anything that cannot
 * be copied keeps its original address and is reported back to the user.
 */
class AssetCollector
{
    /**
     * Attribute values that point at images, and CSS url(...) values.
     * Each match exposes the address in exactly one of the groups v1..v5.
     */
    private const REFERENCE_PATTERN = '/\b(?:src|background-url|background)\s*=\s*(?:"(?<v1>[^"]*)"|\'(?<v2>[^\']*)\')'
        .'|url\(\s*(?:"(?<v3>[^"]*)"|\'(?<v4>[^\']*)\'|(?<v5>[^)\'"\s]+))\s*\)/i';

    private const IMAGE_TYPES = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    private const LOCAL_ROOTS = ['media', 'themes'];

    private ?HttpClientInterface $client;

    public function __construct(
        private readonly CoreParametersHelper $parameters,
        private readonly string $docroot,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * Every image reference in the document, in order, with its byte offset and the text as written.
     *
     * @return list<array{offset: int, raw: string}>
     */
    public static function findReferences(string $mjml): array
    {
        if (false === preg_match_all(self::REFERENCE_PATTERN, $mjml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return [];
        }

        $references = [];
        foreach ($matches as $match) {
            foreach (['v1', 'v2', 'v3', 'v4', 'v5'] as $group) {
                if (isset($match[$group][0]) && '' !== trim($match[$group][0])) {
                    $references[] = ['offset' => $match[$group][1], 'raw' => $match[$group][0]];
                    break;
                }
            }
        }

        return $references;
    }

    public function collect(string $mjml): CollectionResult
    {
        $assets  = [];
        $skipped = [];
        $seen    = [];

        foreach (self::findReferences($mjml) as $reference) {
            $raw = $reference['raw'];
            if (isset($seen[$raw])) {
                continue;
            }
            $seen[$raw] = true;

            $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5));

            if (str_starts_with(strtolower($url), 'data:')) {
                continue; // Inline images are already part of the template.
            }
            if (str_contains($url, '{') || str_contains($url, '}')) {
                $skipped[$raw] = 'mautic.email_to_theme.skip.token';
                continue;
            }

            try {
                [$contents, $remote] = $this->read($url);
            } catch (AssetException $e) {
                $skipped[$raw] = $e->getMessage();
                continue;
            }

            $extension = $this->detectExtension($contents, $remote);
            if (null === $extension) {
                $skipped[$raw] = 'mautic.email_to_theme.skip.not_image';
                continue;
            }

            $assets[] = new CollectedAsset($raw, self::fileName($url, $extension), $contents);
        }

        return new CollectionResult($assets, $skipped);
    }

    /**
     * @return array{0: string, 1: bool} the bytes, and whether they were downloaded
     *
     * @throws AssetException
     */
    private function read(string $url): array
    {
        $parts = parse_url($url);
        if (false === $parts) {
            throw new AssetException('mautic.email_to_theme.skip.invalid');
        }

        if ($this->isLocal($parts)) {
            return [$this->readLocal((string) ($parts['path'] ?? '')), false];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new AssetException('mautic.email_to_theme.skip.invalid');
        }
        if (!$this->parameters->get('email_to_theme_fetch_remote', true)) {
            throw new AssetException('mautic.email_to_theme.skip.remote_disabled');
        }

        return [$this->download($url), true];
    }

    /**
     * @param array<string, int|string> $parts
     */
    private function isLocal(array $parts): bool
    {
        if (empty($parts['host'])) {
            // A path on this site ("/media/images/x.png"); scheme-relative addresses have a host.
            return isset($parts['path']) && str_starts_with((string) $parts['path'], '/');
        }

        $site = parse_url((string) $this->parameters->get('site_url', ''));

        return is_array($site) && !empty($site['host'])
            && 0 === strcasecmp((string) $site['host'], (string) $parts['host'])
            && (int) ($site['port'] ?? 0) === (int) ($parts['port'] ?? 0);
    }

    /**
     * @throws AssetException
     */
    private function readLocal(string $path): string
    {
        $site     = parse_url((string) $this->parameters->get('site_url', ''));
        $basePath = rtrim(is_array($site) ? (string) ($site['path'] ?? '') : '', '/');
        $path     = rawurldecode($path);
        if ('' !== $basePath && str_starts_with($path, $basePath.'/')) {
            $path = substr($path, strlen($basePath));
        }

        $docroot = realpath($this->docroot);
        $file    = false === $docroot ? false : realpath($docroot.'/'.ltrim($path, '/'));
        if (false === $docroot || false === $file || !is_file($file)) {
            throw new AssetException('mautic.email_to_theme.skip.not_found');
        }

        $allowed = false;
        foreach (self::LOCAL_ROOTS as $root) {
            if (str_starts_with($file, $docroot.'/'.$root.'/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new AssetException('mautic.email_to_theme.skip.outside');
        }

        if (filesize($file) > $this->maxBytes()) {
            throw new AssetException('mautic.email_to_theme.skip.too_large');
        }

        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new AssetException('mautic.email_to_theme.skip.not_found');
        }

        return $contents;
    }

    /**
     * @throws AssetException
     */
    private function download(string $url): string
    {
        $max      = $this->maxBytes();
        $client   = $this->client ??= new NoPrivateNetworkHttpClient(HttpClient::create());
        $contents = '';

        try {
            $response = $client->request('GET', $url, [
                'timeout'       => 10,
                'max_duration'  => 30,
                'max_redirects' => 3,
                'headers'       => ['User-Agent' => 'Mautic Email to Theme', 'Accept' => 'image/*'],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new AssetException('mautic.email_to_theme.skip.download_failed');
            }

            $length = $response->getHeaders(false)['content-length'][0] ?? null;
            if (null !== $length && (int) $length > $max) {
                $response->cancel();
                throw new AssetException('mautic.email_to_theme.skip.too_large');
            }

            foreach ($client->stream($response) as $chunk) {
                $contents .= $chunk->getContent();
                if (strlen($contents) > $max) {
                    $response->cancel();
                    throw new AssetException('mautic.email_to_theme.skip.too_large');
                }
            }
        } catch (AssetException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new AssetException('mautic.email_to_theme.skip.download_failed');
        }

        return $contents;
    }

    /**
     * The file type comes from the bytes, never from the address or the server's headers.
     * SVG can carry scripts, so it is only accepted from this Mautic's own files.
     */
    private function detectExtension(string $contents, bool $remote): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if ('image/svg' === $mime) {
            $mime = 'image/svg+xml';
        }
        if (!is_string($mime) || !isset(self::IMAGE_TYPES[$mime])) {
            return null;
        }
        if ($remote && 'image/svg+xml' === $mime) {
            return null;
        }

        return self::IMAGE_TYPES[$mime];
    }

    /**
     * A safe file name for the assets folder, based on the address's last path segment.
     */
    public static function fileName(string $url, string $extension): string
    {
        $base = pathinfo(rawurldecode((string) parse_url($url, PHP_URL_PATH)), PATHINFO_FILENAME);
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $base));
        $base = trim(substr($base, 0, 60), '-_');

        return ('' === $base ? 'image' : $base).'.'.$extension;
    }

    private function maxBytes(): int
    {
        return (int) $this->parameters->get('email_to_theme_max_image_bytes', 5 * 1024 * 1024);
    }
}
