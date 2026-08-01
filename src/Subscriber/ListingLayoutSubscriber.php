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
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stellt den Layout-Schalter überall bereit, wo Twig ihn braucht.
 *
 * **Der tragende Weg ist `onController`**: einmal pro Request, bevor ein Controller läuft. Nur er
 * greift unabhängig davon, welche Vorlage gerendert wird und was Shopware aus dem Cache beantwortet.
 * Die Produkt-Box liest ausschließlich diesen Wert.
 *
 * Die drei Ereignis-Handler daneben setzen zusätzlich die Erweiterung `rcMinimalisticLayout` an
 * Seite und Listing-Ergebnis. Das ist die öffentliche Schnittstelle für andere Plugins und Vorlagen:
 *   - Jede Storefront-Seite (GenericPageLoadedEvent): trägt den Geltungsbereich „Alle Seiten" auch
 *     dorthin, wo es keine Kategorie gibt — Startseite, Produktdetailseite mit Cross-Selling, Suche.
 *   - Kategorie-Listing-Seiten (NavigationPageLoadedEvent): wertet das Zusatzfeld der Kategorie aus.
 *   - Listing-Ergebnis (ProductListingResultEvent), sofern es nicht aus dem Cache kommt.
 */
final class ListingLayoutSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcMinimalisticLayout';

    /**
     * Der Ablageort, aus dem die Vorlage tatsächlich liest.
     *
     * Warum nicht die Erweiterung: Die Produkt-Box wird in zwei sehr verschiedenen Zusammenhängen
     * gerendert. Beim Seitenaufruf steht die Seite im Twig-Kontext. Beim Nachladen über
     * `/widgets/cms/navigation/{id}` -- also bei jedem Filter- und Seitenwechsel -- gibt es dort
     * weder `page` noch das Listing-Ergebnis; beides erreicht die Vorlage nicht. Das Listing fiel
     * deshalb beim Blättern auf das Standard-Layout zurück.
     *
     * Am Request kommt die Entscheidung in jedem Zusammenhang an -- und, anders als die Erweiterung
     * am Listing-Ergebnis, auch dann, wenn Shopware die aufgelöste Kategorie-Seite aus dem Cache
     * beantwortet. Genau das tut es im Regelfall: Am 2026-07-30 gegen echte Shopdaten nachgemessen lief
     * `onListingResult` bei keinem einzigen Seitenaufruf, weil das Ergebnis samt Erweiterung aus
     * dem Cache kam.
     */
    public const REQUEST_ATTRIBUTE = 'rcMinimalisticLayoutActive';

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
            KernelEvents::CONTROLLER => 'onController',
            GenericPageLoadedEvent::class => 'onGenericPageLoaded',
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
            ProductListingResultEvent::class => 'onListingResult',
        ];
    }

    /**
     * Trifft die Entscheidung einmal pro Request, bevor irgendein Controller läuft.
     *
     * Das ist der einzige Weg, der unabhängig davon greift, welche Vorlage gerendert wird und was
     * Shopware aus dem Cache beantwortet. Die drei Ereignis-Handler darunter bleiben, weil sie die
     * Erweiterung an Seite und Listing-Ergebnis setzen -- die ist die öffentliche Schnittstelle für
     * andere Plugins. Für die Darstellung zählt der Request-Wert.
     */
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        // Admin, Store-API ohne Verkaufskanal, interne Aufrufe: hier gibt es nichts zu entscheiden.
        if (!$context instanceof SalesChannelContext) {
            return;
        }

        $salesChannelId = $context->getSalesChannelId();

        if (!$this->decision->requiresCategory($salesChannelId)) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $this->decision->isActiveFor(null, $salesChannelId));

            return;
        }

        $navigationId = $request->attributes->get('navigationId');
        if (!\is_string($navigationId) || $navigationId === '') {
            return;
        }

        $category = $this->ladeKategorie($navigationId, $context->getContext());
        if ($category === null) {
            return;
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $this->decision->isActiveFor($category, $salesChannelId));
    }

    public function onGenericPageLoaded(GenericPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        // Ohne Kategorie kann hier nur der Geltungsbereich entscheiden. Im Kategorie-Modus bleibt
        // die Seite unangetastet — dafür ist onNavigationPageLoaded zuständig.
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

    public function onListingResult(ProductListingResultEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $result = $event->getResult();

        if (!$this->decision->requiresCategory($salesChannelId)) {
            $this->addLayoutFlag($result, $this->decision->isActiveFor(null, $salesChannelId));

            return;
        }

        $navigationId = $event->getRequest()->attributes->get('navigationId');
        if (!\is_string($navigationId) || $navigationId === '') {
            return;
        }

        $category = $this->ladeKategorie($navigationId, $event->getContext());
        if ($category === null) {
            return;
        }

        $this->addLayoutFlag($result, $this->decision->isActiveFor($category, $salesChannelId));
    }

    /**
     * WICHTIG: KEIN `Criteria::addFields()` verwenden. Partial Loading lässt den EntityHydrator eine
     * `PartialEntity` (erbt von `ArrayEntity`) statt einer `CategoryEntity` zurückgeben — der
     * `instanceof`-Test schlüge dann IMMER fehl und das Layout würde beim Nachladen nie gesetzt.
     * Eine Suche über eine einzelne ID ist ohnehin günstig.
     */
    private function ladeKategorie(string $navigationId, Context $context): ?CategoryEntity
    {
        $category = $this->categoryRepository
            ->search(new Criteria([$navigationId]), $context)
            ->first();

        return $category instanceof CategoryEntity ? $category : null;
    }

    private function addLayoutFlag(Struct $target, bool $active): void
    {
        $target->addExtension(self::EXTENSION_NAME, new ArrayStruct(['active' => $active]));
    }
}
