# Plugin-Uebersicht

## Zweck

Die Plugin-Uebersicht zeigt alle im Netzwerk vorhandenen Plugins und deren Verwendung auf aktiven Websites.

## Tabelle

Die Tabelle folgt dem WordPress-Stil und enthaelt eine Mehrfachauswahl, Plugin-Name mit Kurzbeschreibung und Hover-Aktionen, Version, Herstellerinformationen sowie Statusspalten. Statusspalten zeigen die Anzahl aktiver Websites, netzwerkweite Aktivierung und automatische Aktualisierungen an.

Sortiert werden kann nach Plugin, Anzahl aktiver Websites, Herstellerinformation, netzwerkweiter Aktivierung und automatischen Aktualisierungen. Netzwerkweit aktive Plugins werden nicht vorab einsortiert; die Standardreihenfolge ist der Plugin-Name. Eine farbige linke Markierung kennzeichnet sie.

## Aktionen

Superadmins koennen einzelne oder mehrere Plugins netzwerkweit aktivieren oder deaktivieren, aktualisieren sowie automatische Aktualisierungen ein- oder ausschalten. Die Verfuegbarkeit einzelner Aktionen richtet sich nach WordPress und dem aktuellen Plugin-Status.

Hover-Aktionen verlinken zu Plugin-Details und, sofern vorhanden, zu den Einstellungen des Plugins. Ist das Plugin Check Plugin aktiv, steht fuer ein Plugin zusaetzlich ein Link zum Einzeltest bereit.

## Hinweise

Die Seite verwendet die aggregierten Dashboard-Daten fuer die Verwendung auf Websites. Plugin-Aktionen selbst werden mit den WordPress-Netzwerkaktionen und deren Nonces ausgefuehrt.

## Implementierung

`Dashboard::renderPluginOverviewPage()` verwendet `PluginUsageWidget` und `templates/plugin-overview-page.php`. Die Aggregation entsteht im Dashboard-Metrics-Batch; sie liest keine Plugin-Quellcodes. Eine Quellcodeanalyse existiert nur als explizite Detailfunktion. Aktionen verwenden die WordPress-URLs und Nonces aus der Netzwerk-Pluginverwaltung, nicht eigene ungeschuetzte Endpunkte.
