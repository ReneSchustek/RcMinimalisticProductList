<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutScope;
use Ruhrcoder\RcMinimalisticProductList\Subscriber\ListingLayoutSubscriber;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPage;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prüft jeden Handler des Subscribers mit gestellter Konfiguration und gestelltem Repository, in allen
 * drei Geltungsbereichen. Den Weg über eine echte Datenbank prüft der Integrationstest.
 */
final class ListingLayoutSubscriberTest extends TestCase
{
    private const NAVIGATION_ID_ATTRIBUTE = 'navigationId';

    private const CATEGORY_ID = 'category-id';

    public function testGetSubscribedEventsCoversAllFiveEvents(): void
    {
        $events = ListingLayoutSubscriber::getSubscribedEvents();

        self::assertCount(5, $events);
        self::assertSame('onGenericPageLoaded', $events[GenericPageLoadedEvent::class]);
        self::assertSame('onNavigationPageLoaded', $events[NavigationPageLoadedEvent::class]);
        self::assertSame('onProductPageLoaded', $events[ProductPageLoadedEvent::class]);
        self::assertSame('onListingResult', $events[ProductListingResultEvent::class]);
        self::assertArrayHasKey(KernelEvents::CONTROLLER, $events);
    }

    /**
     * Vor der Kontextauflösung des Kerns fehlte der Verkaufskanal, und beim Blättern einer markierten
     * Kategorie kämen Standard-Kacheln.
     */
    public function testTheControllerListenerRunsAfterTheContextIsResolved(): void
    {
        $listener = ListingLayoutSubscriber::getSubscribedEvents()[KernelEvents::CONTROLLER];

        self::assertIsArray($listener);
        self::assertSame('onController', $listener[0]);
        self::assertLessThan(KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_CONTEXT_RESOLVE, $listener[1]);
    }

    public function testControllerStoresTheDecisionForAMarkedCategory(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult($this->category(true)));
        $request = $this->storefrontRequest(self::CATEGORY_ID);

        $this->subscriber($repository)->onController($this->controllerEvent($request));

        self::assertTrue($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    public function testControllerStoresExplicitFalseForAnUnmarkedCategory(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult($this->category(false)));
        $request = $this->storefrontRequest(self::CATEGORY_ID);

        $this->subscriber($repository)->onController($this->controllerEvent($request));

        self::assertFalse($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    /**
     * Ohne Kategorie entscheidet der Controller-Weg im Kategorie-Modus nichts; die Vorlage fällt dann auf
     * die Erweiterung an der Seite zurück, die onNavigationPageLoaded setzt.
     */
    public function testControllerLeavesTheRequestAloneWithoutNavigationId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');
        $request = $this->storefrontRequest(null);

        $this->subscriber($repository)->onController($this->controllerEvent($request));

        self::assertFalse($request->attributes->has(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    public function testControllerLeavesTheRequestAloneForAnUnknownCategory(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult(null));
        $request = $this->storefrontRequest(self::CATEGORY_ID);

        $this->subscriber($repository)->onController($this->controllerEvent($request));

        self::assertFalse($request->attributes->has(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    public function testControllerDecidesWithoutCategoryLookupWhenScopeIsAll(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');
        $request = $this->storefrontRequest(null);

        $this->subscriber($repository, LayoutScope::All)->onController($this->controllerEvent($request));

        self::assertTrue($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    public function testControllerIgnoresRequestsWithoutSalesChannelContext(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');
        $request = new Request();
        $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, self::CATEGORY_ID);

        $this->subscriber($repository, LayoutScope::All)->onController($this->controllerEvent($request));

        self::assertFalse($request->attributes->has(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    /**
     * Querverkauf und Schieber auf der Produktseite sehen aus wie das Listing der SEO-Kategorie.
     */
    public function testProductPageFollowsTheSeoCategory(): void
    {
        foreach ([true, false] as $marked) {
            [$page, $request] = $this->productPageThroughSubscriber($this->category($marked), LayoutScope::Categories);

            self::assertSame($marked, $request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
            self::assertSame($marked, $this->layoutFlagOf($page));
        }
    }

    public function testProductPageWithoutSeoCategoryStaysDefaultInCategoryMode(): void
    {
        [$page, $request] = $this->productPageThroughSubscriber(null, LayoutScope::Categories);

        self::assertFalse($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
        self::assertFalse($this->layoutFlagOf($page));
    }

    public function testProductPageIsMinimalisticEverywhereWhenScopeIsAll(): void
    {
        [$page, $request] = $this->productPageThroughSubscriber(null, LayoutScope::All);

        self::assertTrue($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
        self::assertTrue($this->layoutFlagOf($page));
    }

    public function testProductPageIgnoresAMarkedSeoCategoryWhenScopeIsOff(): void
    {
        [$page, $request] = $this->productPageThroughSubscriber($this->category(true), LayoutScope::Off);

        self::assertFalse($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
        self::assertFalse($this->layoutFlagOf($page));
    }

    public function testOnNavigationPageLoadedWithActiveCustomFieldAddsPageExtension(): void
    {
        $page = $this->createNavigationPage($this->category(true));

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $this->subscriber($repository)->onNavigationPageLoaded($this->createNavigationEvent($page));

        self::assertTrue($this->layoutFlagOf($page));
    }

    public function testOnNavigationPageLoadedWithInactiveCustomFieldAddsFalseExtension(): void
    {
        $page = $this->createNavigationPage($this->category(false));

        $this->subscriber($this->createMock(EntityRepository::class))
            ->onNavigationPageLoaded($this->createNavigationEvent($page));

        self::assertFalse($this->layoutFlagOf($page));
    }

    public function testOnNavigationPageLoadedAcceptsScalarOneAsActive(): void
    {
        $category = new CategoryEntity();
        $category->setId(self::CATEGORY_ID);
        // So speichert die Administration ein gesetztes Kontrollkästchen.
        $category->setCustomFields([LayoutDecision::CUSTOM_FIELD_KEY => 1]);
        $page = $this->createNavigationPage($category);

        $this->subscriber($this->createMock(EntityRepository::class))
            ->onNavigationPageLoaded($this->createNavigationEvent($page));

        self::assertTrue($this->layoutFlagOf($page));
    }

    public function testOnNavigationPageLoadedWithoutCategoryStaysInactive(): void
    {
        $page = new NavigationPage();

        $this->subscriber($this->createMock(EntityRepository::class))
            ->onNavigationPageLoaded($this->createNavigationEvent($page));

        self::assertFalse($this->layoutFlagOf($page));
    }

    public function testGenericPageStaysUntouchedInCategoryScope(): void
    {
        $page = new Page();

        $this->subscriber($this->createMock(EntityRepository::class))
            ->onGenericPageLoaded($this->createGenericEvent($page));

        self::assertFalse($page->hasExtension(ListingLayoutSubscriber::EXTENSION_NAME));
    }

    /**
     * Die Startseite hat keine markierte Kategorie und bekommt den Schalter im Geltungsbereich
     * „Alle Seiten“ trotzdem.
     */
    public function testGenericPageGetsTheFlagWhenScopeIsAll(): void
    {
        $page = new Page();

        $this->subscriber($this->createMock(EntityRepository::class), LayoutScope::All)
            ->onGenericPageLoaded($this->createGenericEvent($page));

        self::assertTrue($this->layoutFlagOf($page));
    }

    public function testListingResultSkipsTheCategoryLookupWhenScopeIsAll(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');
        $event = $this->createListingEvent(self::CATEGORY_ID);

        $this->subscriber($repository, LayoutScope::All)->onListingResult($event);

        self::assertTrue($this->layoutFlagOf($event->getResult()));
    }

    public function testListingResultStaysInactiveWhenScopeIsOff(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');
        $event = $this->createListingEvent(self::CATEGORY_ID);

        $this->subscriber($repository, LayoutScope::Off)->onListingResult($event);

        self::assertFalse($this->layoutFlagOf($event->getResult()));
    }

    public function testOnListingResultWithoutNavigationIdDoesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');
        $event = $this->createListingEvent(null);

        $this->subscriber($repository)->onListingResult($event);

        self::assertFalse($event->getResult()->hasExtension(ListingLayoutSubscriber::EXTENSION_NAME));
    }

    public function testOnListingResultWithNonStringNavigationIdDoesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('search');

        $request = new Request();
        $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, 12345);
        $event = $this->createListingEventWithRequest($request);

        $this->subscriber($repository)->onListingResult($event);

        self::assertFalse($event->getResult()->hasExtension(ListingLayoutSubscriber::EXTENSION_NAME));
    }

    public function testOnListingResultWithUnknownCategoryDoesNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult(null));
        $event = $this->createListingEvent(self::CATEGORY_ID);

        $this->subscriber($repository)->onListingResult($event);

        self::assertFalse($event->getResult()->hasExtension(ListingLayoutSubscriber::EXTENSION_NAME));
    }

    public function testOnListingResultWithActiveCustomFieldSetsExtension(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult($this->category(true)));
        $event = $this->createListingEvent(self::CATEGORY_ID);

        $this->subscriber($repository)->onListingResult($event);

        self::assertTrue($this->layoutFlagOf($event->getResult()));
    }

    public function testOnListingResultWithInactiveCustomFieldSetsExtensionFalse(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult($this->category(false)));
        $event = $this->createListingEvent(self::CATEGORY_ID);

        $this->subscriber($repository)->onListingResult($event);

        self::assertFalse($this->layoutFlagOf($event->getResult()));
    }

    public function testOnListingResultWithMissingCustomFieldSetsExtensionFalse(): void
    {
        $category = new CategoryEntity();
        $category->setId(self::CATEGORY_ID);
        $category->setCustomFields([]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->createSearchResult($category));
        $event = $this->createListingEvent(self::CATEGORY_ID);

        $this->subscriber($repository)->onListingResult($event);

        self::assertFalse($this->layoutFlagOf($event->getResult()));
    }

    /**
     * @param EntityRepository<CategoryCollection> $repository
     */
    private function subscriber(
        EntityRepository $repository,
        LayoutScope $scope = LayoutScope::Categories,
    ): ListingLayoutSubscriber {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturn($scope->value);

        return new ListingLayoutSubscriber($repository, new LayoutDecision($systemConfigService));
    }

    private function category(bool $marked): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId(self::CATEGORY_ID);
        $category->setCustomFields([LayoutDecision::CUSTOM_FIELD_KEY => $marked]);

        return $category;
    }

    private function layoutFlagOf(Struct $target): mixed
    {
        $extension = $target->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);
        self::assertInstanceOf(ArrayStruct::class, $extension);

        return $extension->get('active');
    }

    /**
     * @return array{ProductPage, Request}
     */
    private function productPageThroughSubscriber(?CategoryEntity $seoCategory, LayoutScope $scope): array
    {
        $product = new SalesChannelProductEntity();
        if ($seoCategory !== null) {
            $product->setSeoCategory($seoCategory);
        }

        $page = new ProductPage();
        $page->setProduct($product);
        $request = new Request();

        $this->subscriber($this->createMock(EntityRepository::class), $scope)
            ->onProductPageLoaded(new ProductPageLoadedEvent($page, $this->createSalesChannelContext(), $request));

        return [$page, $request];
    }

    private function storefrontRequest(?string $navigationId): Request
    {
        $request = new Request();
        $request->attributes->set(
            PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT,
            $this->createSalesChannelContext(),
        );

        if ($navigationId !== null) {
            $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, $navigationId);
        }

        return $request;
    }

    private function controllerEvent(Request $request): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): ?Response => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
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
            $request->attributes->set(self::NAVIGATION_ID_ATTRIBUTE, $navigationId);
        }

        return $this->createListingEventWithRequest($request);
    }

    private function createListingEventWithRequest(Request $request): ProductListingResultEvent
    {
        $result = new ProductListingResult(
            'product',
            0,
            new ProductCollection(),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );

        return new ProductListingResultEvent($request, $result, $this->createSalesChannelContext());
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
