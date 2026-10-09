# Dashboard

## Zweck

Das Dashboard ist die zentrale, konfigurierbare Uebersicht des Multisite Manager. Es fasst die zuletzt berechneten Netzwerkmetriken in Widgets zusammen und vermeidet dabei eine Vollanalyse bei jedem Seitenaufruf.

## Inhalte

Je nach gewaehlter Ansicht stehen unter anderem Widgets fuer Website-Status, Betriebsstatus, Speicherverbrauch, aktuelle Probleme, neue Monitoring-Hinweise, Theme- und Plugin-Nutzung sowie inaktive oder zuletzt geaenderte Websites zur Verfuegung.

Die Editor-Nutzung orientiert sich an RRZE Settings. Ist der Block Editor netzwerkweit als Standardeditor gesetzt, erscheint ein Hinweis mit Link zu den RRZE-Settings statt einer Tortengrafik, weil alle Websites denselben Editor verwenden.

## Bedienung

* Widgets koennen innerhalb einer Ansicht verschoben werden. Die Reihenfolge wird pro Benutzer und Ansicht im Browser gespeichert.
* Ansichten und die darin sichtbaren Widgets werden von Superadmins unter **Einstellungen > Ansichten** verwaltet.
* Tabellen-Widgets sortieren und paginieren nur die sichtbaren Eintraege im Browser.
* Die Zeit des letzten Metrics-Laufs und der Fortschritt einer laufenden Aktualisierung sind auf der Seite sichtbar.

## Datenaktualisierung

Dashboard-Metriken werden zentral auf der Hauptwebsite des Netzwerks im Hintergrund berechnet. Ein Durchlauf verarbeitet Websites in Batches und schreibt erst nach Abschluss einen vollstaendigen Datensatz in den Netzwerk-Cache. Waerend einer Aktualisierung bleibt der letzte vollstaendige Datensatz sichtbar.

Bei einer geaenderten Berechnungslogik erkennt der Cache seine Versionsaenderung und plant eine erneute Batch-Berechnung ein. Das vermeidet einen zeitaufwaendigen synchronen Lauf im Admin-Request.

## Implementierung

Einstieg ist `Dashboard::renderDashboardPage()`. Die Daten stammen aus `MetricsService::getDashboardData()`; die Fassade delegiert an `MetricsImplementationService`. Die Widget-Implementierungen liegen in `includes/Widgets/`, die Templates in `templates/widgets/`.

Die Persistenz des Dashboard-Datensatzes, die Cron-Hooks und die Batch-Sperren sind in [Laufzeitdaten und Scheduler](runtime-state.md#zentrale-dashboard-metriken) aufgefuehrt. Die individuelle Widget-Reihenfolge wird zusaetzlich als User Meta `rrze_msm_widget_orders` gespeichert; die Definition der Netzwerkansichten liegt in der Network Option `rrze_multisite_manager_dashboard_views`.
