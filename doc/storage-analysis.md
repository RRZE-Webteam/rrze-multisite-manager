# Speicheranalyse

## Zweck

Die Speicheranalyse untersucht das Upload-Verzeichnis und die Medienbibliothek einer einzelnen Website. Sie steht im Multisite Manager und - mit lokaler Berechtigung - auch über **Medien > Speicheranalyse** der jeweiligen Website zur Verfügung.

## Bereiche

* **Analyse:** Speicherverbrauch, Dateitypen, größte Dateien und Verzeichnisse sowie potenziell verwaiste Dateien.
* **Fehlende Metadaten:** Medien und Dateien, für die erwartete Metadaten nicht vollständig vorliegen. Hover-Aktionen führen zu Mediendatei-Details und zur Bearbeitung in der jeweiligen Mediathek.
* **Mediendatei-Details:** Technische Daten einer Datei, Vorschau, Link zur Bearbeitung in der Quell-Website und bei Bildern eine optionale TinEye-Rückwärtssuche.

Die angezeigte Differenz zwischen WordPress-Speicherangabe und gefundenem Upload-Volumen wird direkt bei der gefundenen Speichergrösse ausgewiesen.

## Ausführung

Kleine Websites können innerhalb begrenzter Browser-Batches untersucht werden. Große Websites werden ausschließlich durch fortsetzbare Hintergrundaufgaben verarbeitet. Die Analyse arbeitet schrittweise über Upload-Struktur, Medienindex, Metadaten und gegebenenfalls Referenzprüfungen; sie versucht nicht, Zehntausende Dateien in einem Request abzuarbeiten.

Geplante Speicheranalysen werden im Tab **Monitoring > Speicheranalysen** eingerichtet. Je nach Medienanzahl und gemessener Laufzeit wird eine Website einem Sammelbatch oder einer eigenen Aufgabe zugeordnet.

## Vorsicht bei verwaisten Dateien

Eine potenziell verwaiste Datei ist nicht automatisch unbenutzt. Referenzen können aus Themes, Plugins, Widgets, Blockattributen oder nicht untersuchten Metafeldern stammen. Löschaktionen sind daher bewusst einzeln geschützt und sollten erst nach fachlicher Prüfung erfolgen.

## Implementierung und Persistenz

`Dashboard::renderSiteStorageAnalysisPage()` verwendet `StorageAnalysisService`, `StorageAnalysisStateService` und `StorageAnalysisResultService`. Das Template ist `templates/site-storage-analysis-page.php`; Detailansichten einer Datei verwenden `templates/site-storage-attachment-debug.php`.

Das dauerhafte Ergebnis liegt pro Website in den nicht autoloadenden Options `rrze_msm_site_storage_analysis_result`, `rrze_msm_site_storage_analysis_result_meta` und `rrze_msm_site_media_metadata_analysis_result`. Laufende Prozessdaten liegen in versionierten Network Transients; die vollständigen Schlüsselmuster, Cron-Hooks, Scheduler-Optionen und Sperren stehen in [Laufzeitdaten und Scheduler](runtime-state.md#speicheranalyse).

Die Analyse verwendet feste Teilgrenzen: 250 Upload- beziehungsweise Attachment-Index-Einträge, 50 Metadaten-Einträge, zehn Dateien pro Orphan-Prüfschritt und maximal 500 gespeicherte Metadaten-Ergebniszeilen. Der dauerhafte Storage-Ergebniswert wird bei mehr als 2 MiB serialisierter Daten kompaktiert.
