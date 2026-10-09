# Umgebung

## Zweck

Die Seite **Umgebung** dient der technischen Bestandsaufnahme der zentralen WordPress-Multisite-Installation. Sie ist nur für Superadmins sichtbar.

## Inhalte

Die Ausgabe gruppiert Konfigurationswerte aus WordPress, PHP und dem Netzwerk. Dazu gehören insbesondere:

* WordPress-, PHP- und Datenbankinformationen
* aktive Plugins, Themes und Must-use-Plugins
* PHP-Limits für Speicher, Uploads und Laufzeit
* Netzwerk-Konfiguration wie Registrierung, Benutzeranlage und erlaubte Plugin-Aktivierung
* Speicherplatz pro Website und maximale Upload-Dateigröße
* Scheduler- und Cron-Informationen

Die Seite ist eine Leseansicht. Sie ändert keine Einstellungen und startet keine Analyse.

## Implementierung

`Dashboard::renderEnvironmentOverviewPage()` verwendet den `EnvironmentMetricsService`. Die Netzwerkwerte werden über `get_site_option()` gelesen; Website-spezifische Werte werden nicht durch eine netzwerkweite Schleife beim Seitenaufruf ermittelt. Die Tabelle ist damit auch bei großen Netzwerken eine reine Bestandsaufnahme der zentralen Umgebung.

## Verwendung

Nutzen Sie die Umgebung vor allem zur Fehleranalyse und zur Abstimmung von Betriebsparametern. Konfigurationswerte, die WordPress oder die Serverumgebung bestimmen, werden nicht im Multisite Manager selbst geändert.
