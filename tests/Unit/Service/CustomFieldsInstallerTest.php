<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationEntity;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;

final class CustomFieldsInstallerTest extends TestCase
{
    /** @var EntityRepository<CustomFieldSetCollection>&MockObject */
    private EntityRepository&MockObject $setRepository;
    /** @var EntityRepository<CustomFieldSetRelationCollection>&MockObject */
    private EntityRepository&MockObject $relationRepository;
    /** @var EntityRepository<CustomFieldCollection>&MockObject */
    private EntityRepository&MockObject $fieldRepository;
    private Context $context;

    protected function setUp(): void
    {
        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->relationRepository = $this->createMock(EntityRepository::class);
        $this->fieldRepository = $this->createMock(EntityRepository::class);
        $this->context = Context::createDefaultContext();

        // Defaults: greenfield — kein Set, keine Felder.
        $this->setRepository->method('search')->willReturn($this->emptySetSearchResult());
        $this->fieldRepository->method('search')->willReturn($this->emptyFieldSearchResult());
    }

    public function testInstallCallsUpsertOnRepository(): void
    {
        $this->setRepository->expects($this->once())->method('upsert');

        $this->makeInstaller()->install($this->context);
    }

    public function testInstallEnrichesSetAndFieldIdsForIdempotentReinstall(): void
    {
        $existingSet = new CustomFieldSetEntity();
        $existingSet->setId('set-id-existing');

        $setResult = $this->createMock(EntitySearchResult::class);
        $setResult->method('first')->willReturn($existingSet);

        $existingField = new CustomFieldEntity();
        $existingField->setId('field-id-existing');
        $existingField->setName('rc_show_minimalistic_productlist');
        $existingField->setType('bool');

        $fieldResult = $this->createMock(EntitySearchResult::class);
        $fieldResult->method('getEntities')->willReturn(new CustomFieldCollection([$existingField]));

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($setResult);
        $this->fieldRepository = $this->createMock(EntityRepository::class);
        $this->fieldRepository->method('search')->willReturn($fieldResult);

        $this->setRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function (array $payload): bool {
                self::assertSame('set-id-existing', $payload[0]['id']);
                self::assertSame('field-id-existing', $payload[0]['customFields'][0]['id']);

                return true;
            }));

        $this->makeInstaller()->install($this->context);
    }

    public function testAddRelationsCallsUpsertWithExistingRelationIdForIdempotentReactivate(): void
    {
        $existingRelation = new CustomFieldSetRelationEntity();
        $existingRelation->setId('relation-id-existing');
        $existingRelation->setEntityName('category');

        $existingSet = new CustomFieldSetEntity();
        $existingSet->setId('set-id-existing');
        $existingSet->setRelations(new CustomFieldSetRelationCollection([$existingRelation]));

        $setResult = $this->createMock(EntitySearchResult::class);
        $setResult->method('first')->willReturn($existingSet);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($setResult);

        $this->relationRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function (array $payload): bool {
                self::assertSame('relation-id-existing', $payload[0]['id']);
                self::assertSame('set-id-existing', $payload[0]['customFieldSetId']);
                self::assertSame('category', $payload[0]['entityName']);

                return true;
            }));

        $this->makeInstaller()->addRelations($this->context);
    }

    public function testAddRelationsOmitsIdForGreenfieldRelation(): void
    {
        $existingSet = new CustomFieldSetEntity();
        $existingSet->setId('set-id-new');
        // Keine Relations gesetzt → null.

        $setResult = $this->createMock(EntitySearchResult::class);
        $setResult->method('first')->willReturn($existingSet);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('search')->willReturn($setResult);

        $this->relationRepository->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function (array $payload): bool {
                self::assertArrayNotHasKey('id', $payload[0]);
                self::assertSame('set-id-new', $payload[0]['customFieldSetId']);

                return true;
            }));

        $this->makeInstaller()->addRelations($this->context);
    }

    public function testAddRelationsSkipsUpsertWhenNoSetFound(): void
    {
        $this->relationRepository->expects($this->never())->method('upsert');

        $this->makeInstaller()->addRelations($this->context);
    }

    public function testUninstallDeletesCustomFieldSetWhenExists(): void
    {
        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('getIds')->willReturn(['set-id-1']);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('searchIds')->willReturn($idResult);
        $this->setRepository->expects($this->once())
            ->method('delete')
            ->with([['id' => 'set-id-1']]);

        $this->makeInstaller()->uninstall($this->context);
    }

    public function testUninstallSkipsDeleteWhenNoSetFound(): void
    {
        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('getIds')->willReturn([]);

        $this->setRepository = $this->createMock(EntityRepository::class);
        $this->setRepository->method('searchIds')->willReturn($idResult);
        $this->setRepository->expects($this->never())->method('delete');

        $this->makeInstaller()->uninstall($this->context);
    }

    private function makeInstaller(): CustomFieldsInstaller
    {
        return new CustomFieldsInstaller(
            $this->setRepository,
            $this->relationRepository,
            $this->fieldRepository,
        );
    }

    /**
     * @return EntitySearchResult<CustomFieldSetCollection>&MockObject
     */
    private function emptySetSearchResult(): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);

        return $result;
    }

    /**
     * @return EntitySearchResult<CustomFieldCollection>&MockObject
     */
    private function emptyFieldSearchResult(): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new CustomFieldCollection());

        return $result;
    }
}
