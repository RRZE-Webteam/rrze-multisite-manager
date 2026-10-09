# Dokumentation des RRZE Multisite Manager

Diese Dokumentation beschreibt die sichtbaren Seiten des Menues **Multisite Manager** aus Entwicklerperspektive. Sie richtet sich an Personen, die den Betrieb, die Weiterentwicklung oder die Fehlersuche in einem grossen WordPress-Multisite-Netzwerk verantworten. Bedienhinweise sind nur enthalten, soweit sie den technischen Ablauf erklaeren.

Die gemeinsame Referenz fuer Persistenz, Cron-Hooks, Batches und Sperren ist [Laufzeitdaten und Scheduler](runtime-state.md).

Allgemeine Vorgaben fuer Entwicklung, Builds, Tests und Uebersetzungen stehen in [Entwicklung](development.md).

## Zugriffsmodell

Der Manager kann fuer berechtigte Websupport-Benutzer sichtbar sein. Netzwerkweite Verwaltungsfunktionen, die Umgebung, Monitoring und Einstellungen bleiben Superadmins vorbehalten. Einzelne Analyse-Seiten sind ausserdem im Dashboard der jeweiligen Website erreichbar, wenn die lokale Berechtigung vorhanden ist.

## Menueseiten

| Seite | Beschreibung | Zugriff |
| --- | --- | --- |
| [Dashboard](dashboard.md) | Konfigurierbare Kennzahlen und Widgets | Berechtigte Manager-Benutzer |
| [Umgebung](environment.md) | Server-, WordPress- und Netzwerk-Konfiguration | Superadmin |
| [Website-Uebersicht](website-overview.md) | Filterbare Liste und Status aller Websites | Berechtigte Manager-Benutzer |
| [Website-Details](website-details.md) | Detaildaten und Verwaltungsaktionen einer Website | Berechtigte Manager-Benutzer; sensible Funktionen nur Superadmin |
| [Plugin-Uebersicht](plugin-overview.md) | Netzwerkweite Plugin-Nutzung und Verwaltung | Berechtigte Manager-Benutzer; Aktionen nur Superadmin |
| [Plugin-Details](plugin-details.md) | Details und Verwendung eines Plugins | Berechtigte Manager-Benutzer |
| [Theme-Uebersicht](theme-overview.md) | Netzwerkweite Theme-Nutzung | Berechtigte Manager-Benutzer |
| [Theme-Details](theme-details.md) | Details und Verwendung eines Themes | Berechtigte Manager-Benutzer |
| [Speicheranalyse](storage-analysis.md) | Upload-, Medien- und Metadatenanalyse einer Website | Berechtigte lokale Benutzer bzw. Superadmin |
| [Shortcodes und Bloecke](shortcodes-and-blocks.md) | Verwendete Shortcodes und Bloecke je Website | Berechtigte lokale Benutzer bzw. Superadmin |
| [Monitoring](monitoring.md) | Hintergrundprozesse, Zeitplaene und Wartung | Superadmin |
| [Einstellungen](settings.md) | Globale Konfiguration, Ansichten und Logging | Superadmin |

## Betriebshinweise

Dashboard-Metriken, Monitoring und geplante Analysen arbeiten in begrenzten Batches. Ein Aufruf der Oberflaeche startet keinen synchronen Scan des gesamten Netzwerks. Fuer eine zeitnahe Ausfuehrung muss WordPress-Cron verlaesslich ausgeloest werden; bei grossen Installationen empfiehlt sich ein serverseitig angestossener Cron-Aufruf.

Die Seiten speichern Analyse- und Statusdaten, nicht fertig gerenderte Adminseiten. Nach einer Aenderung relevanter Einstellungen oder nach einem Plugin-Update kann eine Hintergrundaktualisierung erforderlich sein, bevor aggregierte Kennzahlen den neuen Stand zeigen.
