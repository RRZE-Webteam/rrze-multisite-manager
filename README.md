[![Aktuelle Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-multisite-manager/main?label=Version)](https://github.com/RRZE-Webteam/rrze-multisite-manager) [![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-multisite-manager?label=Release+Version)](https://github.com/rrze-webteam/rrze-multisite-manager/releases/) [![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-multisite-manager)](https://github.com/RRZE-Webteam/rrze-multisite-manager) [![GitHub issues](https://img.shields.io/github/issues/RRZE-Webteam/rrze-multisite-manager)](https://github.com/RRZE-Webteam/rrze-multisite-manager/issues)

# RRZE Multisite Manager

Verwaltungs- und Analysewerkzeug fuer WordPress-Multisite-Netzwerke mit Fokus auf Statusverwaltung, Monitoring, Metriken sowie Website-, Plugin- und Theme-Uebersichten.

## Contributors

* RRZE-Webteam, https://www.rrze.fau.de

## Copyright

GNU General Public License (GPL) Version 3

## Dokumentation

Die oeffentliche Dokumentation und Endanwender-Hinweise liegen unter:

* https://www.wp.rrze.fau.de

## Feedback

* Issues und Feedback: https://github.com/RRZE-Webteam/rrze-multisite-manager/issues
* Kontakt: webmaster@rrze.fau.de

## Requirements

* WordPress ab 6.9.4
* PHP ab 8.3
* WordPress Multisite
* Node.js/npm nur fuer lokale Entwicklungs- und Build-Schritte

## Installation

* Plugin in das Verzeichnis `wp-content/plugins/rrze-multisite-manager/` legen
* Im Netzwerk aktivieren
* Bei Entwicklungsarbeiten generierte Dateien nicht direkt bearbeiten, sondern aus `src/` nach `build/` neu erzeugen

## Configuration

Das Plugin stellt einen Multisite Manager fuer Superadmins und freigegebene Websupport-Benutzer bereit. Einstellungen betreffen unter anderem:

* Dashboard-Ansichten und Widget-Sortierung
* Monitoring-Intervalle und Monitoring-Parameter
* Metrics-Intervalle und Batch-Verhalten
* Status- und Betriebsinformationen fuer Websites

## Translations

Die Uebersetzungen liegen im Verzeichnis `languages/`.

Empfohlener Ablauf fuer Entwickler:

* POT-Datei neu erzeugen, z.B. mit WP-CLI:
  `wp i18n make-pot . languages/rrze-multisite-manager.pot --domain=rrze-multisite-manager --exclude=node_modules,build,.git`
* Vorhandene `.po`-Dateien aktualisieren und daraus die zugehoerigen `.mo`-Dateien kompilieren
* Alternativ kann fuer die Pflege von `.po`- und `.mo`-Dateien auch Loco Translate verwendet werden

Wichtig:

* Im Code stehen die Original-Strings in englischer Sprache
* Deutsch und weitere Sprachen werden ueber die Sprachdateien bereitgestellt
* Generierte Sprachdateien sollten bei String-Aenderungen zusammen mit dem Code aktualisiert werden

## Multisite behavior

Dieses Plugin ist als Netzwerk-Plugin fuer WordPress Multisite ausgelegt.

* Netzwerkweite Kennzahlen, Monitoring-Zustaende und View-Konfigurationen werden als Network Options gespeichert
* Website-spezifische Detaildaten, Metadaten und Statusinformationen werden pro Site oder als Site Meta gespeichert
* Das Plugin verwendet WordPress-Multisite-APIs wie `get_sites()`, `switch_to_blog()`, `get_site_option()` und `update_blog_status()`
* Einige Analysen und Monitoring-Prozesse laufen im Batch-Verfahren, damit grosse Netzwerke nicht in einem einzelnen Request komplett verarbeitet werden muessen


## Services

Verwendete externe Kommunikation:

* Provider: die jeweils in der Multisite registrierten Website-Domains selbst
* Purpose: DNS- und HTTP-Erreichbarkeitspruefung im Monitoring
* Transmitted data: normale HTTP-HEAD- oder HTTP-GET-Anfragen an die jeweilige Website-URL; dabei fallen technisch uebliche Request-Metadaten des Servers an
* Authentication method: keine
* Timeout behavior: die Monitoring-Pruefungen nutzen die WordPress-HTTP-API mit definiertem Timeout-Verhalten im Plugin-Code
* Failure behavior: Nichterreichbarkeit oder DNS-/HTTP-Fehler fuehren zu Monitoring-Statusdaten, aber sollen den WordPress-Adminbereich nicht unbenutzbar machen
* Privacy implications: bei der Verfuegbarkeitspruefung werden Anfragen an die Ziel-Domain gesendet; dadurch verlassen Request-Daten je nach Zielsystem die lokale Infrastruktur
* Whether data leaves FAU infrastructure: moeglich, falls eine im Netzwerk registrierte Domain oder Zieladresse ausserhalb der FAU-Infrastruktur liegt
* Required API scopes: keine

## Cookies and browser storage

Das Plugin verwendet keine `localStorage`- oder `sessionStorage`-Eintraege.

Verwendete Cookies:

* `rrze_msm_color_mode`
  Speichert die Auswahl zwischen Light Mode und Dark Mode im Adminbereich.
  Laufzeit: 365 Tage
* `rrze_msm_widget_order_<view>`
  Speichert die individuelle Widget-Reihenfolge pro Dashboard-Ansicht im Browser.
  Laufzeit: 365 Tage

Die Cookie-Werte enthalten keine Zugangsdaten oder geheimen Informationen.

## Data storage

Je nach Funktion nutzt das Plugin unter anderem:

* Network Options fuer globale Einstellungen, Monitoring-Laufdaten, View-Konfigurationen und Dashboard-Metriken
* Site Meta fuer websitebezogene Status- und Monitoring-Informationen
* Site Transients fuer gecachte Detail- und Analyseergebnisse
* regulaere Site Options der jeweiligen Websites fuer ausgelesene oder verwaltete Site-Einstellungen

Das Plugin fuehrt ausserdem Analysen ueber Plugins, Themes, Inhalte, Scheduler-Daten, Transients, Uploads und Speicherbelegung durch, ohne dafuer ein eigenes Tabellenschema anzulegen.

## Hooks and APIs

Interne bzw. technische Integrationen:

* WordPress `admin-ajax` fuer Suchfunktionen und Batch-Aktionen im Adminbereich
* WordPress Cron fuer Monitoring- und Metrics-Prozesse
* Logging-Integration ueber `do_action( 'rrze.log.error', ... )`, sofern eine passende Logging-Umgebung vorhanden ist

Oeffentliche REST-Endpunkte stellt das Plugin derzeit nicht bereit.
