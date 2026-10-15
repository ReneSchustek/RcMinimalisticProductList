<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Legt das Zusatzfeld „Eine minimalistische Produktliste anzeigen“ samt Bindung an die Kategorie an,
 * gleicht es ab und entfernt es wieder.
 *
 * Er ist kein Dienst im Container, weil er nur in den Lebenszyklus-Methoden läuft, in denen die Dienste
 * des Plugins noch nicht geladen sind; die Plugin-Klasse baut ihn dort mit den Repositories des Kerns.
 */
final class CustomFieldsInstaller
{
    private const CUSTOM_FIELDSET_NAME = 'rc_show_minimalistic_productlist_category_bool';
    private const RELATION_ENTITY = 'category';

    // Die Systemsprache bekommt den englischen Text, wie bei der Überschrift des Sets.
    private const FIELD_LABEL_EN = 'Show a minimalistic product list';
    private const FIELD_HELP_TEXT_EN = 'If activated, the product list of this category only shows '
        . 'picture, title and price.';

    private const CUSTOM_FIELDSET = [
        'name' => self::CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'Minimalistic Product List',
                'de-DE' => 'Minimalistische Produktliste',
                Defaults::LANGUAGE_SYSTEM => 'Minimalistic Product List',
            ],
            'translated' => true,
        ],
        'allow_customer_write' => false,
        'allow_cart_expose' => false,
        'store_api_aware' => false,
        'active' => true,
        'global' => true,
        'customFields' => [
            [
                'name' => 'rc_show_minimalistic_productlist',
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    // Dieselbe Vorgabe schreibt die Administration für ein dort angelegtes Kontrollkästchen;
                    // der Feld-Renderer leitet die Komponente dann aus `type` ab.
                    'componentName' => 'sw-field',
                    'type' => 'checkbox',
                    'customFieldType' => 'checkbox',
                    'label' => [
                        'en-GB' => self::FIELD_LABEL_EN,
                        'de-DE' => 'Eine minimalistische Produktliste anzeigen',
                        Defaults::LANGUAGE_SYSTEM => self::FIELD_LABEL_EN,
                    ],
                    'helpText' => [
                        'en-GB' => self::FIELD_HELP_TEXT_EN,
                        'de-DE' => 'Wenn aktiviert, enthält die Produktliste nur das Bild, den Titel und den Preis.',
                        Defaults::LANGUAGE_SYSTEM => self::FIELD_HELP_TEXT_EN,
                    ],
                    'customFieldPosition' => 1,
                ],
                'active' => true,
            ],
        ],
    ];

    /**
     * @param EntityRepository<CustomFieldSetCollection>         $customFieldSetRepository
     * @param EntityRepository<CustomFieldSetRelationCollection> $customFieldSetRelationRepository
     * @param EntityRepository<CustomFieldCollection>            $customFieldRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldSetRelationRepository,
        private readonly EntityRepository $customFieldRepository,
    ) {
    }

    public function install(Context $context): void
    {
        // Die IDs des vorhandenen Sets und seiner Felder gehören in die Nutzlast, damit der Upsert
        // aktualisiert statt neu anzulegen. Sonst scheitert er in bestehenden Shops am eindeutigen
        // Feldnamen (uniq.custom_field.name).
        $payload = self::CUSTOM_FIELDSET;
        $existingSet = $this->resolveExistingSet($context);

        if ($existingSet !== null) {
            $payload['id'] = $existingSet->getId();
        }

        $payload['customFields'] = $this->enrichCustomFieldsWithExistingIds($payload['customFields'], $context);

        $this->customFieldSetRepository->upsert([$payload], $context);
    }

    public function addRelations(Context $context): void
    {
        $existingSet = $this->resolveExistingSet($context);
        if ($existingSet === null) {
            return;
        }

        $setId = $existingSet->getId();
        $relationPayload = [
            'customFieldSetId' => $setId,
            'entityName' => self::RELATION_ENTITY,
        ];

        // Die vorhandene Bindung geht mit ihrer ID hinein. Ohne sie scheiterte jedes erneute Aktivieren am
        // eindeutigen Schlüssel aus Set und Entität (uniq.custom_field_set_relation).
        $existingRelationId = $this->resolveExistingRelationId($existingSet, self::RELATION_ENTITY);
        if ($existingRelationId !== null) {
            $relationPayload['id'] = $existingRelationId;
        }

        $this->customFieldSetRelationRepository->upsert([$relationPayload], $context);
    }

    public function uninstall(Context $context): void
    {
        $ids = $this->getCustomFieldSetIds($context);
        if ($ids === []) {
            return;
        }

        $this->customFieldSetRepository->delete(array_map(static function (string $id): array {
            return ['id' => $id];
        }, $ids), $context);
    }

    private function resolveExistingSet(Context $context): ?CustomFieldSetEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));
        $criteria->addAssociation('relations');

        $entity = $this->customFieldSetRepository->search($criteria, $context)->getEntities()->first();

        return $entity instanceof CustomFieldSetEntity ? $entity : null;
    }

    private function resolveExistingRelationId(CustomFieldSetEntity $set, string $entityName): ?string
    {
        $relations = $set->getRelations();
        if ($relations === null) {
            return null;
        }

        foreach ($relations as $relation) {
            if ($relation->getEntityName() === $entityName) {
                return $relation->getId();
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $customFields
     *
     * @return array<int, array<string, mixed>>
     */
    private function enrichCustomFieldsWithExistingIds(array $customFields, Context $context): array
    {
        $names = array_values(array_filter(
            array_map(static fn (array $field): mixed => $field['name'] ?? null, $customFields),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        if ($names === []) {
            return $customFields;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', $names));

        $existingFields = $this->customFieldRepository->search($criteria, $context)->getEntities();

        $idByName = [];
        foreach ($existingFields as $existingField) {
            $idByName[$existingField->getName()] = $existingField->getId();
        }

        if ($idByName === []) {
            return $customFields;
        }

        foreach ($customFields as $index => $field) {
            $name = $field['name'] ?? null;
            if (is_string($name) && isset($idByName[$name])) {
                $customFields[$index]['id'] = $idByName[$name];
            }
        }

        return $customFields;
    }

    /**
     * @return list<string>
     */
    private function getCustomFieldSetIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));

        return $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();
    }
}
