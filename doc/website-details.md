# Website-Details

## Zweck

Die Seite **Website-Details** bündelt technische und inhaltliche Informationen zu einer einzelnen Website. Sie ist der Ausgangspunkt für die Detailanalyse und für weiterführende WordPress-Verwaltungsseiten.

## Bereiche

Abhängig von der Website und Berechtigung stehen Bereiche für Übersicht, Optionen, Cron-Einträge, Inhaltstypen, Bildgrößen, Transients, Medien und Debugdaten bereit. Sensible Optionen werden für nicht privilegierte Benutzer ausgeblendet.

Die Seite verlinkt unter anderem zur Website-Verwaltung, zur Mediathek, zu Theme-Einstellungen, Menüs, Customizer und - falls passend - zum Site Editor.

## Verwaltungsfunktionen

Superadmins können Statusinformationen pflegen und erhalten Zugriff auf weitergehende Netzwerkaktionen. Änderungen an Optionen, das Löschen von Daten oder eine Statusänderung sind geschützt und werden nicht als Teil einer bloßen Detailansicht ausgeführt.

## Caching

Detaildaten werden pro Website zwischengespeichert, damit umfangreiche Optionen oder Medieninformationen nicht bei jedem Aufruf erneut gesammelt werden. Änderungen an relevanten WordPress-Daten invalidieren die passenden Detail- und Dashboard-Caches.

## Implementierung

Die Seite wird durch `Dashboard::renderSiteDetailsPage()` und `templates/site-details-page.php` bereitgestellt. `SiteDetailMetricsService` sammelt die einzelnen Abschnitte; `MetricsCacheService` erzeugt die Site-Transient-Schlüssel `rrze_msm_site_details_<detail-version>_<site-version>_<hash>` und `rrze_msm_site_detail_section_<format-version>_<detail-version>_<site-version>_<hash>`. Die globale Detailversion liegt in `rrze_msm_detail_cache_version`, die Version einer Website in Site Meta `rrze_msm_site_detail_cache_version`.
