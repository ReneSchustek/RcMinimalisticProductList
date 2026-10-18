<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutScope;
use Ruhrcoder\RcMinimalisticProductList\Subscriber\ListingLayoutSubscriber;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPage;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Prüft die Wege, auf denen das Listing-Layout gesetzt wird (Controller, Seitenaufruf, Listing-Ergebnis,
 * jede Storefront-Seite), gegen eine echte Datenbank und einen echten Kernel.
 *
 * Der Nachlade-Weg lädt seine Kategorie selbst. Mit `Criteria::addFields()` liefert der Hydrator dort
 * eine `PartialEntity` statt einer `CategoryEntity`, und die Erweiterung fehlt beim Filtern. Ein Mock
 * gäbe weiterhin eine `CategoryEntity` zurück; nur die echte Datenbank zeigt den Fehler.
 */
#[CoversClass(ListingLayoutSubscriber::class)]
#[CoversClass(LayoutDecision::class)]
final class ListingLayoutSubscriberIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const NAVIGATION_ID_ATTRIBUTE = 'navigationId';

    /**
     * Der Nachlade-Weg beim Filtern und Blättern, mit einer Kategorie, die das Merkmal trägt.
     */
    public function testListingPathSetsTheExtensionForACategoryWithTheFlag(): void
    {
        $categoryId = $this->createCategory(true);

        $extension = $this->listingResultThroughSubscriber($categoryId)
            ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertTrue($extension->get('active'));
    }

    /**
     * Gegenprobe: Ohne sie bestünde auch ein Subscriber, der immer `active` meldet.
     */
    public function testWithoutTheFlagTheDefaultLayoutRemains(): void
    {
        $extension = $this->listingResultThroughSubscriber($this->createCategory(false))
            ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertFalse($extension->get('active'));
    }

    /**
     * Das Merkmal vererbt sich nicht: Es wirkt nur an der Kategorie, an der es gesetzt ist.
     */
    public function testTheFlagIsNotInheritedBySubcategories(): void
    {
        $parentId = $this->createCategory(true);
        $childId = $this->createCategory(false, $parentId);

        $extension = $this->listingResultThroughSubscriber($childId)
            ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertFalse(
            $extension->get('active'),
            'Soll sich das Merkmal vererben, ist das eine Entscheidung, und dieser Test der Ort dafür.',
        );
    }

    /**
     * Im Geltungsbereich „Alle Seiten“ braucht der Nachlade-Weg keine Kategorie und keine Datenbank.
     */
    public function testScopeAllPagesNeedsNoCategory(): void
    {
        $previous = $this->setScope(LayoutScope::All->value);

        try {
            $extension = $this->listingResultThroughSubscriber(null)
                ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

            static::assertInstanceOf(ArrayStruct::class, $extension);
            static::assertTrue($extension->get('active'));
        } finally {
            $this->setScope($previous);
        }
    }

    /**
     * „Aus“ greift auch an einer Kategorie, die das Merkmal trägt.
     */
    public function testScopeOffOverridesTheCustomField(): void
    {
        $categoryId = $this->createCategory(true);
        $previous = $this->setScope(LayoutScope::Off->value);

        try {
            $extension = $this->listingResultThroughSubscriber($categoryId)
                ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

            static::assertInstanceOf(ArrayStruct::class, $extension);
            static::assertFalse($extension->get('active'));
        } finally {
            $this->setScope($previous);
        }
    }

    public function testNavigationPagePathSetsTheExtension(): void
    {
        $extension = $this->navigationPageThroughSubscriber($this->createCategory(true))
            ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertTrue($extension->get('active'));
    }

    public function testNavigationPagePathWithoutTheFlagStaysDefault(): void
    {
        $extension = $this->navigationPageThroughSubscriber($this->createCategory(false))
            ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertFalse($extension->get('active'));
    }

    /**
     * Startseite und Suche haben keine Kategorie; ohne den Seiten-Weg bliebe „Alle Seiten“ dort wirkungslos.
     */
    public function testEveryPageGetsTheScopeAllPages(): void
    {
        $previous = $this->setScope(LayoutScope::All->value);

        try {
            $page = new Page();

            $this->subscriber()->onGenericPageLoaded(
                new GenericPageLoadedEvent($page, $this->salesChannelContext(), new Request()),
            );

            $extension = $page->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);
            static::assertInstanceOf(ArrayStruct::class, $extension);
            static::assertTrue($extension->get('active'));
        } finally {
            $this->setScope($previous);
        }
    }

    /**
     * Im Kategorie-Modus lässt der Seiten-Weg die Seite liegen. Setzte er `false`, überschriebe er das
     * Ergebnis des Kategorie-Wegs.
     */
    public function testInCategoryModeThePagePathLeavesThePageAlone(): void
    {
        $page = new Page();

        $this->subscriber()->onGenericPageLoaded(
            new GenericPageLoadedEvent($page, $this->salesChannelContext(), new Request()),
        );

        static::assertNull($page->getExtension(ListingLayoutSubscriber::EXTENSION_NAME));
    }

    /**
     * Über den Request erfährt die Vorlage die Entscheidung auch beim Nachladen, wo weder Seite noch
     * Listing-Ergebnis im Twig-Kontext stehen und Shopware die Kategorie-Seite aus dem Cache beantwortet.
     */
    public function testControllerListenerStoresTheDecisionOnTheRequest(): void
    {
        $request = $this->requestWithNavigation($this->createCategory(true));

        $this->subscriber()->onController($this->controllerEvent($request));

        static::assertTrue(
            $request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE),
            'Ohne diesen Wert blättert der Kunde aus dem minimalistischen Layout heraus.',
        );
    }

    /**
     * Der Wert ist ausdrücklich `false`, damit die Vorlage nicht auf die Seite zurückfällt.
     */
    public function testWithoutTheFlagTheRequestCarriesExplicitFalse(): void
    {
        $request = $this->requestWithNavigation($this->createCategory(false));

        $this->subscriber()->onController($this->controllerEvent($request));

        static::assertFalse($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    /**
     * Der Controller-Weg läuft bei jedem Storefront-Request; im Geltungsbereich „Alle Seiten“ darf er
     * dafür keine Kategorie brauchen.
     */
    public function testScopeAllPagesNeedsNoCategoryAtTheController(): void
    {
        $previous = $this->setScope(LayoutScope::All->value);

        try {
            $request = $this->requestWithNavigation(null);

            $this->subscriber()->onController($this->controllerEvent($request));

            static::assertTrue($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
        } finally {
            $this->setScope($previous);
        }
    }

    /**
     * Administration und interne Aufrufe haben keinen Verkaufskanal-Kontext. Der Weg hängt am Kernel und
     * läuft auch dort; er darf dann nichts anfassen und keine Ausnahme werfen.
     */
    public function testWithoutSalesChannelContextNothingHappens(): void
    {
        $request = new Request();
        $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, $this->createCategory(true));

        $this->subscriber()->onController($this->controllerEvent($request));

        static::assertNull($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    private function createCategory(bool $flagActive, ?string $parentId = null): string
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'Test category ' . $id,
            'customFields' => [LayoutDecision::CUSTOM_FIELD_KEY => $flagActive],
        ];

        if ($parentId !== null) {
            $data['parentId'] = $parentId;
        }

        $this->categoryRepository()->create([$data], Context::createDefaultContext());

        return $id;
    }

    /**
     * Gibt den vorherigen Wert zurück, damit der Test ihn im `finally` wiederherstellt. Sonst hinge das
     * Ergebnis der folgenden Tests von der zufälligen Reihenfolge ab.
     */
    private function setScope(?string $value): ?string
    {
        $systemConfigService = $this->systemConfigService();
        $previous = $systemConfigService->get(LayoutDecision::CONFIG_KEY);

        if ($value === null) {
            $systemConfigService->delete(LayoutDecision::CONFIG_KEY);
        } else {
            $systemConfigService->set(LayoutDecision::CONFIG_KEY, $value);
        }

        return \is_string($previous) ? $previous : null;
    }

    /**
     * Die Kategorie kommt wie im Betrieb über `navigationId` am Request, damit der Subscriber sie
     * tatsächlich aus der Datenbank lädt.
     */
    private function listingResultThroughSubscriber(?string $navigationId): ProductListingResult
    {
        $context = $this->salesChannelContext();
        $result = new ProductListingResult(
            'product',
            0,
            new ProductCollection([]),
            null,
            new Criteria(),
            $context->getContext(),
        );

        $request = new Request();
        if ($navigationId !== null) {
            $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, $navigationId);
        }

        $this->subscriber()->onListingResult(new ProductListingResultEvent($request, $result, $context));

        return $result;
    }

    private function navigationPageThroughSubscriber(string $categoryId): NavigationPage
    {
        $context = $this->salesChannelContext();

        $category = $this->categoryRepository()
            ->search(new Criteria([$categoryId]), $context->getContext())
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $category);

        $page = new NavigationPage();
        $page->setCategory($category);

        $this->subscriber()->onNavigationPageLoaded(
            new NavigationPageLoadedEvent($page, $context, new Request()),
        );

        return $page;
    }

    /**
     * Symfony macht Dienste standardmäßig privat, und einen Subscriber spricht nur der Event-Dispatcher an;
     * im Test-Container ist er deshalb nicht abrufbar. Seine beiden Abhängigkeiten kommen echt aus dem
     * Container, samt Datenbank und Konfiguration.
     */
    private function subscriber(): ListingLayoutSubscriber
    {
        return new ListingLayoutSubscriber(
            $this->categoryRepository(),
            new LayoutDecision($this->systemConfigService()),
        );
    }

    private function salesChannelContext(): SalesChannelContext
    {
        // Geprüft wird auf die abstrakte Basis: Ist der Objekt-Cache aktiv, legt Shopware einen
        // Zwischenspeicher-Dekorator (CachedSalesChannelContextFactory) um die konkrete Klasse.
        $factory = $this->getContainer()->get(SalesChannelContextFactory::class);
        static::assertInstanceOf(AbstractSalesChannelContextFactory::class, $factory);

        return $factory->create(Uuid::randomHex(), $this->firstSalesChannel());
    }

    private function firstSalesChannel(): string
    {
        $connection = $this->getContainer()->get(Connection::class);
        static::assertInstanceOf(Connection::class, $connection);

        $id = $connection->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel WHERE active = 1 LIMIT 1');

        static::assertIsString($id, 'Kein aktiver Verkaufskanal in der Testdatenbank.');

        return $id;
    }

    /**
     * Ein Request, wie ihn die Storefront liefert: mit aufgelöstem Verkaufskanal-Kontext und, falls
     * angegeben, der Kategorie als Route-Parameter.
     */
    private function requestWithNavigation(?string $navigationId): Request
    {
        $request = new Request();
        $request->attributes->set(
            PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT,
            $this->salesChannelContext(),
        );

        if ($navigationId !== null) {
            $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, $navigationId);
        }

        return $request;
    }

    private function controllerEvent(Request $request): ControllerEvent
    {
        $kernel = $this->getContainer()->get('http_kernel');
        static::assertInstanceOf(HttpKernelInterface::class, $kernel);

        return new ControllerEvent(
            $kernel,
            static fn (): ?Response => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /** @return EntityRepository<CategoryCollection> */
    private function categoryRepository(): EntityRepository
    {
        $repository = $this->getContainer()->get('category.repository');
        static::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /**
     * Der Container liefert den Dienst untypisiert.
     */
    private function systemConfigService(): SystemConfigService
    {
        $systemConfigService = $this->getContainer()->get(SystemConfigService::class);
        static::assertInstanceOf(SystemConfigService::class, $systemConfigService);

        return $systemConfigService;
    }
}
