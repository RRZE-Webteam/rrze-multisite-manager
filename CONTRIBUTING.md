# Entwicklungsregeln

Für Entwicklung, Reviews und Beiträge zu diesem Plugin gilt verbindlich die [RRZE-SPEC](https://github.com/RRZE-Webteam/SPEC), insbesondere der Standard für RRZE-WordPress-Plugins. Repository-spezifische Hinweise, etwa in `AGENTS.md`, ergänzen die SPEC, dürfen ihr jedoch nicht widersprechen.

Bitte beachten Sie insbesondere:

* WordPress-APIs, WordPress-Coding-Standards und den Multisite-Kontext verwenden.
* Eingaben validieren, Berechtigungen und Nonces prüfen sowie Ausgaben erst am Ausgabepunkt escapen.
* Netzwerkweite Arbeit niemals in normalen Seitenaufrufen ausführen; sie muss begrenzt und fortsetzbar sein.
* Sichtbare Texte übersetzbar mit der Textdomain `rrze-multisite-manager` anlegen.
* Generierte Dateien nicht direkt bearbeiten. Änderungen an JavaScript und Sass erfolgen unter `src/`; danach werden die Assets erzeugt.
* Neue Hintergrundprozesse, persistente Schlüssel und Scheduler in der technischen Dokumentation unter `doc/` dokumentieren.

## Lokale Prüfungen

Nach einer Änderung bitte mindestens `npm run dev` ausführen. Details zu Build, Tests, Sprachdateien und Dokumentationspflege stehen in der [Entwicklerdokumentation](doc/development.md).

Tests dürfen ausschließlich gegen eine nicht-produktive Testdatenbank ausgeführt werden.
