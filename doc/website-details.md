# Website-Details

## Zweck

Die Seite **Website-Details** buendelt technische und inhaltliche Informationen zu einer einzelnen Website. Sie ist der Ausgangspunkt fuer die Detailanalyse und fuer weiterfuehrende WordPress-Verwaltungsseiten.

## Bereiche

Abhaengig von der Website und Berechtigung stehen Bereiche fuer Uebersicht, Optionen, Cron-Eintraege, Inhaltstypen, Bildgroessen, Transients, Medien und Debugdaten bereit. Sensible Optionen werden fuer nicht privilegierte Benutzer ausgeblendet.

Die Seite verlinkt unter anderem zur Website-Verwaltung, zur Mediathek, zu Theme-Einstellungen, Menues, Customizer und - falls passend - zum Site Editor.

## Verwaltungsfunktionen

Superadmins koennen Statusinformationen pflegen und erhalten Zugriff auf weitergehende Netzwerkaktionen. Aenderungen an Optionen, das Loeschen von Daten oder eine Statusaenderung sind geschuetzt und werden nicht als Teil einer blossen Detailansicht ausgefuehrt.

## Caching

Detaildaten werden pro Website zwischengespeichert, damit umfangreiche Optionen oder Medieninformationen nicht bei jedem Aufruf erneut gesammelt werden. Aenderungen an relevanten WordPress-Daten invalidieren die passenden Detail- und Dashboard-Caches.

## Implementierung

Die Seite wird durch `Dashboard::renderSiteDetailsPage()` und `templates/site-details-page.php` bereitgestellt. `SiteDetailMetricsService` sammelt die einzelnen Abschnitte; `MetricsCacheService` erzeugt die Site-Transient-Schluessel `rrze_msm_site_details_<detail-version>_<site-version>_<hash>` und `rrze_msm_site_detail_section_<format-version>_<detail-version>_<site-version>_<hash>`. Die globale Detailversion liegt in `rrze_msm_detail_cache_version`, die Version einer Website in Site Meta `rrze_msm_site_detail_cache_version`.
