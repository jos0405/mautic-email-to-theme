<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Tests\Unit;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\EmailToThemeBundle\Theme\Model\CollectedAsset;
use MauticPlugin\EmailToThemeBundle\Theme\Model\CollectionResult;
use MauticPlugin\EmailToThemeBundle\Theme\Model\ConversionRequest;
use MauticPlugin\EmailToThemeBundle\Theme\TemplateBuilder;
use MauticPlugin\EmailToThemeBundle\Theme\ThemeWriteException;
use MauticPlugin\EmailToThemeBundle\Theme\ThemeWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ThemeWriterTest extends TestCase
{
    private const MJML = '<mjml><mj-body><mj-image src="https://x.test/logo.png"/></mj-body></mjml>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/e2t-writer-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$this->dir.'/themes', $this->dir.'/backups']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testCreatesACompleteTheme(): void
    {
        $result = $this->writer()->write($this->request(ConversionRequest::MODE_NEW, 'Spring Sale 2026!'), self::MJML, $this->assets('logo-bytes'));

        $theme = $this->dir.'/themes/spring-sale-2026';
        self::assertTrue($result->created);
        self::assertSame('spring-sale-2026', $result->key);
        self::assertSame(1, $result->copiedImages);
        self::assertNull($result->backupPath);
        foreach (['config.json', 'composer.json', 'thumbnail.png', 'html/email.html.twig', 'html/base.html.twig', 'html/message.html.twig'] as $file) {
            self::assertFileExists($theme.'/'.$file);
        }
        self::assertSame('logo-bytes', file_get_contents($theme.'/assets/logo.png'));
        self::assertStringContainsString("getAssetUrl('themes/'~template~'/assets/logo.png'", (string) file_get_contents($theme.'/html/email.html.twig'));

        $config = json_decode((string) file_get_contents($theme.'/config.json'), true);
        self::assertSame('Spring Sale 2026!', $config['name']);
        self::assertSame(['grapesjsbuilder'], $config['builder']);
        self::assertSame(['email'], $config['features']);
        self::assertSame(7, $config['emailToTheme']['sourceEmailId']);
        self::assertSame([], glob($this->dir.'/themes/.email-to-theme-*'), 'no staging folder is left behind');
    }

    public function testRefusesToCreateOverAnExistingFolder(): void
    {
        (new Filesystem())->mkdir($this->dir.'/themes/news');

        try {
            $this->writer()->write($this->request(ConversionRequest::MODE_NEW, 'News'), self::MJML, $this->assets('x'));
            self::fail('expected an exception');
        } catch (ThemeWriteException $e) {
            self::assertSame('mautic.email_to_theme.error.exists', $e->getMessage());
        }
        self::assertSame([], glob($this->dir.'/themes/news/*'));
    }

    public function testUpdateBacksUpFirstAndKeepsOtherFiles(): void
    {
        $fs    = new Filesystem();
        $theme = $this->dir.'/themes/news';
        $fs->dumpFile($theme.'/config.json', json_encode(['name' => 'Newsletter', 'author' => 'Agency', 'builder' => ['grapesjsbuilder'], 'features' => ['email', 'page']]));
        $fs->dumpFile($theme.'/html/email.html.twig', 'old');
        $fs->dumpFile($theme.'/html/page.html.twig', 'page template');
        $fs->dumpFile($theme.'/assets/logo.png', 'other logo');
        $fs->dumpFile($theme.'/thumbnail.png', 'old thumb');

        $result = $this->writer()->write($this->request(ConversionRequest::MODE_UPDATE, '', 'news'), self::MJML, $this->assets('new logo'));

        self::assertFalse($result->created);
        self::assertSame('Newsletter', $result->name);
        self::assertSame('page template', file_get_contents($theme.'/html/page.html.twig'));
        self::assertSame('other logo', file_get_contents($theme.'/assets/logo.png'), 'a different file with the same name is not overwritten');
        self::assertSame('new logo', file_get_contents($theme.'/assets/logo-2.png'));
        self::assertStringContainsString('/assets/logo-2.png', (string) file_get_contents($theme.'/html/email.html.twig'));
        self::assertSame('old thumb', file_get_contents($theme.'/thumbnail.png'), 'no upload keeps the thumbnail');
        self::assertFileExists($theme.'/html/base.html.twig', 'missing skeleton files are added');

        $config = json_decode((string) file_get_contents($theme.'/config.json'), true);
        self::assertSame('Agency', $config['author']);
        self::assertSame(['email', 'page'], $config['features']);

        self::assertNotNull($result->backupPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($result->backupPath));
        self::assertSame('old', $zip->getFromName('news/html/email.html.twig'));
        self::assertSame('other logo', $zip->getFromName('news/assets/logo.png'));
        $zip->close();
    }

    public function testUpdateReusesIdenticalImagesAndReplacesTheThumbnail(): void
    {
        $fs    = new Filesystem();
        $theme = $this->dir.'/themes/news';
        $fs->dumpFile($theme.'/config.json', json_encode(['name' => 'News', 'builder' => ['grapesjsbuilder'], 'features' => ['email']]));
        $fs->dumpFile($theme.'/assets/logo.png', 'same');
        $fs->dumpFile($theme.'/thumbnail_email.png', 'old');

        $upload = $this->dir.'/upload.jpg';
        imagejpeg(imagecreatetruecolor(1200, 800), $upload);

        $this->writer()->write($this->request(ConversionRequest::MODE_UPDATE, '', 'news', $upload), self::MJML, $this->assets('same'));

        self::assertFileDoesNotExist($theme.'/assets/logo-2.png');
        foreach (['thumbnail.png', 'thumbnail_email.png'] as $file) {
            $size = getimagesize($theme.'/'.$file);
            self::assertSame([600, 400, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
        }
    }

    public function testBadThumbnailStopsBeforeAnythingIsWritten(): void
    {
        $upload = $this->dir.'/upload.png';
        file_put_contents($upload, 'not an image');

        $this->expectExceptionMessage('mautic.email_to_theme.error.thumbnail');
        try {
            $this->writer()->write($this->request(ConversionRequest::MODE_NEW, 'News', null, $upload), self::MJML, $this->assets('x'));
        } finally {
            self::assertSame([], glob($this->dir.'/themes/*'));
        }
    }

    public function testUpdateRefusesPathsOutsideTheThemesFolder(): void
    {
        $this->expectExceptionMessage('mautic.email_to_theme.error.not_found');
        $this->writer()->write($this->request(ConversionRequest::MODE_UPDATE, '', '../backups'), self::MJML, $this->assets('x'));
    }

    private function writer(): ThemeWriter
    {
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getSystemPath')->with('themes', true)->willReturn($this->dir.'/themes');

        return new ThemeWriter($paths, new TemplateBuilder(), $this->dir.'/backups');
    }

    private function request(string $mode, string $name, ?string $target = null, ?string $thumbnail = null): ConversionRequest
    {
        return new ConversionRequest($mode, $name, $target, $thumbnail, 7, 'Source email');
    }

    private function assets(string $contents): CollectionResult
    {
        return new CollectionResult([new CollectedAsset('https://x.test/logo.png', 'logo.png', $contents)], []);
    }
}
