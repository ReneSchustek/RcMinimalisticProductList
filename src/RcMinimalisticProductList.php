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

final class RcMinimalisticProductList extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $installer = $this->getCustomFieldsInstaller();
        $installer->install($installContext->getContext());
        // Relation gleich mit anlegen, damit auch der keepUserData-Reinstall-Pfad
        // (bei dem activate() nicht erneut feuert) die Kategorie-Bindung erhält.
        $installer->addRelations($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        // Label-/Config-/Relation-Drift bestehender Installationen beim Plugin-Update
        // abgleichen — der ID-Auflösungs-Code im Installer ist re-run-sicher.
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

    // Während install()/activate() sind plugin-eigene Services noch nicht im DI-Container.
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
