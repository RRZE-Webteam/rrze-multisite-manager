# Website-Übersicht

## Zweck

Die Website-Übersicht listet die Websites des Netzwerks mit den zuletzt bekannten Kennzahlen und technischen Statusinformationen.

## Inhalte und Filter

Die Seite bietet Statusansichten für aktive, archivierte, blockierte und zur Löschung markierte Websites sowie für technische Zustände wie Bereitstellung, fehlendes DNS oder Nichterreichbarkeit. Eine Suche filtert nach Titel oder URL.

Die Tabelle zeigt unter anderem Website, Registrierung, letzte Aktivität, Administrator, Rollen- und Inhaltszahlen, Speicherverbrauch sowie Schnellaktionen. Die genauen Spalten können je nach Ansicht und Berechtigung variieren.

## Aktionen

Von einer Zeile aus führen Links zu den Website-Details, zur WordPress-Netzwerkverwaltung und zu passenden Analyse-Seiten. Statusänderungen und netzwerkweite Verwaltungsaktionen sind Superadmins vorbehalten.

## Datenstand

Die Übersicht verwendet die Dashboard-Metriken. Sie ist daher eine aggregierte Momentaufnahme und keine Live-Abfrage jeder einzelnen Website. Den Zeitstempel und Fortschritt der Aktualisierung zeigt das Dashboard beziehungsweise Monitoring an.

## Implementierung

`Dashboard::renderSiteOverviewPage()` rendert `templates/site-overview-page.php` auf Basis von `site_overview` im Dashboard-Datensatz. Die technische Statusanreicherung wird von `MonitoringService` als Site Meta persistiert; die vollständige Liste dieser Schlüssel steht in [Laufzeitdaten und Scheduler](runtime-state.md#verfügbarkeitsmonitoring). Tabelleninteraktionen für Sortierung und Seitengröße bleiben im Browser und lösen keine serverseitige Neuberechnung der Aggregation aus.
