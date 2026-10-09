# Theme-Details

## Zweck

Die Seite **Theme-Details** beschreibt ein einzelnes Theme und seine Verwendung im Netzwerk.

## Inhalte

Sie zeigt Metadaten wie Name, Version, Autor, Beschreibung und Screenshot sowie die Websites, die dieses Theme verwenden. Bei Block-Themes kann die Detailansicht auf den Site Editor der jeweiligen Website verweisen.

## Quellcodeanalyse

Eine optionale Quellcodeanalyse ist nur eine explizit angeforderte Detailfunktion. Sie wird nicht bei der Initialisierung des Plugins, beim Dashboard-Aufruf oder durch die regulären Metrik-Batches gestartet.

## Implementierung

`Dashboard::renderThemeDetailsPage()` verwendet den Nonce-Namensraum `rrze_msm_source_analysis_theme_<stylesheet>`. Der Cache-Schlüssel folgt dem Muster `rrze_msm_theme_details_<detail-version>_<hash>` und kombiniert Stylesheet, Analysemodus und Theme-Fingerprint.
