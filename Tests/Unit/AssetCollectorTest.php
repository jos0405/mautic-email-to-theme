<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Tests\Unit;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\EmailToThemeBundle\Theme\AssetCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AssetCollectorTest extends TestCase
{
    private string $docroot;

    private string $png;

    protected function setUp(): void
    {
        $this->docroot = sys_get_temp_dir().'/e2t-docroot-'.bin2hex(random_bytes(4));
        $image         = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $this->png = (string) ob_get_clean();

        $fs = new Filesystem();
        $fs->dumpFile($this->docroot.'/media/images/Logo Big.png', $this->png);
        $fs->dumpFile($this->docroot.'/themes/brienz/assets/a.png', $this->png);
        $fs->dumpFile($this->docroot.'/app/secret.png', $this->png);
        $fs->dumpFile($this->docroot.'/media/files/notes.txt', 'plain text');
        $fs->dumpFile($this->docroot.'/media/images/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->docroot);
    }

    public function testCopiesLocalImagesFromMediaAndThemes(): void
    {
        $mjml = '<mj-image src="https://mautic.test/sub/media/images/Logo%20Big.png"/>'
            .'<mj-image src="/sub/themes/brienz/assets/a.png"/><mj-image src="https://mautic.test/sub/media/images/icon.svg"/>';

        $result = $this->collector()->collect($mjml);

        self::assertSame([], $result->skipped);
        self::assertSame(['logo-big.png', 'a.png', 'icon.svg'], array_map(fn ($a) => $a->suggestedName, $result->assets));
        self::assertSame($this->png, $result->assets[0]->contents);
    }

    public function testRefusesFilesOutsideMediaAndThemesAndNonImages(): void
    {
        $mjml = '<mj-image src="https://mautic.test/sub/app/secret.png"/>'
            .'<mj-image src="https://mautic.test/sub/media/../app/secret.png"/>'
            .'<mj-image src="https://mautic.test/sub/media/files/notes.txt"/>'
            .'<mj-image src="https://mautic.test/sub/media/images/missing.png"/>';

        $result = $this->collector()->collect($mjml);

        self::assertSame([], $result->assets);
        self::assertSame([
            'https://mautic.test/sub/app/secret.png'           => 'mautic.email_to_theme.skip.outside',
            'https://mautic.test/sub/media/../app/secret.png'  => 'mautic.email_to_theme.skip.outside',
            'https://mautic.test/sub/media/files/notes.txt'    => 'mautic.email_to_theme.skip.not_image',
            'https://mautic.test/sub/media/images/missing.png' => 'mautic.email_to_theme.skip.not_found',
        ], $result->skipped);
    }

    public function testDownloadsRemoteImagesButNotSvgOrOversizedFiles(): void
    {
        $responses = [
            'https://cdn.test/photo.jpg?w=600' => new MockResponse($this->png),
            'https://cdn.test/vector.svg'      => new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'https://cdn.test/huge.png'        => new MockResponse(str_repeat('x', 2000)),
            'https://cdn.test/gone.png'        => new MockResponse('', ['http_code' => 404]),
        ];
        $client = new MockHttpClient(fn (string $method, string $url) => $responses[$url]);

        $mjml = '<mj-image src="https://cdn.test/photo.jpg?w=600"/><mj-image src="https://cdn.test/vector.svg"/>'
            .'<mj-image src="https://cdn.test/huge.png"/><mj-image src="https://cdn.test/gone.png"/>'
            .'<mj-image src="https://cdn.test/{contactfield=avatar}"/><mj-image src="data:image/png;base64,AAAA"/>'
            .'<mj-image src="ftp://cdn.test/x.png"/>';

        $result = $this->collector($client, 1000)->collect($mjml);

        self::assertCount(1, $result->assets);
        self::assertSame('photo.png', $result->assets[0]->suggestedName, 'the extension follows the bytes');
        self::assertSame([
            'https://cdn.test/vector.svg'            => 'mautic.email_to_theme.skip.not_image',
            'https://cdn.test/huge.png'              => 'mautic.email_to_theme.skip.too_large',
            'https://cdn.test/gone.png'              => 'mautic.email_to_theme.skip.download_failed',
            'https://cdn.test/{contactfield=avatar}' => 'mautic.email_to_theme.skip.token',
            'ftp://cdn.test/x.png'                   => 'mautic.email_to_theme.skip.invalid',
        ], $result->skipped);
    }

    public function testRemoteDownloadsCanBeSwitchedOff(): void
    {
        $result = $this->collector(null, 1000, false)->collect('<mj-image src="https://cdn.test/a.png"/>');

        self::assertSame(['https://cdn.test/a.png' => 'mautic.email_to_theme.skip.remote_disabled'], $result->skipped);
    }

    public function testTheDefaultClientRefusesPrivateNetworkAddresses(): void
    {
        $result = $this->collector(null, 1000, true, false)->collect('<mj-image src="http://127.0.0.1/a.png"/>');

        self::assertSame(['http://127.0.0.1/a.png' => 'mautic.email_to_theme.skip.download_failed'], $result->skipped);
    }

    private function collector(?MockHttpClient $client = null, int $maxBytes = 5_000_000, bool $remote = true, bool $mockClient = true): AssetCollector
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturnCallback(fn (string $name, $default = null) => match ($name) {
            'site_url'                       => 'https://mautic.test/sub',
            'email_to_theme_max_image_bytes' => $maxBytes,
            'email_to_theme_fetch_remote'    => $remote,
            default                          => $default,
        });

        return new AssetCollector($parameters, $this->docroot, $mockClient ? ($client ?? new MockHttpClient()) : null);
    }
}
