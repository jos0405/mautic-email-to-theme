<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme\Model;

/**
 * An image found in the email, with its bytes, ready to be written to the theme's assets folder.
 */
final class CollectedAsset
{
    public function __construct(
        public readonly string $url,
        public readonly string $suggestedName,
        public readonly string $contents,
    ) {
    }
}
