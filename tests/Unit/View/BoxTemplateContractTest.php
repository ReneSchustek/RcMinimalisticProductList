<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests für die Twig-Templates des Listing-Overrides.
 * Sichern die Vererbungskette gegen versehentliches Löschen oder Refactoring ab —
 * die Storefront-Wirkung hängt davon ab, dass:
 *   1. box.html.twig per sw_extends vom Standard erbt und nur das Layout setzt
 *   2. box.html.twig die Entscheidung am Request abfragt, nicht nur an der Seite
 *   3. box-rc-minimalistic.html.twig vom box-standard erbt und vier Blöcke leert
 */
final class BoxTemplateContractTest extends TestCase
{
    private const VIEWS_DIR = __DIR__ . '/../../../src/Resources/views/storefront/component/product/card';

    public function testBoxOverrideExtendsStandardTemplate(): void
    {
        $content = $this->loadTemplate('box.html.twig');

        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/component/product/card/box.html.twig' %}",
            $content,
            'box.html.twig muss vom Storefront-Standard erben — sonst geht der parent()-Dispatcher verloren.',
        );
    }

    public function testBoxOverrideSwitchesLayoutWhenExtensionActive(): void
    {
        $content = $this->loadTemplate('box.html.twig');

        self::assertStringContainsString(
            "app.request.attributes.get('rcMinimalisticLayoutActive')",
            $content,
            'Request-Pfad: Beim Nachladen über /widgets/cms/navigation liegt weder die Seite noch das '
            . 'Listing-Ergebnis im Twig-Kontext. Ohne den Request-Wert blättert der Kunde aus dem '
            . 'minimalistischen Layout heraus.',
        );
        self::assertStringContainsString(
            'page.extensions.rcMinimalisticLayout.active',
            $content,
            'Seiten-Pfad als Rückfall: greift für Vorlagen, die außerhalb eines Storefront-Requests rendern.',
        );
        self::assertStringNotContainsString(
            'searchResult.extensions',
            $content,
            'Das Listing-Ergebnis erreicht diese Vorlage nicht -- am 2026-07-30 gegen echte Shopdaten '
            . 'nachgemessen. Eine Abfrage darauf sähe nach Absicherung aus, wäre aber wirkungslos.',
        );
        self::assertStringContainsString(
            "'rc-minimalistic'",
            $content,
            'Layout-Bezeichner muss exakt rc-minimalistic sein — Dispatcher in box.html.twig sucht box-rc-minimalistic.html.twig.',
        );
        self::assertStringContainsString(
            '{{ parent() }}',
            $content,
            'parent() muss den Standard-Dispatcher aufrufen, der das Sub-Template inkludiert.',
        );
    }

    public function testBoxOverrideDoesNotReplaceCardMarkup(): void
    {
        $content = $this->loadTemplate('box.html.twig');

        self::assertStringNotContainsString(
            'class="card product-box',
            $content,
            'box.html.twig darf das card-Markup NICHT selber rendern — das ist Aufgabe des Sub-Templates.',
        );
    }

    public function testRcMinimalisticSubTemplateExtendsStandardBox(): void
    {
        $content = $this->loadTemplate('box-rc-minimalistic.html.twig');

        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/component/product/card/box-standard.html.twig' %}",
            $content,
            'Sub-Template muss vom box-standard erben — sonst fehlt das gesamte Card-Markup.',
        );
    }

    #[DataProvider('provideBlocksToBlank')]
    public function testRcMinimalisticSubTemplateBlanksBlock(string $blockName): void
    {
        $content = $this->loadTemplate('box-rc-minimalistic.html.twig');
        $pattern = '/\{% block ' . preg_quote($blockName, '/') . ' %\}\s*\{% endblock %\}/';

        self::assertMatchesRegularExpression(
            $pattern,
            $content,
            sprintf('Block "%s" muss leer überschrieben sein, sonst greift das Verstecken nicht.', $blockName),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBlocksToBlank(): array
    {
        return [
            'description'              => ['component_product_box_description'],
            'variant_characteristics'   => ['component_product_box_variant_characteristics'],
            'action'                    => ['component_product_box_action'],
            'rating'                    => ['component_product_box_rating'],
        ];
    }

    public function testObsoleteIslandTemplateIsRemoved(): void
    {
        $obsolete = self::VIEWS_DIR . '/box-minimalistic.html.twig';

        self::assertFileDoesNotExist(
            $obsolete,
            'box-minimalistic.html.twig war eine nirgendwo eingebundene Insel und muss in v1.2.2 entfernt sein.',
        );
    }

    public function testStorefrontScssIsShippedAtConventionPath(): void
    {
        $scss = __DIR__ . '/../../../src/Resources/app/storefront/src/scss/base.scss';

        self::assertFileExists(
            $scss,
            'base.scss muss am Shopware-Konventionspfad liegen — sonst greift theme:compile sie nicht automatisch.',
        );

        $content = file_get_contents($scss);
        self::assertIsString($content);
        self::assertStringContainsString(
            '.product-box.box-rc-minimalistic',
            $content,
            'SCSS muss exakt auf die vom Twig-Override gesetzte CSS-Klasse zielen.',
        );
    }

    private function loadTemplate(string $name): string
    {
        $path = self::VIEWS_DIR . '/' . $name;
        $content = file_get_contents($path);

        self::assertIsString($content, sprintf('Template %s konnte nicht gelesen werden.', $name));

        return $content;
    }
}
