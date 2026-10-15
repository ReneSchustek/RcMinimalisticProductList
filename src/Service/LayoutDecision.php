<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Entscheidet, ob eine Seite das minimalistische Layout bekommt.
 *
 * Die Entscheidung hat zwei Quellen: die Plugin-Einstellung „Geltungsbereich“ (je Verkaufskanal) und
 * das Zusatzfeld an der Kategorie. Sie liegt hier und nicht im Subscriber, weil alle Einstiegspunkte
 * (Seitenaufruf, Nachladen des Listings, Produktseite) dieselbe Antwort brauchen.
 */
final class LayoutDecision
{
    public const CUSTOM_FIELD_KEY = 'rc_show_minimalistic_productlist';

    public const CONFIG_KEY = 'RcMinimalisticProductList.config.scope';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isActiveFor(?CategoryEntity $category, ?string $salesChannelId): bool
    {
        return match ($this->scope($salesChannelId)) {
            LayoutScope::Off => false,
            LayoutScope::All => true,
            LayoutScope::Categories => $this->hasActiveCustomField($category),
        };
    }

    /**
     * Nur im Kategorie-Modus muss der Aufrufer eine Kategorie beschaffen; in den beiden anderen
     * Modi spart er sich die Datenbankabfrage.
     */
    public function requiresCategory(?string $salesChannelId): bool
    {
        return $this->scope($salesChannelId) === LayoutScope::Categories;
    }

    private function scope(?string $salesChannelId): LayoutScope
    {
        $scope = $this->systemConfigService->getString(self::CONFIG_KEY, $salesChannelId);

        // Ein leerer oder unbekannter Wert fällt auf den Kategorie-Modus zurück. Er ist die Vorgabe
        // in config.xml, und ein Update ohne gespeicherte Einstellung verändert so keinen Shop.
        return LayoutScope::tryFrom($scope) ?? LayoutScope::Categories;
    }

    private function hasActiveCustomField(?CategoryEntity $category): bool
    {
        if (!$category instanceof CategoryEntity) {
            return false;
        }

        $customFields = $category->getCustomFields() ?? [];

        // Die Administration speichert das Häkchen als 1, über die API kann ebenso true ankommen;
        // der Cast deckt beides ab.
        return (bool) ($customFields[self::CUSTOM_FIELD_KEY] ?? false);
    }
}
