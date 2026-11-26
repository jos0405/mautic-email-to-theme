<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme;

use Mautic\CoreBundle\Helper\ThemeHelper;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Which themes can be updated, and which folder name a new theme gets.
 */
class ThemeCatalog
{
    public function __construct(private readonly ThemeHelper $themeHelper)
    {
    }

    /**
     * Email themes for the MJML builder, without Mautic's own themes: a Mautic update would
     * overwrite those and undo the change.
     *
     * @return array<string, string> folder => name
     */
    public function updatableThemes(): array
    {
        $defaults = $this->themeHelper->getDefaultThemes();
        $themes   = [];

        // 'all' and our own filter: Mautic's 'email' list depends on which builder is switched on.
        foreach ($this->themeHelper->getInstalledThemes('all', true, true) as $key => $info) {
            $builders = (array) ($info['config']['builder'] ?? []);
            $features = (array) ($info['config']['features'] ?? []);
            if (!in_array('grapesjsbuilder', $builders, true) || !in_array('email', $features, true) || in_array($key, $defaults, true)) {
                continue;
            }
            $themes[(string) $key] = (string) $info['name'];
        }

        asort($themes, SORT_NATURAL | SORT_FLAG_CASE);

        return $themes;
    }

    public function isUpdatable(string $key): bool
    {
        return isset($this->updatableThemes()[$key]);
    }

    public static function keyFor(string $name): string
    {
        $key = strtolower((new AsciiSlugger())->slug($name)->toString());
        $key = trim(substr($key, 0, 50), '-');

        return '' === $key ? 'email-theme' : $key;
    }
}
