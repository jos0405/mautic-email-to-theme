<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Tests\Unit;

use MauticPlugin\EmailToThemeBundle\Theme\AssetCollector;
use MauticPlugin\EmailToThemeBundle\Theme\TemplateBuilder;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class TemplateBuilderTest extends TestCase
{
    public function testFindsImageReferencesInAttributesAndCss(): void
    {
        $mjml = '<mjml><mj-body><mj-image src="https://a.test/x.png" /><mj-hero background-url=\'/media/h.jpg\'></mj-hero>'
            .'<mj-raw><img src="b.gif"></mj-raw><mj-style>.x{background:url(/media/c.png)} .y{background:url("d.png")}</mj-style>'
            .'<mj-button href="https://a.test/page">Go</mj-button></mj-body></mjml>';

        $raws = array_column(AssetCollector::findReferences($mjml), 'raw');

        self::assertSame(['https://a.test/x.png', '/media/h.jpg', 'b.gif', '/media/c.png', 'd.png'], $raws);
    }

    public function testRendersBackToTheOriginalWithAssetsReplaced(): void
    {
        $mjml = '<mjml><mj-body><mj-image src="https://a.test/x.png?a=1&amp;b=2" />'
            .'<mj-text>Hi {contactfield=firstname}, {{ not twig }} {% neither %} {# nor this #} {{{ three</mj-text>'
            .'<mj-image src="https://a.test/keep.png" /></mj-body></mjml>';

        $twigSource = (new TemplateBuilder())->build($mjml, ['https://a.test/x.png?a=1&amp;b=2' => 'x.png']);

        $twig = new Environment(new ArrayLoader(['t' => $twigSource]), ['autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('getAssetUrl', fn (string $path): string => 'https://site.test/'.$path));

        $expected = str_replace('https://a.test/x.png?a=1&amp;b=2', 'https://site.test/themes/my-theme/assets/x.png', $mjml);
        self::assertSame($expected, $twig->render('t', ['template' => 'my-theme']));
    }

    public function testEscapesEveryTwigOpener(): void
    {
        self::assertSame("a{{ '{{' }}b{{ '{%' }}c{{ '{#' }}d{e}", TemplateBuilder::escape('a{{b{%c{#d{e}'));
    }

    public function testRefusesUnsafeAssetNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TemplateBuilder())->build('<mj-image src="x.png"/>', ['x.png' => "x'.png"]);
    }
}
