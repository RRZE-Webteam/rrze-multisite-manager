# Theme-Uebersicht

## Zweck

Die Theme-Uebersicht zeigt die im Netzwerk verfuegbaren Themes und ihre Verwendung auf Websites.

## Inhalte

Die Seite fasst Theme-Metadaten, Screenshots, Versionen und die Anzahl verwendender Websites zusammen. Links fuehren zu Theme-Details und zu den passenden Website-Details. Nicht verwendete Themes sind dadurch schnell erkennbar.

Die Daten stammen aus den Dashboard-Metriken und werden im Hintergrund aktualisiert. Der Aufruf der Uebersicht startet keinen synchronen Theme-Scan fuer alle Websites.

## Verwaltung

Netzwerkweite Theme-Verwaltung erfolgt weiterhin ueber die WordPress-Netzwerkadministration. Der Multisite Manager verlinkt auf die passenden WordPress-Funktionen und macht die Auswirkungen im Netzwerk sichtbar.

## Implementierung

`Dashboard::renderThemeOverviewPage()` verwendet die im Dashboard-Metrics-Batch erzeugte Theme-Aggregation. Pro Theme werden nur begrenzte Vorschauen der verwendenden Websites im Metrikdatensatz gehalten; umfangreiche Website-Listen entstehen erst in der Detailansicht.
