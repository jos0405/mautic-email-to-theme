<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme\Model;

/**
 * Images that could be copied, and the addresses that were left as they are (with the reason).
 */
final class CollectionResult
{
    /**
     * @param list<CollectedAsset>  $assets
     * @param array<string, string> $skipped url => translation key of the reason
     */
    public function __construct(
        public readonly array $assets,
        public readonly array $skipped,
    ) {
    }
}
