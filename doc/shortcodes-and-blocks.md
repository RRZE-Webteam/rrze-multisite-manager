# Shortcodes und Blöcke

## Zweck

Die Seite ermittelt die in Inhalten einer Website vorkommenden Shortcodes und Blöcke. Neben dem Eintrag selbst kann die Ausgabe Informationen zu Registrierung, Anbieter und Fundstellen enthalten.

## Zugänge

Superadmins erreichen die netzwerkweite Seite im Multisite Manager. Für lokale Administratoren steht eine eingeschränkte Seite unter **Werkzeuge > Shortcodes und Blöcke** ihrer Website zur Verfügung, sofern sie die Voraussetzungen erfüllen.

## Bedienung

Die Tabs trennen Shortcodes und Blöcke. Eine Analyse kann für eine einzelne Website angefordert werden. Der Status zeigt, ob ein Ergebnis vorliegt, ob ein nächster Lauf geplant ist und welcher Ausführungsmodus verwendet wird.

## Planung und Skalierung

Die Aufgaben werden unter **Monitoring > Shortcode und Blöcke** verwaltet. Kleine Websites können Sammelbatches zugeordnet werden; größere Websites erhalten eine eigene geplante Aufgabe. Die Analyse ist fortsetzbar, zeitlich begrenzt und speichert nur die Ergebnisdaten, nicht den vollständigen gerenderten Inhalt.

## Implementierung und Persistenz

`Dashboard::renderShortcodeBlockAnalysisPage()` nutzt `ShortcodeBlockAnalysisSchedulerService` und `templates/shortcode-block-analysis-page.php`. Pro Website werden Ergebnis, Status und Zwischenzustand in den Options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status` und `rrze_msm_shortcode_block_analysis_state` gespeichert.

Der Scheduler verwendet getrennte Einzel- und Sammelbatch-Hooks, einen Legacy-Hook zur Rückwärtskompatibilität und pro Website einen Lock mit dem Präfix `rrze_msm_shortcode_block_analysis_lock_`. Die vollständige Liste der Scheduler-Options und Hooks steht in [Laufzeitdaten und Scheduler](runtime-state.md#shortcode--und-blockanalyse). Die Batch-Implementierung begrenzt sich auf 20 Websites, höchstens fünf Teilbatches pro Request und ein Request-Budget von 20 Sekunden; Fortsetzungen werden nach fünf Sekunden geplant.
