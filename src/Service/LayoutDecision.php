<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Entscheidet, ob eine Seite das minimalistische Layout bekommt.
 *
 * Die Entscheidung hat zwei Quellen — die Plugin-Einstellung „Geltungsbereich" (je Verkaufskanal)
 * und das Zusatzfeld an der Kategorie. Sie liegt hier und nicht im Subscriber, weil beide
 * Einstiegspunkte (Seitenaufruf und AJAX-Nachladen des Listings) dieselbe Antwort brauchen.
 */
final class LayoutDecision
{
    public const SCOPE_OFF = 'off';
    public const SCOPE_ALL = 'all';
    public const SCOPE_CATEGORIES = 'categories';

    public const CUSTOM_FIELD_KEY = 'rc_show_minimalistic_productlist';

    private const CONFIG_KEY = 'RcMinimalisticProductList.config.scope';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isActiveFor(?CategoryEntity $category, ?string $salesChannelId): bool
    {
        return match ($this->scope($salesChannelId)) {
            self::SCOPE_OFF => false,
            self::SCOPE_ALL => true,
            default => $this->hasActiveCustomField($category),
        };
    }

    /**
     * Nur im Kategorie-Modus muss der Aufrufer eine Kategorie beschaffen. Sonst spart sich der
     * AJAX-Pfad die Datenbank-Abfrage.
     */
    public function requiresCategory(?string $salesChannelId): bool
    {
        return $this->scope($salesChannelId) === self::SCOPE_CATEGORIES;
    }

    private function scope(?string $salesChannelId): string
    {
        $scope = $this->systemConfigService->getString(self::CONFIG_KEY, $salesChannelId);

        // Leerer oder unbekannter Wert bedeutet: bisheriges Verhalten. Ein Update darf das
        // Aussehen bestehender Shops nicht verändern.
        return \in_array($scope, [self::SCOPE_OFF, self::SCOPE_ALL, self::SCOPE_CATEGORIES], true)
            ? $scope
            : self::SCOPE_CATEGORIES;
    }

    private function hasActiveCustomField(?CategoryEntity $category): bool
    {
        if (!$category instanceof CategoryEntity) {
            return false;
        }

        $customFields = $category->getCustomFields() ?? [];

        return (bool) ($customFields[self::CUSTOM_FIELD_KEY] ?? false);
    }
}
