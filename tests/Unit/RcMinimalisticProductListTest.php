<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\RcMinimalisticProductList;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Symfony\Component\DependencyInjection\Container;

/**
 * Prüft, welche Schritte des Installers jede Lebenszyklus-Methode auslöst. Besonders die Bindung an die
 * Kategorie schon bei der Installation und das Behalten der Daten beim Deinstallieren hängen allein an
 * der Plugin-Klasse.
 */
final class RcMinimalisticProductListTest extends TestCase
{
    private const SHOPWARE_VERSION = '6.7.15.0';

    private const PLUGIN_VERSION = '1.4.1';

    /** @var EntityRepository<CustomFieldSetCollection>&MockObject */
    private EntityRepository&MockObject $setRepository;

    /** @var EntityRepository<CustomFieldSetRelationCollection>&MockObject */
    private EntityRepository&MockObject $relationRepository;

    /** @var EntityRepository<CustomFieldCollection>&MockObject */
    private EntityRepository&MockObject $fieldRepository;

    private RcMinimalisticProductList $plugin;

    protected function setUp(): void
    {
        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->relationRepository = $this->createMock(EntityRepository::class);
        $this->fieldRepository = $this->createMock(EntityRepository::class);

        // Ein vorhandenes Set, damit addRelations() bis zum Upsert durchläuft.
        $set = new CustomFieldSetEntity();
        $set->setId('set-id');
        $setResult = $this->createMock(EntitySearchResult::class);
        $setResult->method('getEntities')->willReturn(new CustomFieldSetCollection([$set]));
        $this->setRepository->method('search')->willReturn($setResult);

        $fieldResult = $this->createMock(EntitySearchResult::class);
        $fieldResult->method('getEntities')->willReturn(new CustomFieldCollection());
        $this->fieldRepository->method('search')->willReturn($fieldResult);

        $container = new Container();
        $container->set('custom_field_set.repository', $this->setRepository);
        $container->set('custom_field_set_relation.repository', $this->relationRepository);
        $container->set('custom_field.repository', $this->fieldRepository);

        $this->plugin = new RcMinimalisticProductList(true, __DIR__);
        $this->plugin->setContainer($container);
    }

    public function testInstallCreatesTheFieldAndBindsItToTheCategory(): void
    {
        $this->setRepository->expects($this->once())->method('upsert');
        $this->relationRepository->expects($this->once())->method('upsert');

        $this->plugin->install(new InstallContext(
            $this->plugin,
            Context::createDefaultContext(),
            self::SHOPWARE_VERSION,
            self::PLUGIN_VERSION,
            $this->createMock(MigrationCollection::class),
        ));
    }

    public function testUpdateReconcilesFieldAndBinding(): void
    {
        $this->setRepository->expects($this->once())->method('upsert');
        $this->relationRepository->expects($this->once())->method('upsert');

        $this->plugin->update(new UpdateContext(
            $this->plugin,
            Context::createDefaultContext(),
            self::SHOPWARE_VERSION,
            '1.4.0',
            $this->createMock(MigrationCollection::class),
            self::PLUGIN_VERSION,
        ));
    }

    public function testActivateOnlyBindsTheField(): void
    {
        $this->setRepository->expects($this->never())->method('upsert');
        $this->relationRepository->expects($this->once())->method('upsert');

        $this->plugin->activate(new ActivateContext(
            $this->plugin,
            Context::createDefaultContext(),
            self::SHOPWARE_VERSION,
            self::PLUGIN_VERSION,
            $this->createMock(MigrationCollection::class),
        ));
    }

    public function testUninstallRemovesTheFieldWhenDataIsNotKept(): void
    {
        $ids = $this->createMock(IdSearchResult::class);
        $ids->method('getIds')->willReturn(['set-id']);
        $this->setRepository->method('searchIds')->willReturn($ids);
        $this->setRepository->expects($this->once())->method('delete')->with([['id' => 'set-id']]);

        $this->plugin->uninstall($this->uninstallContext(false));
    }

    /**
     * Wer beim Deinstallieren die Daten behält, findet die gesetzten Häkchen nach einer Neuinstallation
     * wieder; das Feld darf dann nicht gelöscht werden.
     */
    public function testUninstallKeepsTheFieldWhenDataIsKept(): void
    {
        $this->setRepository->expects($this->never())->method('searchIds');
        $this->setRepository->expects($this->never())->method('delete');

        $this->plugin->uninstall($this->uninstallContext(true));
    }

    public function testWithoutContainerTheLifecycleFailsLoudly(): void
    {
        $this->plugin->setContainer(null);

        $this->expectException(\RuntimeException::class);

        $this->plugin->activate(new ActivateContext(
            $this->plugin,
            Context::createDefaultContext(),
            self::SHOPWARE_VERSION,
            self::PLUGIN_VERSION,
            $this->createMock(MigrationCollection::class),
        ));
    }

    private function uninstallContext(bool $keepUserData): UninstallContext
    {
        return new UninstallContext(
            $this->plugin,
            Context::createDefaultContext(),
            self::SHOPWARE_VERSION,
            self::PLUGIN_VERSION,
            $this->createMock(MigrationCollection::class),
            $keepUserData,
        );
    }
}
