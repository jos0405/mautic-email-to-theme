<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme;

/**
 * Turns the email's MJML into the theme's html/email.html.twig.
 *
 * Mautic renders theme templates with Twig, so anything in the email that looks like Twig
 * ("{{", "{%", "{#") is escaped and comes out exactly as written. Copied images are replaced
 * with getAssetUrl() calls so they follow the theme folder, the way Mautic's own themes do.
 */
class TemplateBuilder
{
    /**
     * @param array<string, string> $assetMap image reference as written in the MJML => file name in assets/
     */
    public function build(string $mjml, array $assetMap): string
    {
        $output   = '';
        $position = 0;

        foreach (AssetCollector::findReferences($mjml) as $reference) {
            if (!isset($assetMap[$reference['raw']])) {
                continue;
            }

            $output .= self::escape(substr($mjml, $position, $reference['offset'] - $position));
            $output .= self::assetExpression($assetMap[$reference['raw']]);
            $position = $reference['offset'] + strlen($reference['raw']);
        }

        return $output.self::escape(substr($mjml, $position));
    }

    public static function escape(string $text): string
    {
        return (string) preg_replace_callback('/\{[{%#]/', static fn (array $m): string => "{{ '".$m[0]."' }}", $text);
    }

    private static function assetExpression(string $fileName): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/', $fileName)) {
            throw new \InvalidArgumentException('Unsafe asset file name: '.$fileName);
        }

        return "{{ getAssetUrl('themes/'~template~'/assets/".$fileName."', null, null, true) }}";
    }
}
