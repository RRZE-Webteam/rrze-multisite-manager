# Plugin-Details

## Zweck

Die Seite **Plugin-Details** zeigt Metadaten und die Verwendung eines ausgewaehlten Plugins im Netzwerk.

## Inhalte

Neben Name, Version, Beschreibung, Autor und technischen Anforderungen zeigt die Seite, ob ein Plugin netzwerkweit aktiv ist und auf welchen Websites es aktiv verwendet wird. Sie verlinkt auf die zugehoerigen Website-Details.

Soweit WordPress die Informationen bereitstellt, erscheinen auch Aktualisierungsinformationen und die passenden Verwaltungslinks. Netzwerkweite Aenderungen sind nur fuer Superadmins verfuegbar.

## Quellcodeanalyse

Eine optionale Detailanalyse kann Quellcodeinformationen eines Plugins aufbereiten. Sie wird nur auf ausdrueckliche Anforderung ausgefuehrt und ist nicht Teil der regulaeren Dashboard-Metriken oder eines wiederkehrenden Vollscans.

## Implementierung

`Dashboard::renderPluginDetailsPage()` prueft die Anfrage mit dem Nonce-Namensraum `rrze_msm_source_analysis_plugin_<plugin-file>`. Der Detailcache verwendet das Muster `rrze_msm_plugin_details_<detail-version>_<hash>`; der Hash enthaelt Plugin-Datei, Analysemodus und Plugin-Fingerprint. Damit invalidiert eine relevante Datei- oder Modus-Aenderung den Detailcache ohne einen globalen Quellcodeindex aufzubauen.
