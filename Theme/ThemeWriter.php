<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\EmailToThemeBundle\Theme\Model\CollectedAsset;
use MauticPlugin\EmailToThemeBundle\Theme\Model\CollectionResult;
use MauticPlugin\EmailToThemeBundle\Theme\Model\ConversionRequest;
use MauticPlugin\EmailToThemeBundle\Theme\Model\ConversionResult;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes a theme folder in themes/ from an email's MJML.
 *
 * A new theme is built in a hidden folder and moved into place at the end, so a failure never
 * leaves half a theme in the theme list. Before an existing theme is updated, its whole folder
 * is zipped to the backup folder; without a backup nothing is changed.
 */
class ThemeWriter
{
    public const THUMBNAIL_MAX_WIDTH = 600;

    private const SKELETON_DIR = __DIR__.'/../Resources/theme';

    private Filesystem $filesystem;

    public function __construct(
        private readonly PathsHelper $pathsHelper,
        private readonly TemplateBuilder $templateBuilder,
        private readonly string $backupDir,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @throws ThemeWriteException
     */
    public function write(ConversionRequest $request, string $mjml, CollectionResult $collection): ConversionResult
    {
        $root = rtrim((string) $this->pathsHelper->getSystemPath('themes', true), '/');

        return ConversionRequest::MODE_UPDATE === $request->mode
            ? $this->update($root, $request, $mjml, $collection)
            : $this->create($root, $request, $mjml, $collection);
    }

    private function create(string $root, ConversionRequest $request, string $mjml, CollectionResult $collection): ConversionResult
    {
        $key    = ThemeCatalog::keyFor($request->name);
        $target = $root.'/'.$key;
        if ($this->filesystem->exists($target)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.exists', ['%key%' => $key]);
        }

        // Thumbnail first: a bad upload should fail before anything is written.
        $thumbnail = null !== $request->thumbnailPath
            ? $this->thumbnailPng($request->thumbnailPath)
            : (string) file_get_contents(self::SKELETON_DIR.'/thumbnail.png');

        $staging = $root.'/.email-to-theme-'.bin2hex(random_bytes(6));
        try {
            $this->filesystem->mkdir([$staging.'/html', $staging.'/assets']);

            $map = $this->writeAssets($staging.'/assets', $collection->assets);
            $this->filesystem->dumpFile($staging.'/html/email.html.twig', $this->templateBuilder->build($mjml, $map));
            $this->copySkeleton($staging);
            $this->filesystem->dumpFile($staging.'/config.json', $this->encode($this->config([], $request->name, $request)));
            $this->filesystem->dumpFile($staging.'/composer.json', $this->encode([
                'name'        => 'local/theme-'.$key,
                'description' => $request->name,
                'type'        => 'mautic-theme',
                'extra'       => ['install-directory-name' => $key],
            ]));
            $this->filesystem->dumpFile($staging.'/thumbnail.png', $thumbnail);

            if ($this->filesystem->exists($target) || !@rename($staging, $target)) {
                throw new ThemeWriteException('mautic.email_to_theme.error.exists', ['%key%' => $key]);
            }
        } catch (ThemeWriteException $e) {
            $this->filesystem->remove($staging);
            throw $e;
        } catch (\Throwable $e) {
            $this->filesystem->remove($staging);
            throw new ThemeWriteException('mautic.email_to_theme.error.write', [], $e);
        }

        return new ConversionResult($key, $request->name, true, count($map), $collection->skipped, null);
    }

    private function update(string $root, ConversionRequest $request, string $mjml, CollectionResult $collection): ConversionResult
    {
        $key = (string) $request->targetKey;
        $dir = $root.'/'.$key;
        if ('' === $key || 1 !== preg_match('/^[A-Za-z0-9._-]+$/', $key) || str_starts_with($key, '.') || !is_dir($dir)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.not_found', ['%key%' => $key]);
        }

        $configFile = $dir.'/config.json';
        $config     = json_decode((string) @file_get_contents($configFile), true);
        if (!is_array($config)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.bad_config', ['%key%' => $key]);
        }

        $thumbnail = null !== $request->thumbnailPath ? $this->thumbnailPng($request->thumbnailPath) : null;
        $backup    = $this->backup($dir, $key);

        try {
            $this->filesystem->mkdir([$dir.'/html', $dir.'/assets']);
            $map = $this->writeAssets($dir.'/assets', $collection->assets);
            $this->replaceFile($dir.'/html/email.html.twig', $this->templateBuilder->build($mjml, $map));
            $this->copySkeleton($dir, true);
            $name = (string) ($config['name'] ?? $key);
            $this->replaceFile($configFile, $this->encode($this->config($config, $name, $request)));

            if (null !== $thumbnail) {
                $this->replaceFile($dir.'/thumbnail.png', $thumbnail);
                // An email-specific thumbnail would win over thumbnail.png in the theme picker.
                if ($this->filesystem->exists($dir.'/thumbnail_email.png')) {
                    $this->replaceFile($dir.'/thumbnail_email.png', $thumbnail);
                }
            }
        } catch (\Throwable $e) {
            throw new ThemeWriteException('mautic.email_to_theme.error.update_failed', ['%backup%' => $backup], $e);
        }

        return new ConversionResult($key, $name, false, count($map), $collection->skipped, $backup);
    }

    /**
     * Writes the images and returns which file each reference now points at. A file that is
     * already in the folder with the same bytes is reused; a different file with the same name
     * gets a number added.
     *
     * @param list<CollectedAsset> $assets
     *
     * @return array<string, string> reference as written in the MJML => file name
     */
    private function writeAssets(string $assetsDir, array $assets): array
    {
        $map   = [];
        $taken = [];

        foreach ($assets as $asset) {
            $hash = sha1($asset->contents);
            $name = $this->freeName($assetsDir, $asset->suggestedName, $hash, $taken);
            if (!isset($taken[$name])) {
                if (!$this->filesystem->exists($assetsDir.'/'.$name)) {
                    $this->filesystem->dumpFile($assetsDir.'/'.$name, $asset->contents);
                }
                $taken[$name] = $hash;
            }
            $map[$asset->url] = $name;
        }

        return $map;
    }

    /**
     * @param array<string, string> $taken names used in this run => content hash
     */
    private function freeName(string $dir, string $suggested, string $hash, array $taken): string
    {
        $base      = pathinfo($suggested, PATHINFO_FILENAME);
        $extension = pathinfo($suggested, PATHINFO_EXTENSION);

        for ($i = 1; ; ++$i) {
            $name = 1 === $i ? $suggested : $base.'-'.$i.'.'.$extension;
            if (isset($taken[$name])) {
                if ($taken[$name] === $hash) {
                    return $name;
                }
                continue;
            }
            $path = $dir.'/'.$name;
            if (!$this->filesystem->exists($path) || (is_file($path) && sha1_file($path) === $hash)) {
                return $name;
            }
        }
    }

    private function copySkeleton(string $dir, bool $onlyMissing = false): void
    {
        foreach (['base.html.twig', 'message.html.twig'] as $file) {
            if ($onlyMissing && $this->filesystem->exists($dir.'/html/'.$file)) {
                continue;
            }
            $this->filesystem->copy(self::SKELETON_DIR.'/html/'.$file, $dir.'/html/'.$file, true);
        }
    }

    /**
     * @param array<string, mixed> $config existing config.json, or [] for a new theme
     *
     * @return array<string, mixed>
     */
    private function config(array $config, string $name, ConversionRequest $request): array
    {
        $config['name'] = $name;
        $config += ['author' => 'Email to Theme', 'authorUrl' => ''];

        $builders          = array_values((array) ($config['builder'] ?? []));
        $config['builder'] = in_array('grapesjsbuilder', $builders, true) ? $builders : array_merge($builders, ['grapesjsbuilder']);

        $features           = array_values((array) ($config['features'] ?? []));
        $config['features'] = in_array('email', $features, true) ? $features : array_merge($features, ['email']);

        $config['emailToTheme'] = [
            'sourceEmailId'   => $request->emailId,
            'sourceEmailName' => $request->emailName,
            'updatedAt'       => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];

        return $config;
    }

    /**
     * @throws ThemeWriteException
     */
    private function backup(string $dir, string $key): string
    {
        $params = ['%dir%' => $this->backupDir];
        if (!class_exists(\ZipArchive::class)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.backup', $params);
        }

        try {
            $this->filesystem->mkdir($this->backupDir, 0750);
        } catch (\Throwable $e) {
            throw new ThemeWriteException('mautic.email_to_theme.error.backup_dir', $params, $e);
        }
        if (!is_writable($this->backupDir)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.backup_dir', $params);
        }

        $path = $this->backupDir.'/'.$key.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
        $zip  = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.backup', $params);
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && !$file->isLink()) {
                $zip->addFile($file->getPathname(), $key.'/'.substr($file->getPathname(), strlen($dir) + 1));
            }
        }

        if (!$zip->close() || !is_file($path)) {
            throw new ThemeWriteException('mautic.email_to_theme.error.backup', $params);
        }

        return $path;
    }

    /**
     * Converts the upload to PNG (the only thumbnail format the theme picker reads),
     * scaled down to the picker's width.
     *
     * @throws ThemeWriteException
     */
    private function thumbnailPng(string $path): string
    {
        $contents = @file_get_contents($path);
        $image    = false !== $contents && function_exists('imagecreatefromstring') ? @imagecreatefromstring($contents) : false;
        if (false === $image) {
            throw new ThemeWriteException('mautic.email_to_theme.error.thumbnail');
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        if ($width > self::THUMBNAIL_MAX_WIDTH) {
            $scaled = imagescale($image, self::THUMBNAIL_MAX_WIDTH, max(1, (int) round($height * self::THUMBNAIL_MAX_WIDTH / $width)));
            if (false !== $scaled) {
                $image = $scaled;
            }
        }
        imagesavealpha($image, true);

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    private function replaceFile(string $path, string $contents): void
    {
        // dumpFile writes to a temporary file and renames it, so readers never see half a file.
        $this->filesystem->dumpFile($path, $contents);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
