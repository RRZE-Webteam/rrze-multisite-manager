# Laufzeitdaten und Scheduler

## Namensräume

Die technischen Schlüssel des Plugins folgen drei Konventionen:

| Präfix | Verwendung |
| --- | --- |
| `rrze_msm_` | Network Options, Site Meta, Site Options, Transients, Sperren und Cron-Hooks der Laufzeitlogik |
| `rrze_msm_run_` / `rrze_msm_refresh_` | ausführende oder fortsetzende Cron-Hooks |
| `rrze_multisite_manager_` | Dashboard-Cache sowie Admin-Post- und Network-Admin-Aktionen |

Die globale Plugin-Konfiguration liegt als Network Option unter `rrze-multisite-manager`. Die verwendeten Hook-Namen und Scheduling-Keys werden in `includes/Config.php` zentral definiert. Änderungen an diesen Namen erfordern immer eine Migrations- beziehungsweise Bereinigungsstrategie für bereits existierende Cron-Einträge.

## Zentrale Dashboard-Metriken

| Element | Name |
| --- | --- |
| wiederkehrender Hook | `rrze_msm_refresh_dashboard_metrics` |
| Schedule-Key | `rrze_msm_dashboard_metrics_cycle` |
| Fortsetzungsargument | `['rrze_msm_dashboard_metrics_batch' => true]` |
| Aktivierung | Network Option `rrze_msm_dashboard_metrics_scheduling_enabled` |
| Cache | Network Option `rrze_multisite_manager_dashboard_metrics_v7_<network-id>` |
| Batch-Fortschritt | `rrze_msm_dashboard_metrics_batch_offset`, `rrze_msm_dashboard_metrics_batch_total`, `rrze_msm_dashboard_metrics_batch_state` |
| Sperre | `rrze_msm_dashboard_metrics_refresh_lock_state`, TTL 900 Sekunden |
| Dirty-Marker | `rrze_msm_dashboard_metrics_dirty` |

`MetricsImplementationService` erzeugt den Datensatz, `DashboardMetricsBatchService` verarbeitet die Website-Schleife und `DashboardMetricsRefreshService` verwaltet Cache, Batchzustand und Sperre. Der Cache besitzt eine eigene Datenversionsnummer; sie wird beim Lesen geprüft und plant bei einer Änderung einen neuen begrenzten Lauf. Alte Transient-Cache-Versionen mit dem Präfix `rrze_multisite_manager_dashboard_metrics_v1_` bis `_v6_` werden nur aus Kompatibilitätsgründen bereinigt.

## Verfügbarkeitsmonitoring

| Element | Name |
| --- | --- |
| Hook | `rrze_msm_check_site_availability` |
| Standard-Schedule-Key | `rrze_msm_every_six_hours` |
| Fortsetzungsargument | `['rrze_msm_monitoring_batch' => true]` |
| Aktivierung | `rrze_msm_monitoring_scheduling_enabled` |
| Laufzustand | `rrze_msm_monitoring_run_state` |
| Batch-Fortschritt | `rrze_msm_monitoring_batch_offset`, `rrze_msm_monitoring_batch_total` |
| Protokoll | `rrze_msm_monitoring_run_log` |
| Sperre | `rrze_msm_monitoring_lock_state`, Fallback-Transient `rrze_msm_monitoring_lock`, TTL 900 Sekunden |

`MonitoringService` registriert den Hook auf `init` nur im Cron-Kontext der Hauptwebsite. Ein Fortsetzungsevent wird mit fünf Sekunden Mindestabstand eingeplant. HTTP-Prüfungen verwenden die WordPress-HTTP-API; die statische Voreinstellung beträgt fünf Websites pro Monitoring-Batch, 45 Sekunden Request-Budget, vier Sekunden HTTP-Timeout und zwei Sekunden Fallback-Timeout.

PHPs native DNS-Funktionen (`dns_get_record()` und `checkdnsrr()`) haben keinen pro Aufruf steuerbaren Timeout. Deshalb beginnt das Plugin keine DNS-Prüfung mehr, wenn weniger als fünf Sekunden des Batch-Zeitbudgets verbleiben. Der Status wird dann als `unknown` gespeichert; dies zählt nicht als DNS-Fehler und wird im nächsten regulären Lauf erneut geprüft. Diese Schutzmaßnahme verhindert keine Blockierung eines bereits gestarteten DNS-Aufrufs.

### Serverbetrieb: DNS-Timeouts

Für produktive große Netzwerke muss der DNS-Resolver des PHP-/Cron-Hosts begrenzt und erreichbar sein. Empfohlen ist ein lokaler, gecachter Resolver wie Unbound oder systemd-resolved. Seine Upstream-Resolver sollten kurze, betrieblich abgestimmte Zeitlimits und eine begrenzte Zahl von Wiederholungen verwenden; bei glibc-basierten Hosts kann dies beispielsweise über `options timeout:1 attempts:1` in der Resolver-Konfiguration erfolgen. Die konkrete Konfiguration ist betriebssystem-, Container- und Resolver-abhängig und muss mit dem Infrastrukturteam abgestimmt werden, da sie für alle Prozesse des Hosts gilt.

Wenn eine garantierte Obergrenze pro DNS-Abfrage erforderlich ist, darf die Auflösung nicht im WordPress/PHP-Prozess stattfinden. Sie muss dann durch einen separat überwachten Worker oder einen eigenen DNS-over-HTTPS-Endpunkt des Betreibers mit festem HTTP-Timeout erfolgen. Öffentliche DNS-over-HTTPS-Dienste sind für das Monitoring nicht vorgesehen, weil dabei alle überwachten Domains an Dritte übermittelt würden.

Pro Website werden die folgenden Site-Meta-Schlüssel geschrieben: `rrze_msm_operational_status`, `rrze_msm_operational_status_source`, `rrze_msm_previous_operational_status`, `rrze_msm_operational_status_changed_at`, `rrze_msm_dns_status`, `rrze_msm_dns_status_detail`, `rrze_msm_http_status`, `rrze_msm_http_status_detail`, `rrze_msm_http_status_code`, `rrze_msm_last_availability_check`, `rrze_msm_last_dns_ok_at`, `rrze_msm_last_http_ok_at`, `rrze_msm_last_dns_error_at`, `rrze_msm_last_http_error_at`, `rrze_msm_dns_failure_count`, `rrze_msm_http_failure_count`, `rrze_msm_monitoring_note` und `rrze_msm_monitoring_history`.

## Speicheranalyse

| Element | Name |
| --- | --- |
| Einzelanalyse-Hook | `rrze_msm_run_site_storage_analysis` |
| Sammelbatch-Hook | `rrze_msm_run_site_storage_analysis_batch` |
| Frequenzmigration | `rrze_msm_reschedule_storage_analysis_frequency` |
| veralteter Entfernen-Hook | `rrze_msm_remove_storage_analysis_tasks` |
| Batch-Fortsetzungsargument | `['rrze_msm_storage_analysis_batch' => true]` |
| Site-Sperre | `rrze_msm_storage_analysis_lock_<site-id>` |
| Sammelbatch-Sperre | `rrze_msm_storage_analysis_batch_lock` |
| Netzwerkweite Ausführungsslots | `rrze_msm_storage_analysis_run_slots` |
| Sperre für Slot-Aktualisierungen | `rrze_msm_storage_analysis_run_slots_lock` |

Die Zuweisung und Fortschrittsdaten sind Network Options, darunter `rrze_msm_storage_analysis_assignments`, `rrze_msm_storage_analysis_batch_site_ids`, `rrze_msm_storage_analysis_batch_last_site_id`, `rrze_msm_storage_analysis_schedule_initialization_state` und `rrze_msm_storage_analysis_frequency_reschedule_state`. Das dauerhafte Ergebnis liegt als nicht autoloadende Site Option in `rrze_msm_site_storage_analysis_result`; die Kurzmetadaten liegen in `rrze_msm_site_storage_analysis_result_meta`. Das Ergebnis wird bei mehr als 2 MiB serialisierter Daten gekürzt.

Fortsetzbare Prozessdaten sind Network Transients mit den Mustern `rrze_msm_site_storage_analysis_v3_<cache-version>_<site-id>`, `rrze_msm_site_storage_analysis_base_state_<cache-version>_<site-id>`, `rrze_msm_site_storage_analysis_orphan_state_<cache-version>_<site-id>`, `rrze_msm_site_storage_attachment_index_v2_<cache-version>_<site-id>` und `rrze_msm_site_storage_attachment_index_bucket_v1_<cache-version>_<site-id>_<hex-bucket>`. Die Buckets verhindern einen einzelnen unbeschränkt großen Medienindex-Transient. Die Option `monitoring_storage_analysis_max_concurrent_runs` begrenzt netzwerkweit gleichzeitig laufende Speicheranalysen; der Standardwert ist 10. Nicht erhaltene Slots werden als einmalige Cron-Ereignisse erneut versucht. Verwaiste Slots laufen nach der konfigurierten maximalen Laufzeit zuzüglich einer Minute ab.

## Shortcode- und Blockanalyse

| Element | Name |
| --- | --- |
| Einzelanalyse-Hook | `rrze_msm_run_shortcode_block_analysis_site` |
| Sammelbatch-Hook | `rrze_msm_run_shortcode_block_analysis_batch` |
| Legacy-Hook | `rrze_msm_run_shortcode_block_analysis` |
| Frequenzmigration | `rrze_msm_reschedule_shortcode_block_analysis_frequency` |
| veralteter Entfernen-Hook | `rrze_msm_remove_shortcode_block_analysis_tasks` |
| Batch-Fortsetzungsargument | `['rrze_msm_shortcode_block_batch' => true]` |
| Site-Sperre | `rrze_msm_shortcode_block_analysis_lock_<site-id>` |

Die Schedulerdaten liegen als Network Options unter anderem in `rrze_msm_shortcode_block_analysis_assignments`, `rrze_msm_shortcode_block_analysis_batch_site_ids`, `rrze_msm_shortcode_block_analysis_batch_offset`, `rrze_msm_shortcode_block_analysis_batch_lock` und `rrze_msm_shortcode_block_analysis_schedule_initialization_state`. Ergebnis, Status und Prozesszustand liegen pro Website in den Site Options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status` und `rrze_msm_shortcode_block_analysis_state`. Die maximale Ergebniszahl ist in `Config::getShortcodeBlockResultEntryLimit()` begrenzt.

## Admin-AJAX und Wartung

Planungsinitialisierung, Aufgabenentfernung und die Bereinigung alter lokaler zentraler Cron-Einträge sind browsergetriebene AJAX-Batches. Die Hooks lauten `wp_ajax_rrze_msm_start_analysis_schedule_initialization`, `wp_ajax_rrze_msm_run_analysis_schedule_initialization_batch`, `wp_ajax_rrze_msm_start_analysis_task_removal`, `wp_ajax_rrze_msm_run_analysis_task_removal_batch`, `wp_ajax_rrze_msm_start_legacy_central_cron_cleanup` und `wp_ajax_rrze_msm_run_legacy_central_cron_cleanup_batch`.

Die Aufgabenentfernung verwendet die jeweiligen State-Options `rrze_msm_storage_analysis_task_removal_state` und `rrze_msm_shortcode_block_analysis_task_removal_state`. Alte zentrale lokale Cron-Einträge verwenden `rrze_msm_legacy_central_cron_cleanup_state`. `NetworkCronCleanupQueue` versieht AJAX-Läufe mit `mode = ajax`, `run_id` und einem Lease von 15 Minuten. Ein abgebrochener Browserlauf wird danach als aufgegeben markiert und blockiert keine neuen regulären Analyseaufgaben dauerhaft.

Alle AJAX- und Admin-Post-Aktionen prüfen Capability und Nonce. Neue technische Aktionen müssen diese beiden Prüfungen beibehalten und den jeweiligen Zustand in Network Options speichern, wenn sie netzwerkweit arbeiten.

## Externe Kommunikation

`MonitoringService` sendet DNS- und HTTP-Anfragen an die in der Multisite registrierten Website-Domains. Die HTTP-Anfragen laufen ohne Authentifizierung über die WordPress-HTTP-API. Ziel, Timeout- und Fehlerverhalten sind im Abschnitt Verfügbarkeitsmonitoring beschrieben. Je nach Ziel-Domain können dabei Request-Metadaten die lokale Infrastruktur verlassen.

Die Rückwärtssuche für Bilder ist eine nutzerinitiierte Browseraktion: Der Link zu TinEye übergibt die öffentlich erreichbare Bild-URL als Suchparameter. Das Plugin selbst führt dabei keinen serverseitigen Request an TinEye aus und verwendet keine API-Zugangsdaten.

## Browserzustand und Cookies

Das Plugin verwendet kein `localStorage` oder `sessionStorage`. Der JavaScript-Code in `src/js/rrze-multisite-manager.js` setzt Cookies mit `Path=/` und `SameSite=Lax`:

| Cookie | Inhalt | Laufzeit |
| --- | --- | --- |
| `rrze_msm_color_mode` | gewählter Admin-Farbmodus | 365 Tage |
| `rrze_msm_widget_order_<view>` | individuelle Widget-Reihenfolge einer Dashboard-Ansicht | 365 Tage |

Die Cookie-Werte enthalten keine Zugangsdaten. Die Widget-Reihenfolge wird zusätzlich als User Meta `rrze_msm_widget_orders` gespeichert, damit die serverseitig gerenderte Reihenfolge unabhängig vom Browser erhalten bleibt.
