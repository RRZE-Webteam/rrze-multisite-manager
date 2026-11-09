# WordPress-Core-Hooks

Diese Seite dokumentiert alle **WordPress-Core-Hooks**, an die das Plugin
`RRZE Multisite Manager` aktuell Callbacks registriert. Sie dient als
Übersicht für Auswirkungen von Änderungen im WordPress-Core und für die
Fehlersuche bei unerwartet alten Dashboard-Metriken oder Statusanzeigen.

Stand: Quellcode des Plugins. Priorität und Anzahl der akzeptierten Argumente
sind angegeben, wenn sie nicht den WordPress-Standardwerten `10` und `1`
entsprechen.

## Abgrenzung

Nicht enthalten sind bewusst:

- plugin-eigene Cron-Hooks (`rrze_msm_*`), AJAX- und Formular-Endpunkte
  (`wp_ajax_rrze_msm_*`, `admin_post_rrze_*`, `network_admin_edit_rrze_*`),
- plugin-eigene Protokoll-Hooks wie `rrze.log.error`,
- Hooks aus Test-Bootstrap-Dateien.

Die folgenden Hooks stammen aus WordPress Core. Mehrere Einträge bei einem
Hook bedeuten verschiedene Callbacks mit unterschiedlichen Aufgaben.

## Laden, Aktivierung und Administration

| Hook | Art | Callback/Komponente | Priorität / Argumente | Wirkung im Plugin |
| --- | --- | --- | --- | --- |
| `init` | Action | Plugin-Bootstrap `loadTextdomain()` | 0 | Lädt die Übersetzungen des Plugins; bei anonymen Frontend-Aufrufen (nicht Admin, Cron, AJAX, WP-CLI, nicht angemeldet) übersprungen. |
| `init` | Action | Plugin-Bootstrap `loaded()` | 5 | Prüft die netzwerkweite Aktivierung und Systemvoraussetzungen und initialisiert die Plugin-Dienste. Darin prüft `AnalysisLifecycle::register()` das Analyseschema und – nur auf der Hauptwebsite, ohne `switch_to_blog()` – die Planung der stündlichen Analysewartung. |
| `init` | Action | `MonitoringService::ensureScheduledEvent()` | 10 / 1 | Stellt die zentrale Planung der Erreichbarkeitsprüfung sicher. |
| `init` | Action | `StorageAnalysisSchedulerService::ensureRecurringSchedules()` | 20 / 1 | Stellt die Planung der Speicheranalysen sicher. |
| `init` | Action | `ShortcodeBlockAnalysisSchedulerService::ensureRecurringSchedules()` | 20 / 1 | Stellt die Planung der Shortcode- und Blockanalysen sicher. |
| `init` | Action | `MetricsImplementationService::ensureDashboardRefreshContinuation()` | 24 / 1 | Stellt die Fortsetzung der Dashboard-Metrik-Ermittlung sicher. |
| `init` | Action | `MetricsImplementationService::ensureDetailCacheMaintenance()` | 24 / 1 | Stellt die regelmäßige Pflege des Detail-Caches sicher. |
| `init` | Action | `PluginThemeUsageMetricsService::ensureSchedule()` | 25 / 1 | Stellt auf der Hauptwebsite die Planung des Plugin-/Theme-Nutzungsindex sicher und setzt einen laufenden Lauf mit fehlender Fortsetzung fort. |
| `init` | Action | `WebsiteStatusReportService::ensureScheduledReport()` | 30 / 1 | Prüft und plant den Website-Statusbericht auf der zentralen Website, sofern aktiviert. |
| `admin_init` | Action | Plugin-Bootstrap `loadTextdomain()` | 0 | Lädt Übersetzungen auch im Administrationskontext frühzeitig. |
| `admin_init` | Action | Plugin-Bootstrap `registerSystemRequirementNotice()` | 10 / 1 | Bereitet bei nicht erfüllten Systemvoraussetzungen die passende Admin-Meldung vor. |
| `load_textdomain_mofile` | Filter | Plugin-Bootstrap `preferBundledTextdomainMofile()` | 10 / 2 | Bevorzugt die mitgelieferte, aktuelle Sprachdatei gegenüber einem veralteten globalen Sprachpaket. |
| Plugin-Aktivierung | Aktivierungshook | `activate()` | – | Legt bzw. aktualisiert die zentralen Analysetabellen und deaktiviert vorhandene Hintergrundplanungen. |
| Plugin-Deaktivierung | Deaktivierungshook | `deactivate()` | – | Entfernt alle plugin-eigenen Cron-Ereignisse, ohne Analyse- und Metrikdaten zu löschen. |
| Plugin-Deinstallation | Uninstall-Hook | `uninstall.php` | – | Löscht die zentralen Analysetabellen und das zugehörige Schema-Merkmal. |
| `admin_notices` | Action | Plugin-Bootstrap / `Main` | 10 / 1 | Zeigt Hinweise bei fehlender netzwerkweiter Aktivierung, nicht erfüllten Systemvoraussetzungen oder nicht verfügbarer Analysespeicherung. |
| `network_admin_notices` | Action | Plugin-Bootstrap / `Main` | 10 / 1 | Entsprechende Hinweise im Netzwerk-Adminbereich. |
| `admin_menu` | Action | `Dashboard::registerMenu()` | 999 / 1 | Registriert die lokalen Verwaltungs- und Werkzeugseiten. |
| `network_admin_menu` | Action | `Dashboard::registerNetworkMenu()` | 999 / 1 | Registriert die Netzwerk-Menüs des Plugins. |
| `admin_enqueue_scripts` | Action | `Dashboard::enqueueAssets()` | 10 / 1 | Lädt CSS und JavaScript der Plugin-Administration. |
| `admin_enqueue_scripts` | Action | `Dashboard::enqueueAdminBarStyles()` | 10 / 1 | Lädt zusätzliche Styles für die Admin-Bar. |
| `admin_bar_menu` | Action | `Dashboard::addAdminBarMenu()` | 35 / 1 | Ergänzt das MSM-Menü in der Admin-Bar. |
| `admin_body_class` | Filter | `Dashboard::filterAdminBodyClass()` | 10 / 1 | Ergänzt eine CSS-Klasse für Plugin-Adminseiten. |
| `user_has_cap` | Filter | `Dashboard::filterUserHasCap()` | 20 / 4 | Passt die Capability-Prüfung für die vorgesehenen Verwaltungsansichten an. |
| `rest_post_dispatch` | Filter | `ResponseTimeSampler::captureRestRequest()` | 10 / 3 | Merkt sich, ob eine REST-Anfrage ein Speichern, automatisches Speichern oder einen Medienupload von Beiträgen/Seiten betrifft. Antwort unverändert. |
| `shutdown` | Action | `ResponseTimeSampler::recordCurrentRequest()` | 1 / 1 | Nur bei aktiver Messung (Seite Umgebung) und nur für Editor-, Speicher- und Upload-Anfragen: speichert Antwortzeit und Lastangaben. |
| `load-<Seiten-Hook>` | Action | `Dashboard::configureNetworkUsersScreen()` | 10 / 1 | Registriert beim Laden der Benutzerseite die Spalten-Filter für die Netzwerk-Benutzerliste. |
| `manage_<Screen-ID>_columns` | Filter | `Dashboard::getNetworkUsersScreenColumns()` | 10 / 1 | Liefert die Spalten der Netzwerk-Benutzerliste (nur Benutzerseite). |
| `default_hidden_columns` | Filter | `Dashboard::filterNetworkUsersDefaultHiddenColumns()` | 10 / 2 | Blendet nach der Einstellung `users_default_visible_columns` Spalten standardmäßig aus (nur Benutzerseite). |
| `pre_user_query` | Action | `NetworkUsersListTable::excludeUsersWithWebsiteMembership()` | 10 / 1 | Nur während der Ansicht „Benutzer ohne Website-Zuordnung“: schließt Benutzer mit Rolle auf einer Website aus. Wird danach wieder entfernt. |
| `wp_redirect` | Filter | `Dashboard::redirectNetworkUsersDeleteToMsm()` | 10 / 2 | Leitet nach der Core-Benutzerlöschung zurück zur Benutzerliste des Multisite Managers, nur mit gültiger Nonce. |
| `admin_action_rrze_multisite_manager_enable_selected_network_audit_plugins`, `admin_action_rrze_multisite_manager_disable_selected_network_audit_plugins` | Action | `Dashboard::handleNetworkAuditPluginBulkToggle()` | 10 / 1 | Massenaktion „Freigeben“/„Sperren“ in der Plugin-Übersicht (Plugin-Freigabe über RRZE Netzwerk-Audit bzw. RRZE Settings). |

## Multisite-Lebenszyklus und Website-Status

Die Invalidierungs-Callbacks dieser Gruppe verwerfen betroffene Detail- und
Dashboard-Caches und planen bei Bedarf eine neue Metrik-Ermittlung. Dadurch
werden Website-Übersicht, Widgets sowie Plugin- und Theme-Übersichten nach
einer Änderung wieder konsistent aufgebaut.

| Hook | Callback/Komponente | Priorität / Argumente | Wirkung im Plugin |
| --- | --- | --- | --- |
| `wpmu_new_blog` | `MetricsImplementationService::invalidateSiteAndGlobalCachesFromHook()` | 20 / 6 | Invalidiert Website- und Netzwerk-Caches nach Anlage einer Website. |
| `wpmu_new_blog` | `PluginThemeUsageMetricsService::syncNewSite()` | 30 / 6 | Trägt Plugin- und Theme-Nutzung der neuen Website in den Nutzungsindex ein (und in die Staging-Tabellen eines laufenden Laufs). |
| `archive_blog` | `Dashboard::recordArchivedSiteTimestamp()` | 10 / 1 | Speichert `rrze_msm_archived_at` als Zeitpunkt der Archivierung. |
| `archive_blog` | `MetricsImplementationService::invalidateSiteCachesFromHook()` | 20 / 1 | Invalidiert die Caches der archivierten Website. |
| `unarchive_blog` | `Dashboard::clearArchivedSiteTimestamp()` | 10 / 1 | Entfernt `rrze_msm_archived_at`. |
| `unarchive_blog` | `MetricsImplementationService::invalidateSiteCachesFromHook()` | 20 / 1 | Invalidiert die Caches der wiederhergestellten Website. |
| `make_delete_blog` | `Dashboard::recordDeletedSiteTimestamp()` | 10 / 1 | Speichert `rrze_msm_deleted_at` als Zeitpunkt der Löschmarkierung. |
| `make_undelete_blog` | `Dashboard::clearDeletedSiteTimestamp()` | 10 / 1 | Entfernt `rrze_msm_deleted_at`. |
| `delete_blog` | `MetricsImplementationService::invalidateSiteAndGlobalCachesFromHook()` | 20 / 2 | Invalidiert Website- und Netzwerk-Caches bei einer Löschmarkierung. |
| `delete_blog` | `PluginThemeUsageMetricsService::removeSite()` | 30 / 2 | Entfernt die Website aus dem Plugin-/Theme-Nutzungsindex. |
| `undelete_blog` | `MetricsImplementationService::invalidateSiteAndGlobalCachesFromHook()` | 20 / 1 | Invalidiert Website- und Netzwerk-Caches nach Aufhebung der Löschmarkierung. |
| `wp_delete_site` | `AnalysisLifecycle::deleteSite()` | 10 / 1 | Entfernt alle zentral gespeicherten Analyse- und Laufdaten einer endgültig gelöschten Website. |
| `wp_delete_site` | `PluginThemeUsageMetricsService::removeDeletedSite()` | 30 / 1 | Entfernt die endgültig gelöschte Website aus dem Nutzungsindex. |
| `make_spam_blog` | `MetricsImplementationService::invalidateSiteCachesFromHook()` | 20 / 1 | Invalidiert Caches nach Sperrung einer Website. |
| `make_ham_blog` | `MetricsImplementationService::invalidateSiteCachesFromHook()` | 20 / 1 | Invalidiert Caches nach Entsperrung einer Website. |
| `mature_blog` | `MetricsImplementationService::invalidateSiteCachesFromHook()` | 20 / 1 | Invalidiert Caches nach Kennzeichnung als reife/öffentliche Website. |
| `unmature_blog` | `MetricsImplementationService::invalidateSiteCachesFromHook()` | 20 / 1 | Invalidiert Caches nach Rücknahme dieser Kennzeichnung. |

## Inhalte, Benutzer und Konfiguration einer Website

| Hook | Callback/Komponente | Priorität / Argumente | Wirkung im Plugin |
| --- | --- | --- | --- |
| `save_post` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 3 | Invalidiert die Metrik- und Detail-Caches der aktuellen Website bei Änderung eines Beitrags. |
| `deleted_post` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 2 | Invalidiert Caches nach endgültigem Löschen eines Beitrags. |
| `add_attachment` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 1 | Invalidiert Caches nach Anlegen eines Mediums. |
| `delete_attachment` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 1 | Invalidiert Caches nach Löschen eines Mediums. |
| `user_register` | `MetricsImplementationService::invalidateDashboardCacheOnly()` | 20 / 1 | Invalidiert die Netzwerk-Dashboard-Metriken bei Anlage eines Benutzers. |
| `deleted_user` | `MetricsImplementationService::invalidateCaches()` | 20 / 1 | Invalidiert die betroffenen Caches nach Löschen eines Benutzers. |
| `add_user_to_blog` | `MetricsImplementationService::invalidateUserSiteCachesFromHook()` | 20 / 3 | Invalidiert die Caches der Website, der ein Benutzer hinzugefügt wurde. |
| `remove_user_from_blog` | `MetricsImplementationService::invalidateUserRemovalSiteCachesFromHook()` | 20 / 2 | Invalidiert die Caches der Website, von der ein Benutzer entfernt wurde. |
| `set_user_role` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 3 | Invalidiert Caches bei Rollenänderung eines Benutzers. |
| `update_option_blogname` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 3 | Invalidiert Caches nach Änderung des Website-Titels. |
| `update_option_admin_email` | `MetricsImplementationService::invalidateCurrentSiteCaches()` | 20 / 3 | Invalidiert Caches nach Änderung der Administrator-E-Mail-Adresse. |
| `update_option_stylesheet` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 3 | Invalidiert Website- und Netzwerk-Caches nach Theme-Wechsel über die Option. |
| `update_option_stylesheet` | `PluginThemeUsageMetricsService::syncCurrentSiteAfterThemeChange()` | 30 / 3 | Ersetzt die Theme-Zuordnung der Website im Nutzungsindex. |
| `update_option_template` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 3 | Invalidiert Website- und Netzwerk-Caches nach Änderung des Theme-Templates. |
| `update_option_template` | `PluginThemeUsageMetricsService::syncCurrentSiteAfterThemeChange()` | 30 / 3 | Ersetzt die Theme-Zuordnung der Website im Nutzungsindex. |
| `update_option_active_plugins` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 3 | Invalidiert Website- und Netzwerk-Caches nach Änderung aktiver Plugins. |
| `update_option_active_plugins` | `PluginThemeUsageMetricsService::syncCurrentSiteAfterPluginChange()` | 30 / 3 | Ersetzt die Plugin-Zuordnungen der Website im Nutzungsindex. |
| `add_option_active_plugins` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 2 | Deckt die erstmalige Anlage der Option aktiver Plugins ab. |
| `add_option_active_plugins` | `PluginThemeUsageMetricsService::syncCurrentSiteAfterPluginChange()` | 30 / 2 | Ersetzt die Plugin-Zuordnungen der Website im Nutzungsindex. |
| `delete_option_active_plugins` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 1 | Deckt das Entfernen der Option aktiver Plugins ab. |
| `delete_option_active_plugins` | `PluginThemeUsageMetricsService::syncCurrentSiteAfterPluginChange()` | 30 / 1 | Ersetzt die Plugin-Zuordnungen der Website im Nutzungsindex. |
| `update_site_option_active_sitewide_plugins` | `MetricsImplementationService::invalidateCaches()` | 20 / 4 | Invalidiert Caches nach Änderung netzwerkweit aktiver Plugins. |
| `update_site_option_active_sitewide_plugins` | `PluginThemeUsageMetricsService::startRun()` | 30 / 4 | Startet einen neuen, begrenzten Lauf des Nutzungsindex, weil die Änderung jede Website betrifft. |
| `update_site_option_allowedthemes` | `MetricsImplementationService::invalidateCaches()` | 20 / 4 | Invalidiert Caches nach Änderung der netzwerkweit erlaubten Themes. |
| `update_site_option_blog_upload_space` | `MetricsImplementationService::invalidateCaches()` | 20 / 4 | Invalidiert Caches nach Änderung des Speicherplatzlimits. |

## Plugins, Themes und Updates

| Hook | Callback/Komponente | Priorität / Argumente | Wirkung im Plugin |
| --- | --- | --- | --- |
| `activated_plugin` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 2 | Invalidiert Caches nach Plugin-Aktivierung. |
| `deactivated_plugin` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 2 | Invalidiert Caches nach Plugin-Deaktivierung. |
| `switch_theme` | `MetricsImplementationService::invalidateCurrentSiteAndGlobalCaches()` | 20 / 3 | Invalidiert Caches beim regulären Theme-Wechsel. |
| `switch_theme` | `PluginThemeUsageMetricsService::syncCurrentSiteAfterThemeChange()` | 30 / 3 | Ersetzt die Theme-Zuordnung der Website im Nutzungsindex. |
| `upgrader_process_complete` | `MetricsImplementationService::invalidateCaches()` | 20 / 2 | Invalidiert Caches nach einem Core-, Plugin- oder Theme-Update. |

## Zeitplanung und Versand

| Hook | Art | Callback/Komponente | Priorität / Argumente | Wirkung im Plugin |
| --- | --- | --- | --- | --- |
| `cron_schedules` | Filter | `MonitoringService::registerSchedules()` | 10 / 1 | Ergänzt die für die Erreichbarkeitsprüfung benötigten WP-Cron-Intervalle. |
| `cron_schedules` | Filter | `StorageAnalysisSchedulerService::registerSchedules()` | 10 / 1 | Ergänzt Intervalle für Speicheranalyse und ihre Batches. |
| `cron_schedules` | Filter | `ShortcodeBlockAnalysisSchedulerService::registerSchedules()` | 10 / 1 | Ergänzt Intervalle für Shortcode- und Blockanalyse. |
| `cron_schedules` | Filter | `MetricsImplementationService::registerSchedules()` | 10 / 1 | Ergänzt Intervalle für Dashboard-Metriken, Historienverdichtung und Cache-Pflege. |
| `cron_schedules` | Filter | `PluginThemeUsageMetricsService::registerSchedules()` | 10 / 1 | Ergänzt das Intervall des Plugin-/Theme-Nutzungsindex. |
| `phpmailer_init` | Action | temporärer Callback in `WebsiteStatusReportService::sendReport()` | 10 / 1 | Schaltet ausschließlich während des Website-Statusberichts den HTML-Versand ein und setzt die Plain-Text-Alternative (`AltBody`). Der Callback wird danach wieder entfernt. |

## Hinweise für Wartung und Erweiterungen

- Die Cache-Invalidierung ist absichtlich zentral in
  `MetricsImplementationService` gebündelt. Neue Datenquellen für
  Dashboard-Metriken müssen dort berücksichtigt werden, statt an jeder
  Anzeige einen eigenen Cache-Workaround einzubauen.
- Die Zeitstempel `rrze_msm_archived_at` und `rrze_msm_deleted_at` sind
  Plugin-Metadaten. WordPress liefert für diese Statuswechsel keinen
  geeigneten, eigenständigen Zeitpunkt.
- Die hier dokumentierten Hook-Callbacks dürfen keine Berechtigungsprüfung
  ersetzen. Hooks können auch durch WP-Cron, WP-CLI oder andere Plugins
  ausgelöst werden.
