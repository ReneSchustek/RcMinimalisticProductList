<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList;

use Ruhrcoder\RcMinimalisticProductList\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
use Shopware\Core\System\CustomField\CustomFieldCollection;

/**
 * Lebenszyklus des Plugins: legt das Zusatzfeld an der Kategorie an, gleicht es bei jedem Update ab und
 * entfernt es nur, wenn der Betreiber beim Deinstallieren die Daten nicht behalten will.
 */
final class RcMinimalisticProductList extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $installer = $this->getCustomFieldsInstaller();
        $installer->install($installContext->getContext());
        // Die Bindung an die Kategorie entsteht schon hier. Nach einer Deinstallation mit behaltenen Daten
        // läuft activate() bei der Neuinstallation nicht erneut, und das Feld stünde an keiner Kategorie.
        $installer->addRelations($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        // Geänderte Beschriftungen und eine verlorene Kategorie-Bindung kommen so auch in bestehenden Shops an.
        // Der Installer übernimmt vorhandene IDs und lässt sich beliebig oft aufrufen.
        $installer = $this->getCustomFieldsInstaller();
        $installer->install($updateContext->getContext());
        $installer->addRelations($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if (!$uninstallContext->keepUserData()) {
            $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->getCustomFieldsInstaller()->addRelations($activateContext->getContext());
    }

    /**
     * Baut den Installer von Hand: Während install() und activate() stehen die Dienste des Plugins noch
     * nicht im Container, nur die des Kerns.
     */
    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        $container = $this->container;
        if ($container === null) {
            throw new \RuntimeException('Plugin container is not available.');
        }

        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');

        /** @var EntityRepository<CustomFieldSetRelationCollection> $customFieldSetRelationRepository */
        $customFieldSetRelationRepository = $container->get('custom_field_set_relation.repository');

        /** @var EntityRepository<CustomFieldCollection> $customFieldRepository */
        $customFieldRepository = $container->get('custom_field.repository');

        return new CustomFieldsInstaller(
            $customFieldSetRepository,
            $customFieldSetRelationRepository,
            $customFieldRepository,
        );
    }
}
