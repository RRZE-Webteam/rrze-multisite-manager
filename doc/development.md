# Entwicklung

## Verbindliche Grundlage

Die verbindliche Grundlage für Entwicklung und Reviews von RRZE-WordPress-Plugins ist die [RRZE-SPEC](https://github.com/RRZE-Webteam/SPEC). Repository-spezifische Anweisungen in `AGENTS.md` ergänzen diese Vorgaben, dürfen ihnen aber nicht widersprechen.

Wesentliche Regeln für dieses Plugin:

* WordPress-APIs und Multisite-Kontext verwenden; Eingaben validieren, Berechtigungen und Nonces prüfen, Ausgaben erst am Ausgabepunkt escapen.
* Keine vollständigen Netzwerk-Scans in normalen Admin- oder Frontend-Requests einführen. Netzwerkweite Arbeit ist als begrenzter, fortsetzbarer Batch zu modellieren.
* Keine generierten Dateien direkt bearbeiten. Änderungen an JavaScript und Sass erfolgen in `src/`; die Ergebnisse werden nach `build/` erzeugt.
* Neue sichtbare Strings müssen den Textdomain `rrze-multisite-manager` verwenden und mit den Sprachdateien aktualisiert werden.
* Neue Hintergrundprozesse brauchen einen eindeutig benannten Hook, einen abgesicherten Zustand, Ablaufbehandlung für Sperren und Dokumentation in [Laufzeitdaten und Scheduler](runtime-state.md).

## Repository-Struktur

| Pfad | Inhalt |
| --- | --- |
| `includes/` | PHP-Klassen, Services, Scheduler und Widgets |
| `includes/Metrics/` | Metrics-Fassade, Implementierung, Caches, Detail- und Speicheranalyse-Services |
| `templates/` | PHP-Templates für Seiten und Widgets |
| `src/js/` | JavaScript-Quelldateien |
| `src/sass/` | Sass-Quelldateien |
| `build/` | generierte JavaScript- und CSS-Assets |
| `languages/` | POT-, PO- und MO-Sprachdateien |
| `tests/` | WordPress-PHPUnit-Tests und Test-Bootstrap |
| `doc/` | Entwicklerdokumentation |

## Build

`npm run dev` erstellt Entwicklungs-Assets, aktualisiert Sprachdateien, synchronisiert die Entwicklungsversionsnummer und erzeugt `readme.txt`. Dieser Befehl ist nach einer Änderung auszuführen.

Weitere Skripte:

| Befehl | Zweck |
| --- | --- |
| `npm run build` | Produktionsassets und Sprachdateien erzeugen |
| `npm run prod` | Produktionsbuild mit Versions- und `readme.txt`-Aktualisierung |
| `npm run release` | Releasebuild mit Release-Version |
| `npm run watch` | Entwicklungsassets beobachten und neu erzeugen |
| `npm test` | PHPUnit-Testlauf, wenn `vendor/bin/phpunit` installiert ist |

`readme.txt` ist generiert und wird aus `package.json` aufgebaut. `README.md` und die Dateien in `doc/` sind dagegen manuell gepflegte Dokumentation.

## Tests

Die Tests verwenden das offizielle WordPress-PHPUnit-Testframework und benötigen eine disposable Testdatenbank. `WP_TESTS_DIR` muss auf die WordPress-Testbibliothek zeigen. Details stehen in [tests/README.md](../tests/README.md).

Tests dürfen niemals gegen eine produktive WordPress-Installation oder Datenbank laufen. Neue Berechnungs-, Cache-, Scheduler- und Bereinigungslogik sollte mindestens einen Regressionstest erhalten. Wenn die lokale PHPUnit-Abhängigkeit fehlt, ist das als nicht ausgeführter Test zu dokumentieren und nicht als erfolgreicher Testlauf darzustellen.

## Übersetzungen

Die Übersetzungen liegen in `languages/`. Originalstrings im Code sind Englisch und verwenden den Textdomain `rrze-multisite-manager`.

Der Build ruft `scripts/build-languages.js` auf. Bei neuen oder geänderten Strings aktualisiert das Skript die POT-Datei sowie die vorhandenen deutschen PO- und MO-Dateien. Für einen manuellen Neuaufbau kann WP-CLI verwendet werden:

```sh
wp i18n make-pot . languages/rrze-multisite-manager.pot --domain=rrze-multisite-manager --exclude=node_modules,build,.git
```

PO-Dateien können anschließend beispielsweise mit Loco Translate gepflegt und zu MO-Dateien kompiliert werden. Generierte Sprachdateien gehören bei String-Änderungen in denselben Change wie der PHP-, JavaScript- oder Template-String.

## Dokumentationspflege

Bei neuen oder umbenannten Menü-Seiten eine Datei in `doc/` anlegen beziehungsweise aktualisieren und in [doc/README.md](README.md) verlinken. Neue persistente Optionen, Meta-Schlüssel, Transients, Cron-Hooks, Locks und AJAX-Endpunkte sind zusätzlich in [Laufzeitdaten und Scheduler](runtime-state.md) zu dokumentieren.
