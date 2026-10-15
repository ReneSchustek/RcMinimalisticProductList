<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Subscriber;

use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stellt den Layout-Schalter überall bereit, wo Twig ihn braucht.
 *
 * Die Produkt-Box liest zuerst das Request-Attribut `rcMinimalisticLayoutActive` und fällt auf die
 * Erweiterung `rcMinimalisticLayout` an der Seite zurück. Das Attribut setzen `onController` (einmal je
 * Request, vor jedem Controller und unabhängig vom Cache) und `onProductPageLoaded`.
 *
 * Die vier Seiten- und Listing-Handler setzen außerdem die Erweiterung, die öffentliche Schnittstelle für
 * andere Plugins und Vorlagen:
 * `onGenericPageLoaded` trägt den Geltungsbereich „Alle Seiten“ auf Seiten ohne Kategorie (Startseite,
 * Suche), `onNavigationPageLoaded` wertet das Zusatzfeld der Kategorie aus, `onProductPageLoaded` das der
 * SEO-Kategorie des Artikels, und `onListingResult` hängt sie an das Listing-Ergebnis, sofern Shopware es
 * nicht aus dem Cache beantwortet.
 */
final class ListingLayoutSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcMinimalisticLayout';

    /**
     * Der Ablageort, aus dem die Vorlage zuerst liest.
     *
     * Die Produkt-Box wird in zwei Zusammenhängen gerendert. Beim Seitenaufruf steht die Seite im
     * Twig-Kontext; beim Nachladen über `/widgets/cms/navigation/{id}`, also bei jedem Filter- und
     * Seitenwechsel, gibt es dort weder `page` noch das Listing-Ergebnis. Stünde die Entscheidung nur an
     * der Seite, fiele das Listing beim Blättern auf Standard-Kacheln zurück.
     *
     * Am Request kommt sie in beiden Zusammenhängen an, auch wenn Shopware die aufgelöste Kategorie-Seite
     * samt Listing-Ergebnis aus dem Cache beantwortet und `onListingResult` deshalb gar nicht läuft.
     */
    public const REQUEST_ATTRIBUTE = 'rcMinimalisticLayoutActive';

    /**
     * Route-Parameter, unter dem Shopware die Kategorie eines Listing-Aufrufs ablegt.
     */
    private const NAVIGATION_ID_ATTRIBUTE = 'navigationId';

    /**
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly LayoutDecision $decision,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Direkt nach der Kontextauflösung des Kerns (Rang -10). Liefe der Zuhörer davor, fände er
            // keinen Verkaufskanal-Kontext und entschiede nichts; beim Nachladen gibt es keine Seite, auf
            // die die Vorlage zurückfallen könnte, und Blättern zeigte Standard-Kacheln.
            KernelEvents::CONTROLLER => [
                'onController',
                KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_CONTEXT_RESOLVE_POST,
            ],
            GenericPageLoadedEvent::class => 'onGenericPageLoaded',
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
            ProductListingResultEvent::class => 'onListingResult',
        ];
    }

    /**
     * Legt die Entscheidung am Request ab, bevor ein Controller läuft.
     *
     * Ohne Entscheidung (Kategorie-Modus ohne oder mit unbekannter Kategorie) bleibt das Attribut leer,
     * und die Vorlage fällt auf die Erweiterung an der Seite zurück.
     */
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        // Administration, Store-API ohne Verkaufskanal, interne Aufrufe: Dort rendert keine Produkt-Box.
        if (!$context instanceof SalesChannelContext) {
            return;
        }

        $active = $this->decideForRequest($request, $context);
        if ($active !== null) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $active);
        }
    }

    public function onGenericPageLoaded(GenericPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        // Ohne Kategorie kann hier nur der Geltungsbereich entscheiden. Im Kategorie-Modus bleibt die Seite
        // unangetastet, sonst überschriebe ein false das Ergebnis von onNavigationPageLoaded.
        if ($this->decision->requiresCategory($salesChannelId)) {
            return;
        }

        $this->addLayoutFlag($event->getPage(), $this->decision->isActiveFor(null, $salesChannelId));
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $category = $page->getCategory();

        $this->addLayoutFlag($page, $this->decision->isActiveFor(
            $category instanceof CategoryEntity ? $category : null,
            $event->getSalesChannelContext()->getSalesChannelId(),
        ));
    }

    /**
     * Querverkauf und Produktschieber der Produktseite zeigen dieselben Kacheln wie das Listing der
     * SEO-Kategorie des Artikels. Die Produktseite trägt keine `navigationId`, `onController` entscheidet
     * dort im Kategorie-Modus also nichts.
     */
    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        // Die SEO-Kategorie zählt nur im Kategorie-Modus; sonst entscheidet allein der Geltungsbereich.
        $category = $this->decision->requiresCategory($salesChannelId) ? $this->seoCategoryOf($event) : null;
        $active = $this->decision->isActiveFor($category, $salesChannelId);

        $event->getRequest()->attributes->set(self::REQUEST_ATTRIBUTE, $active);
        $this->addLayoutFlag($event->getPage(), $active);
    }

    public function onListingResult(ProductListingResultEvent $event): void
    {
        $active = $this->decideForRequest($event->getRequest(), $event->getSalesChannelContext());
        if ($active !== null) {
            $this->addLayoutFlag($event->getResult(), $active);
        }
    }

    /**
     * Entscheidung für einen Listing-Aufruf, dessen Kategorie als Route-Parameter am Request steht.
     *
     * @return bool|null null, wenn der Kategorie-Modus gilt und keine bekannte Kategorie am Request steht
     */
    private function decideForRequest(Request $request, SalesChannelContext $context): ?bool
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->decision->requiresCategory($salesChannelId)) {
            return $this->decision->isActiveFor(null, $salesChannelId);
        }

        $navigationId = $request->attributes->get(self::NAVIGATION_ID_ATTRIBUTE);
        if (!\is_string($navigationId) || $navigationId === '') {
            return null;
        }

        $category = $this->loadCategory($navigationId, $context->getContext());
        if ($category === null) {
            return null;
        }

        return $this->decision->isActiveFor($category, $salesChannelId);
    }

    /**
     * Lädt die ganze Kategorie, nicht nur ihre Zusatzfelder.
     *
     * Mit `Criteria::addFields()` gäbe der Hydrator eine `PartialEntity` statt einer `CategoryEntity`
     * zurück; der `instanceof`-Test schlüge dann immer fehl, und beim Nachladen käme nie das Layout. Eine
     * Suche über eine einzelne ID ist ohnehin günstig.
     */
    private function loadCategory(string $navigationId, Context $context): ?CategoryEntity
    {
        $category = $this->categoryRepository
            ->search(new Criteria([$navigationId]), $context)
            ->getEntities()
            ->first();

        return $category instanceof CategoryEntity ? $category : null;
    }

    private function seoCategoryOf(ProductPageLoadedEvent $event): ?CategoryEntity
    {
        return $event->getPage()->getProduct()->getSeoCategory();
    }

    private function addLayoutFlag(Struct $target, bool $active): void
    {
        $target->addExtension(self::EXTENSION_NAME, new ArrayStruct(['active' => $active]));
    }
}
