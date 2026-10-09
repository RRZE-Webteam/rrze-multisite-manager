# Speicheranalyse

## Zweck

Die Speicheranalyse untersucht das Upload-Verzeichnis und die Medienbibliothek einer einzelnen Website. Sie steht im Multisite Manager und - mit lokaler Berechtigung - auch ueber **Medien > Speicheranalyse** der jeweiligen Website zur Verfuegung.

## Bereiche

* **Analyse:** Speicherverbrauch, Dateitypen, groesste Dateien und Verzeichnisse sowie potenziell verwaiste Dateien.
* **Fehlende Metadaten:** Medien und Dateien, fuer die erwartete Metadaten nicht vollstaendig vorliegen. Hover-Aktionen fuehren zu Mediendatei-Details und zur Bearbeitung in der jeweiligen Mediathek.
* **Mediendatei-Details:** Technische Daten einer Datei, Vorschau, Link zur Bearbeitung in der Quell-Website und bei Bildern eine optionale TinEye-Rueckwaertssuche.

Die angezeigte Differenz zwischen WordPress-Speicherangabe und gefundenem Upload-Volumen wird direkt bei der gefundenen Speichergrösse ausgewiesen.

## Ausfuehrung

Kleine Websites koennen innerhalb begrenzter Browser-Batches untersucht werden. Grosse Websites werden ausschliesslich durch fortsetzbare Hintergrundaufgaben verarbeitet. Die Analyse arbeitet schrittweise ueber Upload-Struktur, Medienindex, Metadaten und gegebenenfalls Referenzpruefungen; sie versucht nicht, Zehntausende Dateien in einem Request abzuarbeiten.

Geplante Speicheranalysen werden im Tab **Monitoring > Speicheranalysen** eingerichtet. Je nach Medienanzahl und gemessener Laufzeit wird eine Website einem Sammelbatch oder einer eigenen Aufgabe zugeordnet.

## Vorsicht bei verwaisten Dateien

Eine potenziell verwaiste Datei ist nicht automatisch unbenutzt. Referenzen koennen aus Themes, Plugins, Widgets, Blockattributen oder nicht untersuchten Metafeldern stammen. Loeschaktionen sind daher bewusst einzeln geschuetzt und sollten erst nach fachlicher Pruefung erfolgen.

## Implementierung und Persistenz

`Dashboard::renderSiteStorageAnalysisPage()` verwendet `StorageAnalysisService`, `StorageAnalysisStateService` und `StorageAnalysisResultService`. Das Template ist `templates/site-storage-analysis-page.php`; Detailansichten einer Datei verwenden `templates/site-storage-attachment-debug.php`.

Das dauerhafte Ergebnis liegt pro Website in den nicht autoloadenden Options `rrze_msm_site_storage_analysis_result`, `rrze_msm_site_storage_analysis_result_meta` und `rrze_msm_site_media_metadata_analysis_result`. Laufende Prozessdaten liegen in versionierten Network Transients; die vollstaendigen Schluesselmuster, Cron-Hooks, Scheduler-Optionen und Sperren stehen in [Laufzeitdaten und Scheduler](runtime-state.md#speicheranalyse).

Die Analyse verwendet feste Teilgrenzen: 250 Upload- beziehungsweise Attachment-Index-Eintraege, 50 Metadaten-Eintraege, zehn Dateien pro Orphan-Pruefschritt und maximal 500 gespeicherte Metadaten-Ergebniszeilen. Der dauerhafte Storage-Ergebniswert wird bei mehr als 2 MiB serialisierter Daten kompaktiert.
