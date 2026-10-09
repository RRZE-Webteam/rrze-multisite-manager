[![Aktuelle Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-multisite-manager/main?label=Version)](https://github.com/RRZE-Webteam/rrze-multisite-manager) [![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-multisite-manager?label=Release+Version)](https://github.com/rrze-webteam/rrze-multisite-manager/releases/) [![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-multisite-manager)](https://github.com/RRZE-Webteam/rrze-multisite-manager) [![GitHub issues](https://img.shields.io/github/issues/RRZE-Webteam/rrze-multisite-manager)](https://github.com/RRZE-Webteam/rrze-multisite-manager/issues)

# RRZE Multisite Manager

Verwaltungs- und Analysewerkzeug fuer WordPress-Multisite-Netzwerke. Das Plugin stellt zentrale Uebersichten, technische Monitoring-Daten, zeitgesteuerte Analysen sowie Verwaltungsfunktionen fuer Websites, Plugins und Themes bereit.

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

## Entwicklerdokumentation

Die technische Dokumentation zu Menue-Seiten, Klassen, Caches, Cron-Hooks, Optionen, Transients, Cookies und AJAX-Aktionen liegt in [doc/README.md](doc/README.md). Allgemeine Entwicklungs-, Build-, Test- und Uebersetzungshinweise stehen in [doc/development.md](doc/development.md). Die zentrale Referenz fuer Laufzeitdaten und Scheduler ist [doc/runtime-state.md](doc/runtime-state.md).
