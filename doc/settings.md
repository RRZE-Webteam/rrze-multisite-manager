# Einstellungen

## Zweck

Die Seite **Einstellungen** konfiguriert den Multisite Manager. Sie ist nur für Superadmins sichtbar und besitzt die Tabs **Allgemeines**, **Monitoring** und **Ansichten**.

## Allgemeines

Hier werden Standardwerte für Dashboard-Tabellen, die Inaktivitätsmarkierung und das optionale Logging von Hintergrundprozessen gepflegt. Die Einstellungen bestimmen keine WordPress-Kernoptionen außerhalb des Multisite Manager.

## Monitoring

Der Tab konfiguriert:

* Intervalle für Dashboard-Metriken und Verfügbarkeitsprüfungen
* Batch-Größe für netzwerkweite Prozesse
* Schonfrist neuer Websites sowie DNS- und HTTP-Fehlerschwellen
* Intervalle, Laufzeitgrenzen und Zuweisungskriterien für Speicheranalysen
* Intervalle und Laufzeitgrenzen für Shortcode-/Blockanalysen
* Anzahl aufbewahrter Protokoll- und Ereigniseinträge

Größere Batches verkürzen einen Gesamtlauf, erhöhen aber Last und Laufzeit eines einzelnen Cron-Requests. Die Werte sollten daher an PHP-Limits, Cron-Takt und Größe des Netzwerks angepasst werden.

## Ansichten

Ansichten bestimmen, welche Widgets im Dashboard sichtbar sind und in welcher Reihenfolge sie standardmäßig erscheinen. Die individuelle Reihenfolge eines Benutzers wird getrennt im Browser gespeichert.

## Speichern und Wirkung

Beim Speichern prüft das Plugin Wertebereiche und plant geänderte wiederkehrende Prozesse neu. Bereits laufende Batches werden nicht durch einen Seitenaufruf parallel gestartet. Für das manuelle Starten, Zurücksetzen oder Entfernen von Aufgaben ist die Seite [Monitoring](monitoring.md) vorgesehen.

## Implementierung

`Settings` liest und schreibt die Network Option `rrze-multisite-manager`. Die Form-Handler werden sowohl als `admin_post_*` als auch als `network_admin_edit_*` registriert, damit die Oberfläche im normalen Admin- und im Netzwerk-Admin-Kontext funktioniert. Alle schreibenden Handler prüfen `is_super_admin()` und den passenden Nonce.

Frequenzänderungen rufen keine synchrone Netzwerkschleife auf. Sie schreiben Signatur- und Fortschrittsdaten wie `rrze_msm_storage_analysis_schedule_signature`, `rrze_msm_shortcode_block_analysis_schedule_signature` und die jeweiligen `*_frequency_reschedule_state`-Options. Die nachgelagerten Migrationshooks sind in [Laufzeitdaten und Scheduler](runtime-state.md) dokumentiert.
