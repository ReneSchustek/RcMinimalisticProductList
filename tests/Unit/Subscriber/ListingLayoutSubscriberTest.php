<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Ruhrcoder\RcMinimalisticProductList\Subscriber\ListingLayoutSubscriber;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPage;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Symfony\Component\HttpFoundation\Request;

final class ListingLayoutSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsCoversBothPaths(): void
    {
        $events = ListingLayoutSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NavigationPageLoadedEvent::class, $events);
        self::assertSame('onNavigationPageLoaded', $events[NavigationPageLoadedEvent::class]);

        self::assertArrayHasKey(ProductListingResultEvent::class, $events);
        self::assertSame('onListingResult', $events[ProductListingResultEvent::class]);

        self::assertArrayHasKey(GenericPageLoadedEvent::class, $events);
        self::assertSame('onGenericPageLoaded', $events[GenericPageLoadedEvent::class]);
    }

    public function testOnNavigationPageLoadedWithActiveCustomFieldAddsPageExtension(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id-balkongelaender');
        $category->setCustomFields(['rc_show_minimalistic_productlist' => true]);

        $page = $this->createNavigationPage($category);
        $event = $this->createNavigationEvent($page);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $subscriber = $this->subscriber($repository);
        $subscriber->onNavigationPageLoaded($event);

        self::assertTrue($page->hasExtension('rcMinimalisticLayout'));

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $page->getExtension('rcMinimalisticLayout');
        self::assertTrue($extension->get('active'));
    }

    public function testOnNavigationPageLoadedWithInactiveCustomFieldAddsFalseExtension(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id-balkongelaender');
        $category->setCustomFields(['rc_show_minimalistic_productlist' => false]);

        $page = $this->createNavigationPage($category);
        $event = $this->createNavigationEvent($page);

        $repository = $this->createMock(EntityRepository::class);
        $subscriber = $this->subscriber($repository);
        $subscriber->onNavigationPageLoaded($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $page->getExtension('rcMinimalisticLayout');
        self::assertFalse($extension->get('active'));
    }

    public function testOnNavigationPageLoadedAcceptsScalarOneAsActive(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id-balkongelaender');
        // Shopware speichert Checkbox-Werte aus dem Admin als Integer 1 — analog zu der Live-DB-Spalte.
        $category->setCustomFields(['rc_show_minimalistic_productlist' => 1]);

        $page = $this->createNavigationPage($category);
        $event = $this->createNavigationEvent($page);

        $repository = $this->createMock(EntityRepository::class);
        $subscriber = $this->subscriber($repository);
        $subscriber->onNavigationPageLoaded($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $page->getExtension('rcMinimalisticLayout');
        self::assertTrue($extension->get('active'));
    }

    public function testOnNavigationPageLoadedWithoutCategoryStaysInactive(): void
    {
        $page = new NavigationPage();
        // Kein setCategory()-Aufruf — Page hat keine Listing-Kategorie (z. B. fehlerhafter Aufruf-Pfad).
        $event = $this->createNavigationEvent($page);

        $repository = $this->createMock(EntityRepository::class);
        $subscriber = $this->subscriber($repository);
        $subscriber->onNavigationPageLoaded($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $page->getExtension('rcMinimalisticLayout');
        self::assertFalse($extension->get('active'));
    }

    public function testGenericPageStaysUntouchedInCategoryScope(): void
    {
        $page = new Page();
        $subscriber = $this->subscriber($this->createMock(EntityRepository::class));

        $subscriber->onGenericPageLoaded($this->createGenericEvent($page));

        self::assertFalse($page->hasExtension('rcMinimalisticLayout'));
    }

    /**
     * Der Fall aus der Praxis: die Startseite hat keine markierte Kategorie. Im Geltungsbereich
     * „Alle Seiten" bekommt sie den Schalter trotzdem — genauso Produktseiten mit Cross-Selling.
     */
    public function testGenericPageGetsTheFlagWhenScopeIsAll(): void
    {
        $page = new Page();
        $subscriber = $this->subscriber($this->createMock(EntityRepository::class), LayoutDecision::SCOPE_ALL);

        $subscriber->onGenericPageLoaded($this->createGenericEvent($page));

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $page->getExtension('rcMinimalisticLayout');
        self::assertTrue($extension->get('active'));
    }

    public function testListingResultSkipsTheCategoryLookupWhenScopeIsAll(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $subscriber = $this->subscriber($repository, LayoutDecision::SCOPE_ALL);
        $event = $this->createListingEvent('category-id-123');

        $subscriber->onListingResult($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $event->getResult()->getExtension('rcMinimalisticLayout');
        self::assertTrue($extension->get('active'));
    }

    public function testListingResultStaysInactiveWhenScopeIsOff(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $subscriber = $this->subscriber($repository, LayoutDecision::SCOPE_OFF);
        $event = $this->createListingEvent('category-id-123');

        $subscriber->onListingResult($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $event->getResult()->getExtension('rcMinimalisticLayout');
        self::assertFalse($extension->get('active'));
    }

    public function testOnListingResultWithoutNavigationIdDoesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $subscriber = $this->subscriber($repository);
        $event = $this->createListingEvent(null);

        $subscriber->onListingResult($event);

        self::assertFalse($event->getResult()->hasExtension('rcMinimalisticLayout'));
    }

    public function testOnListingResultWithNonStringNavigationIdDoesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $subscriber = $this->subscriber($repository);

        $request = new Request();
        $request->attributes->set('navigationId', 12345);
        $event = $this->createListingEventWithRequest($request);

        $subscriber->onListingResult($event);

        self::assertFalse($event->getResult()->hasExtension('rcMinimalisticLayout'));
    }

    public function testOnListingResultWithUnknownCategoryDoesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(
            $this->createSearchResult(null),
        );

        $subscriber = $this->subscriber($repository);
        $event = $this->createListingEvent('category-id-123');

        $subscriber->onListingResult($event);

        self::assertFalse($event->getResult()->hasExtension('rcMinimalisticLayout'));
    }

    public function testOnListingResultWithActiveCustomFieldSetsExtension(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id-123');
        $category->setCustomFields(['rc_show_minimalistic_productlist' => true]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(
            $this->createSearchResult($category),
        );

        $subscriber = $this->subscriber($repository);
        $event = $this->createListingEvent('category-id-123');

        $subscriber->onListingResult($event);

        self::assertTrue($event->getResult()->hasExtension('rcMinimalisticLayout'));

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $event->getResult()->getExtension('rcMinimalisticLayout');
        self::assertTrue($extension->get('active'));
    }

    public function testOnListingResultWithInactiveCustomFieldSetsExtensionFalse(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id-123');
        $category->setCustomFields(['rc_show_minimalistic_productlist' => false]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(
            $this->createSearchResult($category),
        );

        $subscriber = $this->subscriber($repository);
        $event = $this->createListingEvent('category-id-123');

        $subscriber->onListingResult($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $event->getResult()->getExtension('rcMinimalisticLayout');
        self::assertFalse($extension->get('active'));
    }

    public function testOnListingResultWithMissingCustomFieldSetsExtensionFalse(): void
    {
        $category = new CategoryEntity();
        $category->setId('category-id-123');
        $category->setCustomFields([]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(
            $this->createSearchResult($category),
        );

        $subscriber = $this->subscriber($repository);
        $event = $this->createListingEvent('category-id-123');

        $subscriber->onListingResult($event);

        /** @var ArrayStruct<array<string, mixed>> $extension */
        $extension = $event->getResult()->getExtension('rcMinimalisticLayout');
        self::assertFalse($extension->get('active'));
    }

    /**
     * @param EntityRepository<CategoryCollection> $repository
     */
    private function subscriber(
        EntityRepository $repository,
        string $scope = LayoutDecision::SCOPE_CATEGORIES,
    ): ListingLayoutSubscriber {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturn($scope);

        return new ListingLayoutSubscriber($repository, new LayoutDecision($systemConfigService));
    }

    private function createGenericEvent(Page $page): GenericPageLoadedEvent
    {
        return new GenericPageLoadedEvent($page, $this->createSalesChannelContext(), new Request());
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn('sales-channel-id');

        return $salesChannelContext;
    }

    private function createNavigationPage(CategoryEntity $category): NavigationPage
    {
        $page = new NavigationPage();
        $page->setCategory($category);

        return $page;
    }

    private function createNavigationEvent(NavigationPage $page): NavigationPageLoadedEvent
    {
        return new NavigationPageLoadedEvent($page, $this->createSalesChannelContext(), new Request());
    }

    private function createListingEvent(?string $navigationId): ProductListingResultEvent
    {
        $request = new Request();
        if ($navigationId !== null) {
            $request->attributes->set('navigationId', $navigationId);
        }

        return $this->createListingEventWithRequest($request);
    }

    private function createListingEventWithRequest(Request $request): ProductListingResultEvent
    {
        $context = Context::createDefaultContext();
        $result = new ProductListingResult(
            'product',
            0,
            new ProductCollection(),
            null,
            new Criteria(),
            $context,
        );

        return new ProductListingResultEvent(
            $request,
            $result,
            $this->createSalesChannelContext(),
        );
    }

    /**
     * @return EntitySearchResult<CategoryCollection>
     */
    private function createSearchResult(?CategoryEntity $category): EntitySearchResult
    {
        $entities = $category !== null ? [$category] : [];

        return new EntitySearchResult(
            'category',
            \count($entities),
            new CategoryCollection($entities),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );
    }
}
