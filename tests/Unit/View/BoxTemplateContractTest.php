<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hält die Vererbungskette der beiden Vorlagen fest, an der die ganze Wirkung im Shop hängt.
 *
 * `box.html.twig` erbt vom Standard, fragt die Entscheidung zuerst am Request ab und setzt nur das
 * Layout; `box-rc-minimalistic.html.twig` erbt von `box-standard` und leert vier Blöcke. Twig meldet
 * keinen Fehler, wenn eine dieser Verbindungen reißt, es rendert dann still die Standard-Kachel.
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
            'box.html.twig muss vom Storefront-Standard erben, sonst fehlt der Dispatcher hinter parent().',
        );
    }

    public function testBoxOverrideSwitchesLayoutWhenExtensionActive(): void
    {
        $content = $this->loadTemplate('box.html.twig');

        self::assertStringContainsString(
            "app.request.attributes.get('rcMinimalisticLayoutActive')",
            $content,
            'Beim Nachladen über /widgets/cms/navigation liegt weder die Seite noch das Listing-Ergebnis im '
            . 'Twig-Kontext. Ohne den Request-Wert blättert der Kunde aus dem minimalistischen Layout heraus.',
        );
        self::assertStringContainsString(
            'page.extensions.rcMinimalisticLayout.active',
            $content,
            'Die Seite ist der Rückfall für Aufrufe, bei denen am Request keine Entscheidung steht.',
        );
        self::assertStringNotContainsString(
            'searchResult.extensions',
            $content,
            'Das Listing-Ergebnis erreicht diese Vorlage nicht. Eine Abfrage darauf sähe nach Absicherung aus, '
            . 'wäre aber wirkungslos.',
        );
        self::assertStringContainsString(
            "'rc-minimalistic'",
            $content,
            'Der Layout-Bezeichner muss rc-minimalistic lauten; der Dispatcher sucht danach '
            . 'box-rc-minimalistic.html.twig.',
        );
        self::assertStringContainsString(
            '{{ parent() }}',
            $content,
            'parent() ruft den Standard-Dispatcher auf, der die Unter-Vorlage einbindet.',
        );
    }

    public function testBoxOverrideDoesNotReplaceCardMarkup(): void
    {
        $content = $this->loadTemplate('box.html.twig');

        self::assertStringNotContainsString(
            'class="card product-box',
            $content,
            'Das Karten-Markup rendert die Unter-Vorlage; box.html.twig setzt nur das Layout.',
        );
    }

    public function testRcMinimalisticSubTemplateExtendsStandardBox(): void
    {
        $content = $this->loadTemplate('box-rc-minimalistic.html.twig');

        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/component/product/card/box-standard.html.twig' %}",
            $content,
            'Die Unter-Vorlage muss von box-standard erben, sonst fehlt das gesamte Karten-Markup.',
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
            sprintf('Block "%s" muss leer überschrieben sein, sonst bleibt er sichtbar.', $blockName),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBlocksToBlank(): array
    {
        return [
            'description' => ['component_product_box_description'],
            'variant_characteristics' => ['component_product_box_variant_characteristics'],
            'action' => ['component_product_box_action'],
            'rating' => ['component_product_box_rating'],
        ];
    }

    public function testStorefrontScssIsShippedAtConventionPath(): void
    {
        $scss = __DIR__ . '/../../../src/Resources/app/storefront/src/scss/base.scss';

        self::assertFileExists(
            $scss,
            'Shopware bindet nur base.scss am Konventionspfad ein; jede andere Datei übergeht theme:compile.',
        );

        $content = file_get_contents($scss);
        self::assertIsString($content);
        self::assertStringContainsString(
            '.product-box.box-rc-minimalistic',
            $content,
            'Die Regeln müssen auf die CSS-Klasse zielen, die das Layout rc-minimalistic erzeugt.',
        );
    }

    private function loadTemplate(string $name): string
    {
        $path = self::VIEWS_DIR . '/' . $name;
        $content = file_get_contents($path);

        self::assertIsString($content, sprintf('Vorlage %s konnte nicht gelesen werden.', $name));

        return $content;
    }
}
