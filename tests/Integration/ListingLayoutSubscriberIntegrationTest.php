<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMinimalisticProductList\Service\LayoutDecision;
use Ruhrcoder\RcMinimalisticProductList\Subscriber\ListingLayoutSubscriber;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
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
 * Prüft die beiden Wege, auf denen das Listing-Layout gesetzt wird, gegen eine echte Datenbank
 * und einen echten Kernel.
 *
 * Warum das nötig ist: Der Hotfix v1.2.3 hat einen zweiten Subscriber-Pfad eingeführt, und der
 * AJAX-Pfad lädt seine Kategorie selbst aus der Datenbank. Genau dort steckte schon einmal ein
 * Fehler, den kein Unit-Test finden konnte — mit `Criteria::addFields()` liefert der Hydrator eine
 * `PartialEntity` statt einer `CategoryEntity`, der Typ-Guard schlug fehl und die Erweiterung wurde
 * beim Nachladen von Filtern nie gesetzt. Ein Mock hätte weiterhin eine `CategoryEntity` geliefert.
 */
#[CoversClass(ListingLayoutSubscriber::class)]
#[CoversClass(LayoutDecision::class)]
final class ListingLayoutSubscriberIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const CUSTOM_FIELD = LayoutDecision::CUSTOM_FIELD_KEY;

    private const CONFIG_KEY = 'RcMinimalisticProductList.config.scope';

    /**
     * Was: Kategorie mit gesetztem Merkmal, geladen über den AJAX-Pfad.
     * Warum: Das ist der Weg, der beim Nachladen von Filtern und Seiten läuft — und der Pfad, der
     *        schon einmal still ausgefallen ist.
     * Erwartet: Die Erweiterung sitzt am Ergebnis und meldet `active`.
     */
    public function testAjaxPfadSetztDieErweiterungFürEineKategorieMitMerkmal(): void
    {
        $kategorieId = $this->kategorieAnlegen(true);

        $ergebnis = $this->listingErgebnisDurchSubscriber($kategorieId);

        $erweiterung = $ergebnis->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);
        static::assertInstanceOf(ArrayStruct::class, $erweiterung);
        static::assertTrue($erweiterung->get('active'));
    }

    /**
     * Was: Kategorie ohne das Merkmal.
     * Warum: Gegenprobe — ohne sie würde ein Test, der immer `active` liefert, ebenfalls grün sein.
     * Erwartet: Erweiterung gesetzt, aber nicht aktiv.
     */
    public function testOhneMerkmalBleibtDasStandardLayout(): void
    {
        $kategorieId = $this->kategorieAnlegen(false);

        $erweiterung = $this->listingErgebnisDurchSubscriber($kategorieId)->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $erweiterung);
        static::assertFalse($erweiterung->get('active'));
    }

    /**
     * Was: Eine Unterkategorie, deren Elternkategorie das Merkmal trägt.
     * Warum: Ob sich das Merkmal vererbt, stand bisher nirgends fest — dieser Test
     *        hält die Antwort fest, statt sie im Kopf zu behalten.
     * Erwartet: **keine Vererbung.** Das Merkmal wirkt nur an der Kategorie, an der es gesetzt ist.
     */
    public function testDasMerkmalVererbtSichNichtAufUnterkategorien(): void
    {
        $elternId = $this->kategorieAnlegen(true);
        $kindId = $this->kategorieAnlegen(false, $elternId);

        $erweiterung = $this->listingErgebnisDurchSubscriber($kindId)->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $erweiterung);
        static::assertFalse(
            $erweiterung->get('active'),
            'Wenn sich das Merkmal doch vererben soll, ist das eine Entscheidung -- und dieser Test der Ort dafür.'
        );
    }

    /**
     * Was: Geltungsbereich „alle Seiten".
     * Warum: In diesem Modus darf der AJAX-Pfad die Datenbank gar nicht erst befragen.
     * Erwartet: aktiv, ohne dass eine Kategorie im Spiel ist.
     */
    public function testGeltungsbereichAlleSeitenBrauchtKeineKategorie(): void
    {
        $vorher = $this->geltungsbereichSetzen('all');

        try {
            $ergebnis = $this->listingErgebnisDurchSubscriber(null);
            $erweiterung = $ergebnis->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

            static::assertInstanceOf(ArrayStruct::class, $erweiterung);
            static::assertTrue($erweiterung->get('active'));
        } finally {
            $this->geltungsbereichSetzen($vorher);
        }
    }

    /**
     * Was: Geltungsbereich „aus".
     * Warum: Der Schalter muss auch dann greifen, wenn eine Kategorie das Merkmal trägt.
     * Erwartet: nicht aktiv.
     */
    public function testGeltungsbereichAusSchlaegtDasMerkmal(): void
    {
        $kategorieId = $this->kategorieAnlegen(true);
        $vorher = $this->geltungsbereichSetzen('off');

        try {
            $erweiterung = $this->listingErgebnisDurchSubscriber($kategorieId)->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

            static::assertInstanceOf(ArrayStruct::class, $erweiterung);
            static::assertFalse($erweiterung->get('active'));
        } finally {
            $this->geltungsbereichSetzen($vorher);
        }
    }


    /**
     * Was: Der Seitenaufruf-Pfad (`NavigationPageLoadedEvent`) mit einer Kategorie, die das Merkmal
     *      trägt.
     * Warum: Das ist der Pfad des ersten Seitenaufrufs -- der AJAX-Pfad greift erst beim Nachladen.
     *        Beide Pfade gehören geprüft, weil der Hotfix v1.2.3 sie auseinandergezogen hat.
     * Erwartet: Die Erweiterung sitzt an der Seite und meldet `active`.
     */
    public function testNavigationsSeitenPfadSetztDieErweiterung(): void
    {
        $kategorieId = $this->kategorieAnlegen(true);

        $seite = $this->navigationsSeiteDurchSubscriber($kategorieId);

        $erweiterung = $seite->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);
        static::assertInstanceOf(ArrayStruct::class, $erweiterung);
        static::assertTrue($erweiterung->get('active'));
    }

    /**
     * Was: Derselbe Pfad mit einer Kategorie ohne Merkmal.
     * Warum: Gegenprobe zum vorigen Test.
     * Erwartet: gesetzt, aber nicht aktiv.
     */
    public function testNavigationsSeitenPfadOhneMerkmalBleibtStandard(): void
    {
        $erweiterung = $this->navigationsSeiteDurchSubscriber($this->kategorieAnlegen(false))
            ->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);

        static::assertInstanceOf(ArrayStruct::class, $erweiterung);
        static::assertFalse($erweiterung->get('active'));
    }

    /**
     * Was: Eine beliebige Storefront-Seite ohne Kategorie im Geltungsbereich "Alle Seiten".
     * Warum: Startseite, Suche und Produktdetailseite mit Cross-Selling haben keine Kategorie. Ohne
     *        diesen Pfad bliebe "Alle Seiten" dort wirkungslos -- genau das soll er verhindern.
     * Erwartet: aktiv.
     */
    public function testJedeSeiteBekommtDenGeltungsbereichAlleSeiten(): void
    {
        $vorher = $this->geltungsbereichSetzen('all');

        try {
            $seite = new Page();

            $this->subscriber()->onGenericPageLoaded(
                new GenericPageLoadedEvent($seite, $this->salesChannelContext(), new Request())
            );

            $erweiterung = $seite->getExtension(ListingLayoutSubscriber::EXTENSION_NAME);
            static::assertInstanceOf(ArrayStruct::class, $erweiterung);
            static::assertTrue($erweiterung->get('active'));
        } finally {
            $this->geltungsbereichSetzen($vorher);
        }
    }

    /**
     * Was: Dieselbe Seite im Standard-Geltungsbereich "Einzelne Kategorien".
     * Warum: Hier darf der Seiten-Pfad nichts anfassen -- zuständig ist dann allein der
     *        Kategorie-Pfad. Setzte er hier `false`, überschriebe er dessen Ergebnis.
     * Erwartet: gar keine Erweiterung.
     */
    public function testImKategorieModusLaesstDerSeitenPfadDieSeiteInRuhe(): void
    {
        $seite = new Page();

        $this->subscriber()->onGenericPageLoaded(
            new GenericPageLoadedEvent($seite, $this->salesChannelContext(), new Request())
        );

        static::assertNull($seite->getExtension(ListingLayoutSubscriber::EXTENSION_NAME));
    }


    /**
     * Was: `onController` legt die Entscheidung für eine Kategorie mit Merkmal am Request ab.
     * Warum: **Das ist der Weg, über den die Vorlage sie überhaupt erfährt.** Beim Nachladen über
     *        `/widgets/cms/navigation/{id}` steht in der Produkt-Box weder die Seite noch das
     *        Listing-Ergebnis im Twig-Kontext, und `onListingResult` läuft dort gar nicht erst --
     *        Shopware beantwortet die aufgelöste Kategorie-Seite aus dem Cache. Am 2026-07-30 auf
     *        gegen echte Shopdaten nachgemessen.
     * Erwartet: Der Request trägt danach `true`.
     */
    public function testDerControllerWegLegtDieEntscheidungAmRequestAb(): void
    {
        $request = $this->requestMitNavigation($this->kategorieAnlegen(true));

        $this->subscriber()->onController($this->controllerEreignis($request));

        static::assertTrue(
            $request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE),
            'Ohne diesen Wert blättert der Kunde aus dem minimalistischen Layout heraus.'
        );
    }

    /**
     * Was: Gegenprobe ohne Merkmal.
     * Warum: Der Wert muss ausdrücklich `false` sein und nicht einfach fehlen.
     * Erwartet: `false` am Request, nicht `null`.
     */
    public function testOhneMerkmalStehtAmRequestAusdruecklichFalse(): void
    {
        $request = $this->requestMitNavigation($this->kategorieAnlegen(false));

        $this->subscriber()->onController($this->controllerEreignis($request));

        static::assertFalse($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    /**
     * Was: Geltungsbereich „Alle Seiten" ohne jede Kategorie am Request.
     * Warum: In diesem Modus darf der Weg die Datenbank nicht brauchen -- er läuft bei **jedem**
     *        Storefront-Request, nicht nur auf Listings.
     * Erwartet: `true`, obwohl keine `navigationId` gesetzt ist.
     */
    public function testGeltungsbereichAlleSeitenBrauchtAmControllerKeineKategorie(): void
    {
        $vorher = $this->geltungsbereichSetzen('all');

        try {
            $request = $this->requestMitNavigation(null);

            $this->subscriber()->onController($this->controllerEreignis($request));

            static::assertTrue($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
        } finally {
            $this->geltungsbereichSetzen($vorher);
        }
    }

    /**
     * Was: Ein Request ohne Verkaufskanal-Kontext -- so sehen Admin- und interne Aufrufe aus.
     * Warum: Der Weg hängt am Kernel und läuft damit auch dort. Er darf dort nichts anfassen und
     *        vor allem nicht mit einem Fehler aussteigen.
     * Erwartet: kein Wert am Request, keine Ausnahme.
     */
    public function testOhneVerkaufskanalKontextPassiertNichts(): void
    {
        $request = new Request();
        $request->attributes->set('navigationId', $this->kategorieAnlegen(true));

        $this->subscriber()->onController($this->controllerEreignis($request));

        static::assertNull($request->attributes->get(ListingLayoutSubscriber::REQUEST_ATTRIBUTE));
    }

    private function kategorieAnlegen(bool $merkmalAktiv, ?string $elternId = null): string
    {
        $id = Uuid::randomHex();

        $daten = [
            'id' => $id,
            'name' => 'Testkategorie ' . $id,
            'customFields' => [self::CUSTOM_FIELD => $merkmalAktiv],
        ];

        if ($elternId !== null) {
            $daten['parentId'] = $elternId;
        }

        $this->kategorieRepository()->create([$daten], Context::createDefaultContext());

        return $id;
    }

    /**
     * Setzt den Geltungsbereich und gibt den vorherigen Wert zurück, damit der Test ihn im
     * `finally` wiederherstellen kann. Ohne das hängt das Ergebnis nachfolgender Tests davon ab,
     * in welcher Reihenfolge sie laufen.
     */
    private function geltungsbereichSetzen(?string $wert): ?string
    {
        $dienst = $this->systemConfigService();
        $vorher = $dienst->get(self::CONFIG_KEY);

        if ($wert === null) {
            $dienst->delete(self::CONFIG_KEY);
        } else {
            $dienst->set(self::CONFIG_KEY, $wert);
        }

        return \is_string($vorher) ? $vorher : null;
    }

    /**
     * Baut ein leeres Listing-Ergebnis, schickt es durch den Subscriber und gibt es zurück.
     * Die Kategorie kommt -- wie im Betrieb -- über `navigationId` am Request, damit der
     * Datenbank-Weg des Subscribers wirklich durchlaufen wird.
     */
    private function listingErgebnisDurchSubscriber(?string $navigationId): ProductListingResult
    {
        $context = $this->salesChannelContext();
        $ergebnis = new ProductListingResult(
            'product',
            0,
            new \Shopware\Core\Content\Product\ProductCollection([]),
            null,
            new Criteria(),
            $context->getContext(),
        );

        $request = new Request();
        if ($navigationId !== null) {
            $request->attributes->set('navigationId', $navigationId);
        }

        $this->subscriber()->onListingResult(new ProductListingResultEvent($request, $ergebnis, $context));

        return $ergebnis;
    }

    /**
     * Schickt eine Kategorie-Seite durch den Seitenaufruf-Pfad und gibt sie zurück.
     */
    private function navigationsSeiteDurchSubscriber(string $kategorieId): NavigationPage
    {
        $context = $this->salesChannelContext();

        $kategorie = $this->kategorieRepository()
            ->search(new Criteria([$kategorieId]), $context->getContext())
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $kategorie);

        $seite = new NavigationPage();
        $seite->setCategory($kategorie);

        $this->subscriber()->onNavigationPageLoaded(
            new NavigationPageLoadedEvent($seite, $context, new Request())
        );

        return $seite;
    }

    /**
     * Der Subscriber wird selbst gebaut statt aus dem Container geholt: Symfony macht Dienste
     * standardmäßig privat, und ein Subscriber wird nur über den Event-Dispatcher angesprochen --
     * im Test-Container ist er deshalb nicht abrufbar. Seine beiden Abhängigkeiten kommen dagegen
     * echt aus dem Container, samt echter Datenbank und echter Konfiguration.
     */
    private function subscriber(): ListingLayoutSubscriber
    {
        return new ListingLayoutSubscriber(
            $this->kategorieRepository(),
            new LayoutDecision($this->systemConfigService()),
        );
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $fabrik = $this->getContainer()
            ->get(\Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory::class);
        static::assertInstanceOf(\Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory::class, $fabrik);

        return $fabrik->create(Uuid::randomHex(), $this->ersterVerkaufskanal());
    }

    private function ersterVerkaufskanal(): string
    {
        $verbindung = $this->getContainer()->get(\Doctrine\DBAL\Connection::class);
        static::assertInstanceOf(\Doctrine\DBAL\Connection::class, $verbindung);

        $id = $verbindung->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel WHERE active = 1 LIMIT 1');

        static::assertIsString($id, 'Kein aktiver Verkaufskanal in der Testdatenbank.');

        return $id;
    }

    /**
     * Baut einen Request, wie ihn die Storefront liefert: mit aufgelöstem Verkaufskanal-Kontext und
     * -- sofern vorhanden -- der Kategorie als Route-Parameter.
     */
    private function requestMitNavigation(?string $navigationId): Request
    {
        $request = new Request();
        $request->attributes->set(
            PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT,
            $this->salesChannelContext(),
        );

        if ($navigationId !== null) {
            $request->attributes->set('navigationId', $navigationId);
        }

        return $request;
    }

    private function controllerEreignis(Request $request): ControllerEvent
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

    /** @return EntityRepository<\Shopware\Core\Content\Category\CategoryCollection> */
    private function kategorieRepository(): EntityRepository
    {
        $repository = $this->getContainer()->get('category.repository');
        static::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /**
     * Der Konfigurationsdienst kommt untypisiert aus dem Container.
     */
    private function systemConfigService(): SystemConfigService
    {
        $dienst = $this->getContainer()->get(SystemConfigService::class);
        static::assertInstanceOf(SystemConfigService::class, $dienst);

        return $dienst;
    }

}
