# Shortcodes und Bloecke

## Zweck

Die Seite ermittelt die in Inhalten einer Website vorkommenden Shortcodes und Bloecke. Neben dem Eintrag selbst kann die Ausgabe Informationen zu Registrierung, Anbieter und Fundstellen enthalten.

## Zugaenge

Superadmins erreichen die netzwerkweite Seite im Multisite Manager. Fuer lokale Administratoren steht eine eingeschraenkte Seite unter **Werkzeuge > Shortcodes und Bloecke** ihrer Website zur Verfuegung, sofern sie die Voraussetzungen erfuellen.

## Bedienung

Die Tabs trennen Shortcodes und Bloecke. Eine Analyse kann fuer eine einzelne Website angefordert werden. Der Status zeigt, ob ein Ergebnis vorliegt, ob ein naechster Lauf geplant ist und welcher Ausfuehrungsmodus verwendet wird.

## Planung und Skalierung

Die Aufgaben werden unter **Monitoring > Shortcode und Bloecke** verwaltet. Kleine Websites koennen Sammelbatches zugeordnet werden; groessere Websites erhalten eine eigene geplante Aufgabe. Die Analyse ist fortsetzbar, zeitlich begrenzt und speichert nur die Ergebnisdaten, nicht den vollstaendigen gerenderten Inhalt.

## Implementierung und Persistenz

`Dashboard::renderShortcodeBlockAnalysisPage()` nutzt `ShortcodeBlockAnalysisSchedulerService` und `templates/shortcode-block-analysis-page.php`. Pro Website werden Ergebnis, Status und Zwischenzustand in den Options `rrze_msm_shortcode_block_analysis_result`, `rrze_msm_shortcode_block_analysis_status` und `rrze_msm_shortcode_block_analysis_state` gespeichert.

Der Scheduler verwendet getrennte Einzel- und Sammelbatch-Hooks, einen Legacy-Hook zur Rueckwaertskompatibilitaet und pro Website einen Lock mit dem Praefix `rrze_msm_shortcode_block_analysis_lock_`. Die vollstaendige Liste der Scheduler-Options und Hooks steht in [Laufzeitdaten und Scheduler](runtime-state.md#shortcode--und-blockanalyse). Die Batch-Implementierung begrenzt sich auf 20 Websites, hoechstens fuenf Teilbatches pro Request und ein Request-Budget von 20 Sekunden; Fortsetzungen werden nach fuenf Sekunden geplant.
