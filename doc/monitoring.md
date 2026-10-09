# Monitoring

## Zweck

Die Seite **Monitoring** ist die zentrale Betriebsoberfläche für Dashboard-Metriken, Verfügbarkeitsprüfungen, Speicheranalysen, Shortcode-/Blockanalysen und Wartungswerkzeuge. Sie ist nur für Superadmins sichtbar.

## Tabs

### Netzwerkweit

Zeigt Dashboard-Metriken und Website-Verfügbarkeitsmonitoring. Beide Prozesse können geplant, gestartet, zurückgesetzt oder entfernt werden. Status-Pills verwenden einheitlich:

* **Nicht geplant** in Grau: kein nächster Lauf, unabhängig davon, ob bereits ein Ergebnis vorliegt.
* **Geplant** in Gelb/Orange: noch kein Ergebnis, aber ein kommender Lauf ist geplant.
* **Ok** in Grün: ein Ergebnis liegt vor und ein kommender Lauf ist geplant.

Die Verfügbarkeitsprüfung arbeitet mit DNS- und HTTP-Prüfungen. HTTP-Timeouts und der Request-Zeitrahmen sind begrenzt. DNS-Aufrufe werden nicht mehr begonnen, wenn das Batch-Zeitbudget weniger als fünf Sekunden beträgt; Details zur unvermeidbaren serverseitigen Resolver-Konfiguration stehen in [Laufzeitdaten und Scheduler](runtime-state.md#serverbetrieb-dns-timeouts).

### Speicheranalysen

Zeigt geplante Einzelaufgaben und Sammelbatches für Speicheranalysen. Websites ohne Planung werden nur dann als eigene Tabelle angezeigt, wenn es solche Websites tatsächlich gibt. Mehrfachaktionen erlauben das Einrichten oder Entfernen geplanter Analysen für ausgewählte Websites.

### Shortcode und Blöcke

Entspricht dem Speicheranalyse-Tab für die Shortcode-/Blockanalyse: geplante Aufgaben, Sammelbatches, Status, Start und Mehrfachaktionen werden zentral verwaltet.

### Werkzeuge

Enthält Wartungsaktionen, etwa das Entfernen veralteter lokaler zentraler Cron-Einträge und das Bereinigen von Daten. Umfangreiche Bereinigungen laufen als Admin-AJAX-Batches. Ein Abbruch hinterlässt keine dauerhafte Sperre; ein veralteter Laufstatus wird nach Ablauf behandelt.

## Ereignislinks

Links auf WP Crontrol zeigen bei Sammelbatches immer auf die Hauptwebsite des Netzwerks, weil dort die zentralen Sammelereignisse laufen. Einzelaufgaben verlinken auf den Cron-Kontext ihrer Website.

## Technische Ausführung

`Settings::renderMonitoringPage()` ist ausschließlich eine Steuer- und Statusoberfläche. Die eigentlichen Läufer sind `MetricsImplementationService`, `MonitoringService`, `StorageAnalysisSchedulerService` und `ShortcodeBlockAnalysisSchedulerService`.

Alle wiederkehrenden Netzwerkprozesse werden im Cron-Kontext der Hauptwebsite registriert. Einzelaufgaben für Speicher- und Shortcode-/Blockanalysen laufen hingegen in der Cron-Tabelle der jeweiligen Website. Sammelbatches prüfen ihren Ausführungskontext und verschieben beziehungsweise entfernen falsch auf einer Subsite eingetragene zentrale Events.

Die verwendeten Cron-Hooks, Schedule-Keys, Fortsetzungsargumente, Network Options, Locks und TTLs sind vollständig in [Laufzeitdaten und Scheduler](runtime-state.md) aufgeführt. Die Seite selbst startet keine versteckte Vollanalyse beim Rendern.

## AJAX-Schnittstellen

Die Dialoge für Planungsinitialisierung, Aufgabenentfernung und alte zentrale Cron-Bereinigung rufen `admin-ajax.php` auf. Ihre registrierten Hooks beginnen mit `wp_ajax_rrze_msm_`; sie sind nonce- und Superadmin-geschützt. Die Queue-Zustände liegen als Network Options vor und verwenden bei browsergetriebenen Läufen einen 15-Minuten-Lease, damit ein abgebrochener Dialog keine dauerhafte Sperre hinterlässt.
