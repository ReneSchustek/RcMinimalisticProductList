<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutScope;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Prüft die Entscheidung aus Geltungsbereich und Zusatzfeld, einschließlich des Rückfalls bei einem
 * leeren oder unbekannten Konfigurationswert.
 */
final class LayoutDecisionTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testScopeAllActivatesWithoutAnyCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutScope::All->value);

        self::assertTrue($decision->isActiveFor(null, self::SALES_CHANNEL_ID));
        self::assertFalse($decision->requiresCategory(self::SALES_CHANNEL_ID));
    }

    public function testScopeAllIgnoresAnUnmarkedCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutScope::All->value);

        self::assertTrue($decision->isActiveFor($this->category(false), self::SALES_CHANNEL_ID));
    }

    public function testScopeOffStaysInactiveEvenForAMarkedCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutScope::Off->value);

        self::assertFalse($decision->isActiveFor($this->category(true), self::SALES_CHANNEL_ID));
        self::assertFalse($decision->requiresCategory(self::SALES_CHANNEL_ID));
    }

    public function testScopeCategoriesFollowsTheCustomField(): void
    {
        $decision = $this->decisionWithScope(LayoutScope::Categories->value);

        self::assertTrue($decision->isActiveFor($this->category(true), self::SALES_CHANNEL_ID));
        self::assertFalse($decision->isActiveFor($this->category(false), self::SALES_CHANNEL_ID));
        self::assertTrue($decision->requiresCategory(self::SALES_CHANNEL_ID));
    }

    /**
     * Die Startseite läuft über die Navigations-Wurzel, an der niemand das Zusatzfeld erwartet; im
     * Kategorie-Modus bleibt sie deshalb unverändert.
     */
    public function testScopeCategoriesStaysInactiveWithoutCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutScope::Categories->value);

        self::assertFalse($decision->isActiveFor(null, self::SALES_CHANNEL_ID));
    }

    public function testCategoryWithoutCustomFieldsIsInactive(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id');

        self::assertFalse(
            $this->decisionWithScope(LayoutScope::Categories->value)
                ->isActiveFor($category, self::SALES_CHANNEL_ID),
        );
    }

    public function testAdminStoresTheCheckboxAsIntegerOne(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id');
        $category->setCustomFields([LayoutDecision::CUSTOM_FIELD_KEY => 1]);

        self::assertTrue(
            $this->decisionWithScope(LayoutScope::Categories->value)
                ->isActiveFor($category, self::SALES_CHANNEL_ID),
        );
    }

    /**
     * Ohne gespeicherte Einstellung gilt der Kategorie-Modus, damit ein Update das Aussehen bestehender
     * Shops nicht verändert.
     */
    public function testUnknownOrEmptyConfigurationFallsBackToCategories(): void
    {
        foreach (['', 'unknown-scope'] as $stored) {
            $decision = $this->decisionWithScope($stored);

            self::assertTrue($decision->requiresCategory(self::SALES_CHANNEL_ID), 'stored: ' . $stored);
            self::assertTrue($decision->isActiveFor($this->category(true), self::SALES_CHANNEL_ID));
            self::assertFalse($decision->isActiveFor($this->category(false), self::SALES_CHANNEL_ID));
        }
    }

    public function testConfigurationIsReadPerSalesChannel(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturnMap([
            [LayoutDecision::CONFIG_KEY, 'channel-a', LayoutScope::All->value],
            [LayoutDecision::CONFIG_KEY, 'channel-b', LayoutScope::Off->value],
        ]);

        $decision = new LayoutDecision($systemConfigService);

        self::assertTrue($decision->isActiveFor(null, 'channel-a'));
        self::assertFalse($decision->isActiveFor(null, 'channel-b'));
    }

    /**
     * Die Optionskennungen aus config.xml stehen so in system_config. Ändert sich ein Fallwert, fiele
     * jeder Shop mit diesem Wert still auf den Kategorie-Modus zurück.
     */
    public function testScopeValuesMatchTheConfigurationOptions(): void
    {
        $config = file_get_contents(__DIR__ . '/../../../src/Resources/config/config.xml');
        self::assertIsString($config);

        foreach (LayoutScope::cases() as $scope) {
            self::assertStringContainsString('<id>' . $scope->value . '</id>', $config);
        }
    }

    private function decisionWithScope(string $scope): LayoutDecision
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturn($scope);

        return new LayoutDecision($systemConfigService);
    }

    private function category(bool $active): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId('category-id');
        $category->setCustomFields([LayoutDecision::CUSTOM_FIELD_KEY => $active]);

        return $category;
    }
}
