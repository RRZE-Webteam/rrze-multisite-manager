# Website-Uebersicht

## Zweck

Die Website-Uebersicht listet die Websites des Netzwerks mit den zuletzt bekannten Kennzahlen und technischen Statusinformationen.

## Inhalte und Filter

Die Seite bietet Statusansichten fuer aktive, archivierte, blockierte und zur Loeschung markierte Websites sowie fuer technische Zustaende wie Bereitstellung, fehlendes DNS oder Nichterreichbarkeit. Eine Suche filtert nach Titel oder URL.

Die Tabelle zeigt unter anderem Website, Registrierung, letzte Aktivitaet, Administrator, Rollen- und Inhaltszahlen, Speicherverbrauch sowie Schnellaktionen. Die genauen Spalten koennen je nach Ansicht und Berechtigung variieren.

## Aktionen

Von einer Zeile aus fuehren Links zu den Website-Details, zur WordPress-Netzwerkverwaltung und zu passenden Analyse-Seiten. Statusaenderungen und netzwerkweite Verwaltungsaktionen sind Superadmins vorbehalten.

## Datenstand

Die Uebersicht verwendet die Dashboard-Metriken. Sie ist daher eine aggregierte Momentaufnahme und keine Live-Abfrage jeder einzelnen Website. Den Zeitstempel und Fortschritt der Aktualisierung zeigt das Dashboard beziehungsweise Monitoring an.

## Implementierung

`Dashboard::renderSiteOverviewPage()` rendert `templates/site-overview-page.php` auf Basis von `site_overview` im Dashboard-Datensatz. Die technische Statusanreicherung wird von `MonitoringService` als Site Meta persistiert; die vollstaendige Liste dieser Schluessel steht in [Laufzeitdaten und Scheduler](runtime-state.md#verfuegbarkeitsmonitoring). Tabelleninteraktionen fuer Sortierung und Seitengroesse bleiben im Browser und loesen keine serverseitige Neuberechnung der Aggregation aus.
