# Changelog

## [1.3.1] - 2026-07-30 (Layout bleibt beim Blättern und Filtern erhalten)

> **Deployment:** `php bin/console plugin:refresh && php bin/console plugin:update RcMinimalisticProductList && php bin/console cache:clear`.

### Behoben

- **Beim Filtern oder Umblättern sprang die Darstellung zurück auf die Standard-Kacheln.** In einer Kategorie mit reduziertem Layout sah die erste Seite richtig aus; sobald der Kunde einen Filter setzte oder auf Seite 2 wechselte, kamen Beschreibung, Bewertung und „In den Warenkorb" wieder — mitten im Blättern wechselte also das Aussehen. Ursache: Beim Nachladen rendert Shopware nur einen Ausschnitt der Seite, und in diesem Ausschnitt stand die Layout-Entscheidung nicht mehr zur Verfügung. Sie wird jetzt zusätzlich am Request hinterlegt und ist damit in beiden Fällen erreichbar.

### Hinzugefügt

- **Elf Tests gegen einen echten Shop** decken jetzt alle drei Wege ab, auf denen das Layout gesetzt wird: den Seitenaufruf einer Kategorie, das Nachladen beim Filtern und Blättern sowie jede beliebige Seite im Geltungsbereich „Alle Seiten". Bisher war nur das Verhalten einzelner Bausteine geprüft — nicht das Zusammenspiel mit echter Datenbank und echter Konfiguration. Genau in dieser Lücke saß der oben behobene Fehler.
- Der Abschnitt **„Plugin-Interaktion"** in der README beantwortet, was bei gleichzeitigem Einsatz von RcAbTesting passiert, und zeigt, wo eine Layout-Variante hingehört, falls das minimalistische Listing einmal in einen A/B-Test soll.
- Die README hält jetzt ausdrücklich fest, dass sich das Zusatzfeld **nicht auf Unterkategorien vererbt**. Das war bisher nirgends nachlesbar und ließ sich nur ausprobieren.

### Geändert

- Ein Pinning-Test sicherte bisher eine Abfrage ab, die nie greifen konnte (`searchResult.extensions`). Er prüft jetzt das Gegenteil — dass diese Abfrage **nicht** wieder auftaucht. Ein Test, der totes Verhalten festhält, sieht nach Absicherung aus und ist keine.
- Ein Datenlieferant in den Tests hing noch an einer Kommentar-Auszeichnung, die neuere PHPUnit-Fassungen nicht mehr auswerten; er hätte dort still keine Fälle mehr geliefert.

## [1.3.0] - 2026-07-28 (Geltungsbereich einstellbar)

> **Deployment:** `php bin/console plugin:refresh && php bin/console plugin:update RcMinimalisticProductList && php bin/console cache:clear`.

### Hinzugefügt

- **Einstellung „Geltungsbereich"** (je Verkaufskanal) mit drei Werten: **Einzelne Kategorien** (Standard, bisheriges Verhalten), **Alle Seiten** und **Aus**. „Alle Seiten" spart das Häkchen an jeder einzelnen Kategorie — und erfasst auch die Seiten, an denen man den Schalter nicht vermutet: die Startseite gehört zur Navigations-Wurzel des Verkaufskanals, Produkt-Slider und Cross-Selling folgen bisher der Kategorie der aufgerufenen Seite.
- **Der Geltungsbereich „Alle Seiten" wirkt auf jeder Storefront-Seite**, nicht nur auf Kategorielistings — also auch auf Produktdetailseiten mit Cross-Selling, in der Suche und auf der Wunschliste.

### Geändert

- **Die Entscheidung liegt jetzt in einem eigenen Dienst** (`LayoutDecision`) statt im Subscriber. Beide Einstiegspunkte — Seitenaufruf und AJAX-Nachladen — fragen dieselbe Stelle, die Logik gibt es nur einmal.
- Im Geltungsbereich „Alle Seiten" und „Aus" entfällt beim AJAX-Nachladen die Kategorie-Abfrage in der Datenbank; sie wird dort nicht gebraucht.
- Eine Seite ohne passende Kategorie trägt den Schalter jetzt mit dem Wert „aus", statt ihn wegzulassen. Für die Darstellung ändert das nichts, macht den Zustand aber sichtbar.

## [1.2.10] - 2026-07-20 (Bugfix: AJAX-Reload)

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Minimalistisches Layout bleibt beim Filtern/Sortieren/Blättern erhalten:** Der Listing-Reload (AJAX) lud die Kategorie mit `Criteria::addFields()` — das liefert eine `PartialEntity` statt einer `CategoryEntity`, wodurch der Typ-Guard immer griff und der Layout-Schalter für das Reload-Ergebnis nie gesetzt wurde. Die Kategorie fiel dadurch nach jeder Filter-/Sortier-/Pagination-Aktion auf das Standard-Layout zurück. Das Partial-Loading wurde entfernt (volle Kategorie per Single-ID-Suche).

## [1.2.9] - 2026-06-27 (Lifecycle-Härtung)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console cache:clear`.

### Behoben

- **Kategorie-Bindung ging beim Reinstall mit `keepUserData` verloren:** `install()` ruft jetzt zusätzlich `addRelations()`. Auf diesem Pfad feuert `activate()` nicht erneut, sodass das Custom-Field-Set bisher ohne Relation zur Kategorie zurückblieb.
- **Toter Service-Eintrag entfernt:** Die `CustomFieldsInstaller`-Definition in `services.xml` übergab zwei Repositories, der Konstruktor erwartet drei. Der Installer wird ausschließlich in den Lifecycle-Methoden direkt instanziiert — die Definition war nie funktionsfähig.

### Hinzugefügt

- **`update(UpdateContext)`-Lifecycle:** Gleicht bei Plugin-Updates Label-, Config- und Relations-Drift bestehender Installationen ab. Beide Aufrufe (`install()` + `addRelations()`) sind re-run-sicher.

## [1.2.8] - 2026-05-13 (Equal-Height beibehalten, Inhalt klebt oben)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console theme:compile && php bin/console cache:clear`. Theme-Compile Pflicht.

### Geändert

- **Karten einer Reihe sind wieder gleich hoch — der Inhalt bleibt aber oben.** v1.2.7 brach das Equal-Height-Stretching auf, mit der Konsequenz, dass Karten mit langem Titel andere Höhen hatten als kurze. Optisch unruhig. Korrigiert: `align-self: flex-start` und `height: auto` von der Karte entfernt, dafür `.card-body { display: flex; flex-direction: column; justify-content: flex-start }` plus konsequente `flex: 0 0 auto`-Resets auf Bild-Wrapper und `.product-info`. Damit füllt der Card-Body wie im Standard die ganze Karten-Höhe, aber Bild, Titel und Preis stapeln sich oben statt mit Lücke verteilt zu werden.
- Folge: in einer Reihe gleich hohe Karten, Lücke (falls vorhanden) sitzt unterhalb des Preises — visuell ruhiger als unterschiedlich hohe Karten.

## [1.2.7] - 2026-05-13 (Equal-Height-Stretch endgültig gebrochen)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console theme:compile && php bin/console cache:clear`. Theme-Compile Pflicht.

### Behoben

- **`align-self: flex-start` bricht das Bootstrap-Equal-Height-Stretching.** v1.2.6 reduzierte die Card-Höhe von 484 px auf 418 px, aber die Parent-Row (`.cms-listing-row { display: flex }`) hat per Default `align-items: stretch` — daher streckte sie alle Cards einer Reihe auf die Höhe der höchsten. Mit `align-self: flex-start` an der Card selbst ignoriert sie das Parent-Stretch und bleibt content-Höhe.
- **`.card-body { flex: 0 0 auto !important; height: auto !important }`.** Eine cascadierende Storefront-Regel holte die Body-Höhe zwischenzeitlich wieder zurück. Mit `!important` sind die Werte jetzt definitiv.
- **`.product-info { margin-top: 0 }`** plus Reset von `.product-price-info`, `.product-price-unit`, `.product-cheapest-price`, `.product-price` auf `min-height: 0; margin: 0`. Diese Margins/Min-Heights summierten sich zu den verbleibenden ~60 px Leerraum.

### Folge

- Karten haben jetzt content-Höhe. Cards mit längerem Titel sind etwas höher als kurze — bewusste Konsequenz des kompakten Layouts.
- Standard-Listing-Karten in anderen Kategorien (ohne aktiviertes Custom Field) bleiben unangetastet — alle Regeln stehen unter `.box-rc-minimalistic`.

## [1.2.6] - 2026-05-13 (Root-Cause: Storefront-Default-Fixhöhen abgeschaltet)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console theme:compile && php bin/console cache:clear`. Theme-Compile Pflicht.

### Behoben

- **Karten sind jetzt wirklich kompakt — Root-Cause adressiert.** Die Computed-Style-Analyse auf der Live-Karte (484 px hoch, `.card-body` 482 px) zeigte: Shopwares `vendor/shopware/storefront/Resources/app/storefront/src/scss/component/_product-box.scss` setzt für das normale Listing harte Vorgaben, damit alle Karten optisch gleich wirken:
  - `.product-box { height: 100% }`
  - `.product-image-wrapper { flex-grow: 1; flex-basis: 180px; height: 200px; margin-bottom: 15px }` — **der eigentliche Schuldige**: `flex-grow: 1` streckt den Image-Wrapper und schiebt damit `.product-info` ans Karten-Ende.
  - `.product-name { height: 2.75rem }`, `.product-price-unit { height: 36px }`, `.product-cheapest-price { margin-bottom: 32px }`.
- Die neue SCSS hebt diese Vorgaben gezielt nur für `.box-rc-minimalistic` auf: `flex-grow: 0` auf dem Image-Wrapper, `height: auto` auf den Fixed-Height-Elementen, kleinere Margins.
- Resultat: Karte hat content-Höhe, kein Spread mehr zwischen Bild und Preis. Standard-Listing-Karten bleiben unangetastet.

## [1.2.5] - 2026-05-13 (SCSS gegen Storefront-Default-Flex-Spread gehärtet)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console theme:compile && php bin/console cache:clear`. Theme-Compile Pflicht.

### Behoben

- **Lücke zwischen Titel und Preis verschwindet jetzt zuverlässig.** v1.2.4 lieferte SCSS zwar korrekt aus (im kompilierten `all.css` 6 Treffer für `.box-rc-minimalistic`), die Regeln waren aber nicht stark genug, um Shopwares Storefront-Default zu überstimmen. Die Standard-`.product-info` wird mit `display: flex; justify-content: space-between` aufgezogen — daher klebte der Preis am unteren Karten-Ende und ließ oberhalb einen Leerraum.
  - Neu: `.product-info { display: block }` setzt die Storefront-Flex-Logik zurück. Title, Variant-Slot und Preis-Info stapeln sich jetzt als normale Block-Elemente direkt unter dem Bild.
  - Neu: `.product-box.box-rc-minimalistic { height: auto; min-height: 0 }` bricht die Bootstrap-Equal-Height des Listing-Grids für die minimalistische Karte. Karten haben damit die Höhe ihres Inhalts — keine erzwungene Streckung mehr.
  - `.card-body { flex: 0 0 auto }` hebt das Bootstrap-Default `flex: 1 1 auto` auf, damit der Body innerhalb der Karte ebenfalls nicht stretcht.

## [1.2.4] - 2026-05-13 (Kompakte Card ohne Streck-Lücke)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console theme:compile && php bin/console cache:clear`. Theme-Compile diesmal **Pflicht** — SCSS hinzugekommen.

### Hinzugefügt

- **`Resources/app/storefront/src/scss/base.scss`**: schmale Stilregel für `.product-box.box-rc-minimalistic`. Hebt das Bootstrap-Default `flex: 1 1 auto` auf den direkten Card-Body-Kindern auf, damit zwischen Titel und Preis keine vertikale Streck-Lücke entsteht. Plus `gap: 0.5rem` und reduziertes Padding für ein wirklich kompaktes Layout.
- Twig-Templates und Plugin-Logik unverändert — reines Storefront-Styling.

## [1.2.3] - 2026-05-13 (Custom-Field-Detection robust gegen Variable-Scope)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console cache:clear`. Kein Theme-Compile nötig — nur PHP- und Twig-Logik-Änderung.

### Behoben

- **Layout-Switch greift jetzt auch dort, wo `searchResult` im Twig-Scope nicht durchgereicht wird.** Live-Diagnose auf edelstahl-trummer.de: DB-Custom-Field gesetzt (`"rc_show_minimalistic_productlist": 1`), Subscriber im EventDispatcher registriert — DOM rendert trotzdem `box-standard`. Das Twig-Template fand `searchResult.extensions.rcMinimalisticLayout` an dieser Aufruf-Stelle nicht. Ursache war im konkreten Storefront-Aufruf-Pfad nicht reproduzierbar, aber der Symptom-Fix ist robust:
  - **Zweiter Subscriber-Pfad** `onNavigationPageLoaded(NavigationPageLoadedEvent)` hängt die Extension direkt an die Page (`page.extensions.rcMinimalisticLayout`). Diese Variable ist im Storefront-Twig-Scope global verfügbar — unabhängig davon, ob `searchResult` an die Card weitergegeben wurde. Liest `customFields` direkt aus `$page->getCategory()` ohne separaten DB-Lookup.
  - **`box.html.twig` prüft beide Pfade per `or`-Kette:** zuerst `page.extensions.rcMinimalisticLayout.active`, dann `searchResult.extensions.rcMinimalisticLayout.active`. Mindestens einer der beiden greift in jedem Listing-Kontext (Kategorie-Listing, AJAX-Reload, CMS-Element-Listing).
- **Magic-Strings konsolidiert.** Extension-Name und Custom-Field-Key liegen ab jetzt als `ListingLayoutSubscriber::EXTENSION_NAME` und `::CUSTOM_FIELD_KEY` als Konstanten vor. Doppelpfad-Subscriber teilen die Detection-Logik per privater `extractActiveFlag(CategoryEntity)`.

### Tests

- 4 neue Tests für `onNavigationPageLoaded`: aktives Custom Field, inaktives, Scalar-1 (so persistiert Shopware Admin-Checkboxen), keine Kategorie.
- `BoxTemplateContractTest` aktualisiert: prüft jetzt beide Twig-Pfade.
- Gesamt 27 Tests grün.

## [1.2.2] - 2026-05-13 (Listing-Override greift wieder)

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console theme:compile && php bin/console cache:clear`

### Behoben

- **Minimalistisches Listing greift jetzt im Storefront.** Der bisherige `box.html.twig`-Override definierte nur den Block `component_product_box` ohne `sw_extends` und nutzte eine `layout`-Variable, die nirgendwo gesetzt wurde — Effekt: Twig fiel auf das Standard-Storefront-Template zurück, das Custom Field hatte keine Wirkung im Listing. Fix folgt jetzt dem Shopware-eigenen Muster aus `box-minimal.html.twig`:
  - **`box.html.twig`** extendet `@Storefront/storefront/component/product/card/box.html.twig` und überschreibt `component_product_box_include`. Wenn die Extension `rcMinimalisticLayout.active` true ist (oder das Navigations-Custom-Field gesetzt), wird `layout = 'rc-minimalistic'` gesetzt und `parent()` aufgerufen. Der Standard-Dispatcher inkludiert dann automatisch das neue Sub-Template.
  - **`box-rc-minimalistic.html.twig` (neu)** extendet `@Storefront/storefront/component/product/card/box-standard.html.twig` und blendet `component_product_box_description`, `component_product_box_variant_characteristics`, `component_product_box_action` und `component_product_box_rating` durch leere Blocks aus. Badge, Bild, Titel und Preis bleiben.
- **`box-minimalistic.html.twig` entfernt.** Die Datei war eine Insel — nirgendwo per `sw_include` oder `sw_extends` referenziert. Sie setzte zwar `layout = 'minimalistic'`, aber im falschen Scope. Ersatzlos gestrichen.

### Tests

- Neuer `BoxTemplateContractTest` mit 7 Pinning-Tests (sw_extends-Inheritance, Layout-Switch, leere Sub-Blocks, Entfernung der Insel-Datei). Verhindert dass eine spätere Refactoring-Welle die Inheritance-Kette wieder zerreißt.

### Hinweise für Integrationen

- **Theme-Compile Pflicht.** Wer eigene SCSS-Regeln gegen `.box-minimalistic` geschrieben hat (war seit v1.0.0 nie standardmäßig im DOM, aber denkbar): die CSS-Klasse heißt jetzt `box-rc-minimalistic`. Umbenennung in eigenen Theme-Overrides nötig.
- **Bestehende Twig-Overrides auf `component_product_box`** in Kunden-Themes greifen weiter normal — das Sub-Template `box-rc-minimalistic.html.twig` erbt von `box-standard.html.twig`, also greifen alle Standard-Block-Overrides auch im minimalistischen Layout.

## [1.2.1] - 2026-05-12

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console cache:clear`

### Behoben

- **`plugin:install` nach `--keep-user-data`-uninstall läuft durch.** Früher kollidierte `customFieldSetRepository->upsert()` mit `Duplicate entry rc_show_minimalistic_productlist` weil der Installer keine Field-IDs auf bestehende Felder mappte.
- **`plugin:activate` nach `plugin:deactivate` ist idempotent.** Früher schlug `addRelations()` mit `Duplicate entry <set_id>-category for uniq.custom_field_set_relation.entity_name` fehl. Jetzt wird die bestehende Relation-ID per `addAssociation('relations')` mitgeladen und im Upsert-Payload verwendet.
- **Dritte Repository-Dependency:** `custom_field.repository` im `CustomFieldsInstaller`-Konstruktor (Plugin-Klasse + Tests).

### Tests

- 3 neue Unit-Tests für Idempotenz-Pfade (Set+Field-ID-Enrichment, Relation-ID-Enrichment, Greenfield)

## [1.2.0] - 2026-05-12

> **Deployment:** `php bin/console plugin:update RcMinimalisticProductList && php bin/console cache:clear`

### Geändert
- `uninstall()` respektiert `keepUserData()`: das Custom-Field-Set `rc_show_minimalistic_productlist_category_bool` wird bei vollständiger Deinstallation entfernt, bei "Daten behalten" nicht
- Listing-Subscriber lädt nur noch das benötigte `customFields`-Feld der Kategorie (Partial Loading) — leichte Hot-Path-Entlastung im Storefront

### Tests
- 2 neue Unit-Tests für `CustomFieldsInstaller::uninstall()` (Löschpfad und No-op-Pfad)

## [1.1.0] - 2026-03-31

> **Deployment:** `php bin/console cache:clear`

### Geändert
- Shopware 6.8 Kompatibilität hinzugefügt
- Plugin-Label auf Kurzform vereinheitlicht
- Null-Check für navigation.active.customFields im Preis-Template

## [1.0.0] - 2026-03-28

> **Deployment:** `php bin/console plugin:install --activate RcMinimalisticProductList && php bin/console cache:clear`

### Hinzugefügt
- Minimalistisches Produktlisten-Layout pro Kategorie aktivierbar
- Custom Field `rc_show_minimalistic_productlist` auf Kategorien
- Reduzierte Produktbox: nur Bild, Titel, Preis
- AJAX-Listing-Kompatibilität (Filterung, Sortierung)
