<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme\Model;

final class ConversionResult
{
    /**
     * @param array<string, string> $skipped image address => translation key of the reason
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly bool $created,
        public readonly int $copiedImages,
        public readonly array $skipped,
        public readonly ?string $backupPath,
    ) {
    }
}
