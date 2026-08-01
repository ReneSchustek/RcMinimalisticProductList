<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class LayoutDecisionTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testScopeAllActivatesWithoutAnyCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutDecision::SCOPE_ALL);

        self::assertTrue($decision->isActiveFor(null, self::SALES_CHANNEL_ID));
        self::assertFalse($decision->requiresCategory(self::SALES_CHANNEL_ID));
    }

    public function testScopeAllIgnoresAnUnmarkedCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutDecision::SCOPE_ALL);

        self::assertTrue($decision->isActiveFor($this->category(false), self::SALES_CHANNEL_ID));
    }

    public function testScopeOffStaysInactiveEvenForAMarkedCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutDecision::SCOPE_OFF);

        self::assertFalse($decision->isActiveFor($this->category(true), self::SALES_CHANNEL_ID));
        self::assertFalse($decision->requiresCategory(self::SALES_CHANNEL_ID));
    }

    public function testScopeCategoriesFollowsTheCustomField(): void
    {
        $decision = $this->decisionWithScope(LayoutDecision::SCOPE_CATEGORIES);

        self::assertTrue($decision->isActiveFor($this->category(true), self::SALES_CHANNEL_ID));
        self::assertFalse($decision->isActiveFor($this->category(false), self::SALES_CHANNEL_ID));
        self::assertTrue($decision->requiresCategory(self::SALES_CHANNEL_ID));
    }

    /**
     * Genau der Fall, der in der Praxis überrascht: die Startseite läuft über die Navigations-Wurzel,
     * an der niemand das Zusatzfeld erwartet — im Kategorie-Modus bleibt sie deshalb unverändert.
     */
    public function testScopeCategoriesStaysInactiveWithoutCategory(): void
    {
        $decision = $this->decisionWithScope(LayoutDecision::SCOPE_CATEGORIES);

        self::assertFalse($decision->isActiveFor(null, self::SALES_CHANNEL_ID));
    }

    public function testCategoryWithoutCustomFieldsIsInactive(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id');

        self::assertFalse(
            $this->decisionWithScope(LayoutDecision::SCOPE_CATEGORIES)->isActiveFor($category, self::SALES_CHANNEL_ID),
        );
    }

    public function testAdminStoresTheCheckboxAsIntegerOne(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id');
        $category->setCustomFields([LayoutDecision::CUSTOM_FIELD_KEY => 1]);

        self::assertTrue(
            $this->decisionWithScope(LayoutDecision::SCOPE_CATEGORIES)->isActiveFor($category, self::SALES_CHANNEL_ID),
        );
    }

    /**
     * Ein Update darf das Aussehen bestehender Shops nicht verändern: ohne gespeicherte Einstellung
     * gilt weiterhin der Kategorie-Modus.
     */
    public function testUnknownOrEmptyConfigurationFallsBackToCategories(): void
    {
        foreach (['', 'irgendwas'] as $stored) {
            $decision = $this->decisionWithScope($stored);

            self::assertTrue($decision->requiresCategory(self::SALES_CHANNEL_ID), 'gespeichert: ' . $stored);
            self::assertTrue($decision->isActiveFor($this->category(true), self::SALES_CHANNEL_ID));
            self::assertFalse($decision->isActiveFor($this->category(false), self::SALES_CHANNEL_ID));
        }
    }

    public function testConfigurationIsReadPerSalesChannel(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturnMap([
            ['RcMinimalisticProductList.config.scope', 'kanal-a', LayoutDecision::SCOPE_ALL],
            ['RcMinimalisticProductList.config.scope', 'kanal-b', LayoutDecision::SCOPE_OFF],
        ]);

        $decision = new LayoutDecision($systemConfigService);

        self::assertTrue($decision->isActiveFor(null, 'kanal-a'));
        self::assertFalse($decision->isActiveFor(null, 'kanal-b'));
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
