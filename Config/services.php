<?php

declare(strict_types=1);

use MauticPlugin\EmailToThemeBundle\Theme\AssetCollector;
use MauticPlugin\EmailToThemeBundle\Theme\ThemeWriter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\EmailToThemeBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(
            Mautic\CoreBundle\DependencyInjection\MauticCoreExtension::DEFAULT_EXCLUDES,
            ['EmailToThemeBundle.php', 'Theme/Model', 'Theme/*Exception.php', 'Assets', 'docs', 'Resources', 'Translations'],
        )).'}');

    $services->set(AssetCollector::class)
        ->arg('$docroot', param('kernel.project_dir').'/docroot')
        // Never Mautic's shared client: downloads must refuse private network addresses.
        ->arg('$client', null);

    $services->set(ThemeWriter::class)
        ->arg('$backupDir', param('kernel.project_dir').'/var/email-to-theme-backups');
};
