# Theme-Übersicht

## Zweck

Die Theme-Übersicht zeigt die im Netzwerk verfügbaren Themes und ihre Verwendung auf Websites.

## Inhalte

Die Seite fasst Theme-Metadaten, Screenshots, Versionen und die Anzahl verwendender Websites zusammen. Links führen zu Theme-Details und zu den passenden Website-Details. Nicht verwendete Themes sind dadurch schnell erkennbar.

Die Daten stammen aus den Dashboard-Metriken und werden im Hintergrund aktualisiert. Der Aufruf der Übersicht startet keinen synchronen Theme-Scan für alle Websites.

## Verwaltung

Netzwerkweite Theme-Verwaltung erfolgt weiterhin über die WordPress-Netzwerkadministration. Der Multisite Manager verlinkt auf die passenden WordPress-Funktionen und macht die Auswirkungen im Netzwerk sichtbar.

## Implementierung

`Dashboard::renderThemeOverviewPage()` verwendet die im Dashboard-Metrics-Batch erzeugte Theme-Aggregation. Pro Theme werden nur begrenzte Vorschauen der verwendenden Websites im Metrikdatensatz gehalten; umfangreiche Website-Listen entstehen erst in der Detailansicht.
