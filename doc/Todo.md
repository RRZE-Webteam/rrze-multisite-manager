# Todo

Stand: 06.11.2026, Pluginversion 1.3.38-2. Offene Aufgaben des RRZE Multisite Managers, die zugrunde liegenden Performance-Grundsätze, bereits umgesetzte Maßnahmen und bewusst verworfene Ansätze. Ersetzt die früheren Dateien `performance-review-2026-11-05.md`, `performance-tasks.md` und `performance.md`.

## 1. Einstellungen, Tab „Monitoring“ neu gliedern

Ausgangslage: 13 sichtbare Gruppen mit rund 60 Feldern in einem einzigen Formular. Die Speicheranalyse allein belegt fünf Gruppen mit 25 Feldern, die sich überwiegend nur durch die Stufe klein/mittel/groß/einzeln unterscheiden. Optionsnamen bleiben unverändert, es ist keine Migration nötig. Speichern eines Bereichs lässt andere Bereiche bereits heute unverändert (`Settings::sanitizeOptions()` führt mit dem gespeicherten Stand zusammen).

- [ ] **Unter-Tabs nach Bereichen**, angelehnt an die Monitoring-Seite, jeweils mit eigenem Speichern-Knopf:
  - Allgemein: Workerlimit, Batch-Größe, Protokolleinträge
  - Dashboard-Metriken: Rhythmus, Statistikrhythmus
  - Erreichbarkeit: Rhythmus, Batch-Größe, ignorierte Status, Karenzzeit, DNS-/HTTP-Schwellen
  - Plugins & Themes: Nutzungsrhythmus, Unterordnertiefe, maximale PHP-Dateien
  - Speicheranalyse
  - Shortcodes & Blöcke
- [ ] **Matrix statt Feldliste** für die Batch-Stufen von Speicher- und Shortcode-/Blockanalyse: Zeilen klein/mittel/groß/einzeln, Spalten Rhythmus, Schwelle (Medien bzw. Beiträge), Laufzeitschwelle, Timeout. Aufsteigende Schwellen sind so direkt prüfbar.
- [ ] **Bereich „Erweitert“** (standardmäßig zugeklappt) für selten geänderte Werte: Index- und Schreib-Batch-Größen, Kapazität je Analyseart, Browser-Grenze der Speicheranalyse.
- [ ] **„Batch-Größe der Dashboard-Metriken“** gilt auch für den Plugin-/Theme-Nutzungsindex (`PluginThemeUsageMetricsService::getBatchSize()`), steht aber nur bei den Dashboard-Metriken. Entweder als gemeinsamer Wert unter „Allgemein“ führen oder in zwei Einstellungen trennen.
- [ ] **„Zugang lokaler Administratoren“** (`show_local_analysis_request_notice`) ist eine Zugriffs-, keine Monitoring-Einstellung: in den Tab „Allgemein“ verschieben.
- [ ] Prüfen, ob Unterordnertiefe und PHP-Dateigrenze für Themes getrennt von Plugins einstellbar sein sollen (derzeit gemeinsam).

## 2. Betrieb und Datenbank

- [x] **Produktive DB-Topologie dokumentieren:** MariaDB 10.11 als einzelner Master, kein Galera, HyperDB als Datenbank-Drop-in, kein persistenter Object Cache. Details unter „Messergebnisse“.
- [ ] **HyperDB-Konfiguration prüfen:** Sind in `db-config.php` Lese-Replikate eingetragen? HyperDB leitet `SELECT`-Anweisungen an Replikate, und die Sperranweisungen (`SELECT GET_LOCK(…)`, `SELECT IS_USED_LOCK(…)`, `SELECT RELEASE_LOCK(…)`) sind für HyperDB Leseabfragen. Mit Replikaten würden zwei Worker ihre Sperren auf verschiedenen Servern setzen und sich nicht gegenseitig ausschließen. Ohne Replikate besteht kein Risiko. Falls Replikate vorhanden sind oder geplant werden: in `Support/AtomicLock` vor den Sperrabfragen `$wpdb->send_reads_to_masters()` aufrufen, sofern die Methode existiert.
- [ ] **Konkurrenztest der Sperren** mit zwei echten Verbindungen auf genau diesem Datenbanktyp: gegenseitiger Ausschluss, Freigabe bei Verbindungsende, mehrere Sperren pro Verbindung. Die Sperrprüfung auf der Seite Umgebung deckt nur eine Verbindung ab.
- [ ] **Arbeitsweise des Cron-Skripts dokumentieren** (seriell oder parallel, Intervall). Bekannt: eigenes Skript außerhalb des Browsers, PHP-CLI auf `web-cms-alma`. Erste Messung (5 Minuten) zeigt Parallelität 1 und keine zurückgestellten Läufe; für eine Aussage über mindestens einen vollständigen Analysezyklus beobachten. Die Seite Umgebung zeigt beobachtete maximale Parallelität, zurückgestellte Läufe und die PHP-Laufzeit des Skripts. Bei serieller Abarbeitung greift das Workerlimit praktisch nie.

## 3. Messung im Betrieb

- [ ] Während laufender Analysen auf Staging oder Produktion beobachten: Slow Query Log, PHP-FPM-Status (belegte Worker), Antwortzeiten von Editor, Speichern und Medienupload. Diese Werte liegen außerhalb von WordPress und werden vom Plugin nicht erfasst.
- [ ] Ergebnis in dieser Datei unter „Messergebnisse“ dokumentieren und daraus die Punkte unter 4 bestätigen oder streichen.

## 4. Nur bei Messbefund

- [ ] **Dashboard-Batch:** Kosten von `information_schema.tables` pro Website auf der eingesetzten MySQL-/MariaDB-Version messen (Messwert auf der Seite Umgebung). Bei Bedarf Zeitbudget pro Batch und seltenere Erfassung der Datenbankgröße.
- [ ] **Statusbericht und Deaktivierung:** synchrone Netzwerkläufe; selten, daher nur bei beobachteten Timeouts angehen.
- [ ] **Dateisystem:** I/O der Speicheranalyse auf großen Uploadverzeichnissen (Fortsetzung per `FilesystemIterator::seek()`) nur bei beobachteter Last untersuchen.
- [ ] **Monitoring:** Prüfintervall und Batch-Größe nur bei messbarem Einfluss auf Editorantwortzeiten ändern.

## 5. Nachrangig

- [ ] **Plugin-/Theme-Detailseiten:** Websiteliste serverseitig paginieren. Die Zeilen kommen bereits aus dem gespeicherten Dashboard-Lauf; die Restkosten sind reine HTML-Ausgabe.
- [ ] **Toter URL-Parameter:** `source_analysis` (Nonce `rrze_msm_source_analysis_<type>_<id>`) auf den Plugin-/Theme-Detailseiten startet keine Analyse mehr und ändert nur noch den Cache-Schlüssel. `Dashboard::isSourceAnalysisRequested()`, `getSourceAnalysisUrl()` und das Template-Argument `source_analysis_url` entfernen.
- [ ] **Benutzerseite:** Die Anzahl der Benutzer ohne Website-Zuordnung wird bei jedem Seitenaufruf mit einer `NOT EXISTS`-Abfrage über `usermeta` (`meta_key LIKE '<prefix>%capabilities'`) gezählt. Bei sehr vielen Benutzern messen und gegebenenfalls zwischenspeichern.

## Performance-Grundsätze

**Ziel:** Normale Seitenpflege und Frontendaufrufe sollen durch MSM möglichst wenig belastet werden. Bisher ist **kein Produktionsproblem gemessen**; die Maßnahmen beruhen auf statischer Codeanalyse. Punkte unter 4 werden nur bei belegtem Messbefund umgesetzt.

Das gemeinsame Workerlimit begrenzt die *Parallelität* schwerer Prozesse, nicht die *Schwere* einzelner Operationen. Relevante Last entsteht vor allem durch:

- einzelne unbegrenzte Operationen (z. B. Quellcodescans, `information_schema`-Abfragen),
- synchrone Netzwerkläufe in Admin-Requests (Detailseiten, Suche, Widgets) – diese unterliegen dem Budget nicht,
- Grundaufwand des Plugins bei jedem Request auf jeder Website.

Die atomaren Sperren (`GET_LOCK`) sind eine Korrektheitsmaßnahme gegen doppelte Arbeit und bleiben. Das Budget bleibt als günstige Sicherung erhalten; faire Verteilung, reservierte Plätze oder Grenzen je Prozessklasse werden nicht umgesetzt.

## Messergebnisse

### 06.11.2026 – Test-Multisite, gleicher Datenbankserver und gleiche Serverkonfiguration wie Produktion

Quelle: Seite Umgebung, Version 1.3.37, Erfassung seit 16:05 (ca. 5 Minuten).

| Bereich | Wert | Bewertung |
| --- | --- | --- |
| Datenbank | MariaDB 10.11.7, `DB_HOST` mariamaster01.db.fau.de, antwortender Host `0d78b0fe1e98`, Server-ID 1, nicht read-only | Einzelner Master |
| Galera | `wsrep_on` OFF | Kein Cluster: benannte Sperren gelten serverweit |
| Drop-in | HyperDB | Siehe Abschnitt 2: Lese-Replikate prüfen |
| Verbindung | Connection-ID stabil, Sperrprüfung erfolgreich | Kein Multiplexing-Proxy |
| Object Cache | kein persistenter Cache | Transients und Optionen gehen bei jedem Request an die Datenbank; eingesparter Grundaufwand pro Request wirkt hier voll |
| Kapazität | `max_connections` 5000, `innodb_buffer_pool_size` 32 GB | Workerlimit 3 ist dagegen vernachlässigbar |
| `information_schema` | 175 Tabellen in 0,4 ms, Größenabfrage einer Website 0,5 ms | Nicht repräsentativ für die große Multisite (bei ca. 800 Websites über 10.000 Tabellen); dort erneut messen |
| Cron | `DISABLE_WP_CRON` an, 57 Ereignisse, 0 überfällig | Cron-Skript läuft zuverlässig |
| Cron-Laufzeit | PHP 8.4.27 CLI, `memory_limit` -1, `max_execution_time` 81 | Kein Speicherlimit im Cron: die festen Grenzen der Analysen sind der einzige Schutz gegen unbegrenzten Speicherverbrauch |
| Worker | 6 Läufe zugelassen, 0 zurückgestellt, Parallelität 1, Spitzenspeicher ca. 128 MB je Lauf | Bisher seriell; Zeitraum zu kurz für eine Aussage |

## Erledigt

- [x] Atomare, verbindungsgebundene Sperren mit Eigentümerprüfung statt `add_site_option()`.
- [x] Gemeinsames Workerbudget für schwere Hintergrundprozesse: Standard 3, einstellbar 1–16; Erreichbarkeitsprüfungen ausgenommen (1.3.36-3).
- [x] Plugin-/Theme-Nutzung aus zentralem Index (`plugin_metrics`, `theme_metrics`) statt Live-Lesen von `active_plugins` aller Websites.
- [x] **Quellcodeanalyse begrenzt** (1.3.36-1, erweitert bis 1.3.36-10): Einzeldatei-Plugins nur als Einzeldatei; feste Grenzen 20 s, 2.000 Verzeichnisse, 5.000 Dateien, 1 MiB je Datei, 32 MiB gesamt; einstellbar Unterordnertiefe (Standard 3) und PHP-Dateien (Standard 100); versteckte Verzeichnisse, `node_modules`, `bower_components` und Symlinks werden ausgelassen; unvollständige Ergebnisse markiert; Versuchszähler gegen Endlosschleifen nach Abbrüchen; Analyseversion und Grenzwerte im Fingerprint; laufender Task abbrechbar. Regressionstest: `tests/Standalone/plugin-source-analysis.php`.
- [x] **Nur noch ein Scanner** (1.3.36-4): Die Shortcode-/Blockanalyse liest keine Plugin-Quellen mehr, sondern nutzt die gespeicherten Ergebnisse des Nutzungsindex. `PluginSourceRegistrationIndex` und die zugehörige Einstellung sind entfernt. Dabei behoben: Der alte Index prüfte das Sperrergebnis auf `null` statt auf ein leeres Token und scannte deshalb auch ohne Sperre.
- [x] **Plugin-/Theme-Detailseiten ohne synchrone Website-Metriken** (1.3.36-1): Zeilen aus dem letzten Dashboard-Metriklauf statt `getSiteOverviewMetrics()` für jede nutzende Website (bis zu 800 Websites pro Request).
- [x] **Grundaufwand pro Request** (1.3.36-1): `AnalysisLifecycle::register()` prüft den Wartungsjob nur auf der Hauptwebsite ohne `switch_to_blog()`; der Sprachkatalog wird bei anonymen Frontendaufrufen nicht mehr geladen.
- [x] **Website-Suche** (1.3.36-3): über `wp_blogs` und den gespeicherten Dashboard-Datensatz statt bis zu 500 `get_blog_option()`-Aufrufen; findet alle Websites.
- [x] **Laufzeitwidget der Speicheranalyse** (1.3.36-3): liest die aggregierte Netzwerkoption `rrze_msm_storage_analysis_performance_runs`.
- [x] **Aufräumen** (1.3.36-3): `rebuildDashboardData()` samt nur dort genutzter Hilfsmethoden entfernt; falsche Prozesssperren-Übergabe im Nutzungsindex korrigiert.
- [x] **Laufzeitdiagnose auf der Seite Umgebung** (1.3.36-2): Dauer und Spitzenspeicher je Hook, beobachtete Parallelität, zurückgestellte Läufe, PHP-Laufzeit des Cron-Skripts, Cron-Rückstand der Hauptwebsite, Datenbank- und Sperrdiagnose.
- [x] **Shortcode-/Blockanalyse** (1.3.36-5): Blöcke pro Typ und Beitrag mit Vorkommensanzahl; Ergebnislimit einstellbar und nur einmal gemeldet.
- [x] **Protokollierung** (1.3.36-6): Zwischenschritte von Hintergrundläufen nur mit „Feingranulares Debugging“.
- [x] **Statische Quellcodeanalyse optional** (1.3.37-1): standardmäßig aus; der Nutzungsindex erfasst dann nur die Plugin- und Theme-Nutzung. Bereits gespeicherte Ergebnisse bleiben erhalten.

## Bewusst verworfen

- Fortsetzbare Dashboard-Komposition und gebündelte Statistikverdichtung: etwa 1.000 Einzelabfragen in einem täglichen bzw. wöchentlichen Cronlauf sind für die Datenbank unerheblich.
- Faire Verteilung, reservierte Plätze, Grenzen je Prozessklasse.
- Eigene Testmultisite mit 500 Websites und vollständige WordPress-PHPUnit-Infrastruktur als Rolloutvoraussetzung; ersetzt durch die Punkte unter 2 und 3.
- Pauschaler Umbau von `MetricsImplementationService` ohne funktionalen Anlass.
