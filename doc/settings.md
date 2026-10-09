# Einstellungen

## Zweck

Die Seite **Einstellungen** konfiguriert den Multisite Manager. Sie ist nur fuer Superadmins sichtbar und besitzt die Tabs **Allgemeines**, **Monitoring** und **Ansichten**.

## Allgemeines

Hier werden Standardwerte fuer Dashboard-Tabellen, die Inaktivitaetsmarkierung und das optionale Logging von Hintergrundprozessen gepflegt. Die Einstellungen bestimmen keine WordPress-Kernoptionen ausserhalb des Multisite Manager.

## Monitoring

Der Tab konfiguriert:

* Intervalle fuer Dashboard-Metriken und Verfuegbarkeitspruefungen
* Batch-Groesse fuer netzwerkweite Prozesse
* Schonfrist neuer Websites sowie DNS- und HTTP-Fehlerschwellen
* Intervalle, Laufzeitgrenzen und Zuweisungskriterien fuer Speicheranalysen
* Intervalle und Laufzeitgrenzen fuer Shortcode-/Blockanalysen
* Anzahl aufbewahrter Protokoll- und Ereigniseintraege

Groessere Batches verkuerzen einen Gesamtlauf, erhoehen aber Last und Laufzeit eines einzelnen Cron-Requests. Die Werte sollten daher an PHP-Limits, Cron-Takt und Groesse des Netzwerks angepasst werden.

## Ansichten

Ansichten bestimmen, welche Widgets im Dashboard sichtbar sind und in welcher Reihenfolge sie standardmaessig erscheinen. Die individuelle Reihenfolge eines Benutzers wird getrennt im Browser gespeichert.

## Speichern und Wirkung

Beim Speichern prueft das Plugin Wertebereiche und plant geaenderte wiederkehrende Prozesse neu. Bereits laufende Batches werden nicht durch einen Seitenaufruf parallel gestartet. Fuer das manuelle Starten, Zuruecksetzen oder Entfernen von Aufgaben ist die Seite [Monitoring](monitoring.md) vorgesehen.

## Implementierung

`Settings` liest und schreibt die Network Option `rrze-multisite-manager`. Die Form-Handler werden sowohl als `admin_post_*` als auch als `network_admin_edit_*` registriert, damit die Oberflaeche im normalen Admin- und im Netzwerk-Admin-Kontext funktioniert. Alle schreibenden Handler pruefen `is_super_admin()` und den passenden Nonce.

Frequenzaenderungen rufen keine synchrone Netzwerkschleife auf. Sie schreiben Signatur- und Fortschrittsdaten wie `rrze_msm_storage_analysis_schedule_signature`, `rrze_msm_shortcode_block_analysis_schedule_signature` und die jeweiligen `*_frequency_reschedule_state`-Options. Die nachgelagerten Migrationshooks sind in [Laufzeitdaten und Scheduler](runtime-state.md) dokumentiert.
