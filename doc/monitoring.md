# Monitoring

## Zweck

Die Seite **Monitoring** ist die zentrale Betriebsoberflaeche fuer Dashboard-Metriken, Verfuegbarkeitspruefungen, Speicheranalysen, Shortcode-/Blockanalysen und Wartungswerkzeuge. Sie ist nur fuer Superadmins sichtbar.

## Tabs

### Netzwerkweit

Zeigt Dashboard-Metriken und Website-Verfuegbarkeitsmonitoring. Beide Prozesse koennen geplant, gestartet, zurueckgesetzt oder entfernt werden. Status-Pills verwenden einheitlich:

* **Nicht geplant** in Grau: kein naechster Lauf, unabhaengig davon, ob bereits ein Ergebnis vorliegt.
* **Geplant** in Gelb/Orange: noch kein Ergebnis, aber ein kommender Lauf ist geplant.
* **Ok** in Gruen: ein Ergebnis liegt vor und ein kommender Lauf ist geplant.

Die Verfuegbarkeitspruefung arbeitet mit DNS- und HTTP-Pruefungen. HTTP-Timeouts und der Request-Zeitrahmen sind begrenzt. DNS-Aufrufe werden nicht mehr begonnen, wenn das Batch-Zeitbudget weniger als fuenf Sekunden betraegt; Details zur unvermeidbaren serverseitigen Resolver-Konfiguration stehen in [Laufzeitdaten und Scheduler](runtime-state.md#serverbetrieb-dns-timeouts).

### Speicheranalysen

Zeigt geplante Einzelaufgaben und Sammelbatches fuer Speicheranalysen. Websites ohne Planung werden nur dann als eigene Tabelle angezeigt, wenn es solche Websites tatsaechlich gibt. Mehrfachaktionen erlauben das Einrichten oder Entfernen geplanter Analysen fuer ausgewaehlte Websites.

### Shortcode und Bloecke

Entspricht dem Speicheranalyse-Tab fuer die Shortcode-/Blockanalyse: geplante Aufgaben, Sammelbatches, Status, Start und Mehrfachaktionen werden zentral verwaltet.

### Werkzeuge

Enthaelt Wartungsaktionen, etwa das Entfernen veralteter lokaler zentraler Cron-Eintraege und das Bereinigen von Daten. Umfangreiche Bereinigungen laufen als Admin-AJAX-Batches. Ein Abbruch hinterlaesst keine dauerhafte Sperre; ein veralteter Laufstatus wird nach Ablauf behandelt.

## Ereignislinks

Links auf WP Crontrol zeigen bei Sammelbatches immer auf die Hauptwebsite des Netzwerks, weil dort die zentralen Sammelereignisse laufen. Einzelaufgaben verlinken auf den Cron-Kontext ihrer Website.

## Technische Ausfuehrung

`Settings::renderMonitoringPage()` ist ausschliesslich eine Steuer- und Statusoberflaeche. Die eigentlichen Laeufer sind `MetricsImplementationService`, `MonitoringService`, `StorageAnalysisSchedulerService` und `ShortcodeBlockAnalysisSchedulerService`.

Alle wiederkehrenden Netzwerkprozesse werden im Cron-Kontext der Hauptwebsite registriert. Einzelaufgaben fuer Speicher- und Shortcode-/Blockanalysen laufen hingegen in der Cron-Tabelle der jeweiligen Website. Sammelbatches pruefen ihren Ausfuehrungskontext und verschieben beziehungsweise entfernen falsch auf einer Subsite eingetragene zentrale Events.

Die verwendeten Cron-Hooks, Schedule-Keys, Fortsetzungsargumente, Network Options, Locks und TTLs sind vollstaendig in [Laufzeitdaten und Scheduler](runtime-state.md) aufgefuehrt. Die Seite selbst startet keine versteckte Vollanalyse beim Rendern.

## AJAX-Schnittstellen

Die Dialoge fuer Planungsinitialisierung, Aufgabenentfernung und alte zentrale Cron-Bereinigung rufen `admin-ajax.php` auf. Ihre registrierten Hooks beginnen mit `wp_ajax_rrze_msm_`; sie sind nonce- und Superadmin-geschuetzt. Die Queue-Zustaende liegen als Network Options vor und verwenden bei browsergetriebenen Laeufen einen 15-Minuten-Lease, damit ein abgebrochener Dialog keine dauerhafte Sperre hinterlaesst.
