<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme\Model;

/**
 * What the user asked for in the "Save as theme" form.
 */
final class ConversionRequest
{
    public const MODE_NEW    = 'new';
    public const MODE_UPDATE = 'update';

    public function __construct(
        public readonly string $mode,
        public readonly string $name,
        public readonly ?string $targetKey,
        public readonly ?string $thumbnailPath,
        public readonly int $emailId,
        public readonly string $emailName,
    ) {
    }
}
