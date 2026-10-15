# RcMinimalisticProductList

Shopware-6-Plugin, das ein minimalistisches Produktlisten-Layout einschaltet, für den ganzen Shop oder für einzelne Kategorien.

---

## Was das Plugin macht

Shopware-Produktlistings haben ein festes Layout, das sich nur über Theme-Änderungen umstellen lässt, und dann gilt es global. Dieses Plugin blendet in den Produktkacheln Beschreibung, Varianten-Merkmale, Bewertung und Aktions-Button aus, sodass nur Bild, Titel und Preis bleiben.

Wo das gilt, bestimmt die Plugin-Einstellung **Geltungsbereich** (je Verkaufskanal):

| Geltungsbereich | Wirkung |
|---|---|
| **Einzelne Kategorien** (Standard) | Nur Kategorien, an denen das Zusatzfeld `rc_show_minimalistic_productlist` gesetzt ist. Die Startseite gehört dabei zur Navigations-Wurzel des Verkaufskanals. Wer sie umstellen will, setzt das Feld dort. |
| **Alle Seiten** | Überall, ohne Zusatzfeld: Kategorielistings, Produkt-Slider auf der Startseite, Cross-Selling auf Produktseiten, Suche. |
| **Aus** | Das Plugin ändert nichts. |

Auf der **Produktseite** folgen Cross-Selling und Produkt-Slider im Geltungsbereich „Einzelne Kategorien“ der SEO-Kategorie des Artikels: Trägt sie das Zusatzfeld, zeigen auch diese Kacheln nur Bild, Titel und Preis.

Technisch legt das Plugin die Entscheidung an zwei Stellen ab. Vor dem Seitenaufbau steht sie als Request-Attribut `rcMinimalisticLayoutActive` am Request; zusätzlich hängt sie als Extension `rcMinimalisticLayout` (Feld `active`) an der Seite und am Listing-Ergebnis. Die Produktkachel fragt zuerst den Request und fällt auf die Extension der Seite zurück; bei `active = true` lädt sie das reduzierte Layout. Das greift auch für Produktkacheln in CMS-Elementen derselben Seite.

Funktioniert sowohl beim initialen Seitenaufruf als auch beim AJAX-Reload bei Filterung, Sortierung und Blättern.

---

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

---

## Installation

Das Plugin gehört als Ordner `custom/plugins/RcMinimalisticProductList` in die Shopware-Installation. Übertragen wird er von Hand per FTP in dieses Verzeichnis, ein Composer-Paket gibt es nicht. Danach registriert Shopware ihn über die folgenden Befehle:

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcMinimalisticProductList
php bin/console cache:clear
```

Als Archiv geht es auch ohne FTP: In der Administration unter **Erweiterungen → Meine Erweiterungen → Erweiterung hochladen** nimmt Shopware eine ZIP-Datei entgegen und legt sie selbst an die richtige Stelle; auf der Konsole tut `plugin:zip-import` dasselbe. Danach folgen dieselben Befehle wie oben. Wer das Archiv unter macOS packt, entfernt vorher den Ordner `__MACOSX`, sonst weist Shopware die Datei ab.

### Update

Aktualisierungen laufen denselben Weg: den neuen Ordner per FTP über den alten legen, danach

```bash
php bin/console plugin:refresh
php bin/console plugin:update RcMinimalisticProductList
php bin/console cache:clear
```

Ob zusätzlich `theme:compile` nötig ist, steht im Deployment-Hinweis der jeweiligen Fassung im [CHANGELOG](CHANGELOG.md). Eine Aktualisierung von selbst gibt es nicht: Ohne Composer-Paket und ohne Shopware-Store bleibt sie Sache des Betreibers. Vor einem Sprung über eine Hauptversion gehört ein Datenbank-Abzug dazu.

---

## Konfiguration

**Geltungsbereich:** Admin unter **Erweiterungen → Meine Erweiterungen → RcMinimalisticProductList → Konfiguration**, je Verkaufskanal einstellbar. Standard ist „Einzelne Kategorien".

**Einzelne Kategorie:** Admin unter **Kategorien → [Kategorie] → Individuelle Felder**:

| Feld | Beschreibung |
|------|-------------|
| Eine minimalistische Produktliste anzeigen | Aktiviert das reduzierte Layout für diese Kategorie (nur im Geltungsbereich „Einzelne Kategorien") |

Für die Startseite ist die zuständige Kategorie die Navigations-Wurzel des Verkaufskanals (oft „Hauptkategorien").

Das Zusatzfeld **vererbt sich nicht** auf Unterkategorien: Es wirkt genau an der Kategorie, an der es gesetzt ist. Wer eine ganze Baumebene reduziert darstellen will, setzt es an jeder Kategorie oder stellt den Geltungsbereich auf „Alle Seiten".

---

## Plugin-Interaktion

### RcAbTesting

Beide Plugins können dieselbe Listing-Seite betreffen, geraten sich aber nicht ins Gehege, weil sie
in verschiedene Richtungen arbeiten:

- **Dieses Plugin schreibt.** Es legt die Entscheidung an zwei Stellen ab: als Erweiterung
  `rcMinimalisticLayout` mit dem Feld `active` an Seite und Listing-Ergebnis, und zusätzlich als
  Request-Attribut `rcMinimalisticLayoutActive`. Das Template liest den Request zuerst, denn beim
  Nachladen über `/widgets/cms/navigation/{id}` (jeder Filter- und Seitenwechsel) steht die Seite
  dort nämlich nicht zur Verfügung.
- **RcAbTesting liest nicht mit und schreibt nichts.** Es stellt Varianten über die
  Twig-Funktionen `ab_variant()`, `ab_variant_config()` und `ab_switch()` bereit. Das Template
  fragt die Variante ab, kein Subscriber setzt ein Layout um. Es gibt heute auch keinen
  Frontend-Schalter für Listings; die vorhandenen betreffen Checkout-Darstellung und
  Versandkosten-Hinweis.

Daraus folgt: **Ein Subscriber-Wettlauf ist ausgeschlossen**, eine Prioritäts-Absprache erübrigt
sich. Soll das minimalistische Layout einmal als A/B-Variante laufen, gehört die Entscheidung ins
Template, nicht in einen zweiten Subscriber, etwa so:

```twig
{% set minimalistic = app.request.attributes.get('rcMinimalisticLayoutActive')
    ?? (page.extensions.rcMinimalisticLayout.active ?? false) %}
{% if ab_variant('listing-layout') is not null %}
    {% set minimalistic = ab_variant_config('listing_layout') == 'minimal' %}
{% endif %}
```

Die Variante gewinnt damit bewusst gegen das Zusatzfeld. Sonst wären Kategorien mit gesetztem
Feld dauerhaft aus dem Test heraus und das Ergebnis wäre verzerrt.

**Nicht abgedeckt:** ein Integration-Test für dieses Zusammenspiel. Er braucht auf beiden Seiten
etwas, das es noch nicht gibt: einen Listing-Schalter in RcAbTesting und die Template-Abfrage hier.
Sobald der Schalter existiert, ist dieser Abschnitt der Ort, an dem nachgezogen wird.

---

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)

## Lizenz

MIT, siehe [LICENSE](LICENSE).
