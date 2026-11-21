# HomePod4Lox

Gibt Sprachansagen anderer LoxBerry-Plugins über AirPlay auf einem Apple HomePod oder HomePod mini aus.

**Ohne HomePod gebaut, nicht am Gerät gemessen, Rückmeldung erbeten.** Gemessen ist alles gegen eine AirPlay-Attrappe (die Attrappe aus den Tests von pyatv), nicht an einem echten HomePod. Ob man die Ansage hört, ist offen. Bitte melden, was bei Ihnen geschieht; im Reiter Test stehen dafür die Merkmale des Geräts und das Ergebnis der letzten Ansage. Beides trägt keine Adressen.

## Neu in 0.9.9

- Die Kopfzeile zeigt jetzt die installierte Fassung, einheitlich in allen Plugins.

## Neu in 0.9.8

- **Einstellungen sichern und zurückspielen mit dem gemeinsamen Sicherungsmodul 1.2.11 statt 1.2.9:** Neu in 1.2.10 ist, dass ein leerer Wert auch in einem Teil der Sicherung, der nur aus einem einzelnen Wert besteht (etwa einem Zugangsschlüssel in einer eigenen Datei), einen gesetzten Wert nicht mehr überschreibt; er bleibt stehen und wird in der Meldung genannt. Dieses Plugin führt keinen solchen Teil; an seinem Verhalten ändert sich dadurch nichts. Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4 (3 Vorfassungen zurückgespielt). Am Gerät nicht gemessen.
- **Sicherungsmodul 1.2.11:** Wandelt ein Plugin beim Zurückspielen eine Sicherung aus einer Textdatei um, sagt die Meldung das jetzt mit einem gemeinsamen Satz, und Mängel darin werden in allen Plugins mit denselben Bezeichnungen genannt. Dieses Plugin wandelt keine Textdateien um; an seinem Verhalten ändert sich dadurch nichts. Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4. Am Gerät nicht gemessen.

## Neu in 0.9.7

- **Verhalten geändert – Einstellungen sichern und zurückspielen mit dem gemeinsamen Sicherungsmodul 1.2.9 statt 1.2.2:**
  - Eine Sicherungsdatei, die ein Editor mit einer unsichtbaren Markierung am Anfang (UTF-8-BOM) gespeichert hat, wird jetzt gelesen wie ohne sie. Bis 0.9.6 wurde sie als nicht lesbare Sicherung abgewiesen.
  - Einträge der geltenden Einstellungen, die diese Fassung nicht kennt (etwa aus einer Bearbeitung von Hand), fallen beim Zurückspielen weiter weg, werden jetzt aber mit ihrem Namen genannt, nie mit ihrem Wert. Bis 0.9.6 fielen sie still weg.
  - Jede unbekannte Einstellung wird in der Meldung genau einmal genannt.
  - Die übrigen Neuerungen der Modulfassungen bis 1.2.9 betreffen Fähigkeiten, die dieses Plugin nicht nutzt; an ihm ändert sich dadurch nichts.
- **Knopf „Dokumentation“:** `plugin.cfg` nennt jetzt `WEBSITE=` mit diesem Repository; LoxBerry übernimmt die Adresse bei der Installation und zeigt sie in der Plugin-Verwaltung als Knopf.
- Sicherungen der früheren Fassungen werden weiter angenommen wie bisher; gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4, der über einen Webserver die echten Knöpfe drückt und 3 Vorfassungen zurückspielt. Am Gerät nicht gemessen.

## Neu in 0.9.6

- **Verhalten geändert – Einstellungen sichern und zurückspielen nach der gemeinsamen Vorlage:** Die Knöpfe „Einstellungen sichern“ und „Einstellungen zurückspielen“ arbeiten jetzt mit demselben Sicherungsmodul wie die übrigen Plugins dieser Reihe. Für den Anwender ändert sich:
  - Die Sicherungsdatei trägt im Kopf wie bisher das Kennzeichen des Plugins (`_plugin`, `HomePod4Lox`) und neu die Fassung (`_fassung`). Eine Datei eines anderen Plugins und eine Datei ohne Kennzeichen werden als Ganzes abgewiesen; es ändert sich dann nichts. Sicherungen dieses Plugins tragen das Kennzeichen seit 0.9.0 und werden weiter angenommen.
  - Eine Einstellung, die diese Fassung nicht kennt, wird nicht übernommen und mit ihrem Namen gemeldet, nie mit ihrem Wert. Die übrigen Einstellungen werden zurückgespielt. Bis 0.9.5 wies ein unbekannter Name die ganze Datei ab.
  - Ein leerer Wert in der Sicherung überschreibt keinen bestehenden; der bisherige Wert bleibt, und die Meldung nennt den Namen. Bisher galt das nur für das Aktionstoken.
- Unverändert: Eine Datei mit einem unzulässigen Wert ändert gar nichts, und alle Beanstandungen werden genannt. Beide Token gehören in die Datei – das Sprechtoken ist das Token, mit dem andere Plugins über dieses Plugin sprechen. Nach dem Zurückspielen zieht die Zweitschrift mit, und die Meldung sagt, dass es keinen dauernd laufenden Dienst gibt.
- **Haken beim Zurückspielen aus dem gemeinsamen Sicherungsmodul:** Der Haken „Ja, die geltenden Einstellungen durch die Sicherung ersetzen“ zwischen dem Feld für die Sicherungsdatei und dem Knopf „Einstellungen zurückspielen“ bleibt Pflicht, ab Werk aus; ohne ihn wird die Datei nicht gelesen und nichts geändert, und die Meldung sagt es. Neu ist, dass Haken, Text und Prüfung samt der Prüfung der hochgeladenen Datei (über das Formular gekommen, höchstens 256 KB) aus dem gemeinsamen Sicherungsmodul 1.1.0 kommen und in allen Plugins dieser Reihe gleich sind; bis 0.9.5 hatte dieses Plugin dafür eigene Texte. Die Grenze für die Datei lag bis 0.9.5 bei 64 KB.
- **Gemeinsames Sicherungsmodul 1.2.0:** Das Modul kann jetzt, was einzelne Plugins dieser Reihe bisher selbst darum herum gebaut haben (Entscheidung „Einheitlichkeit“). An diesem Plugin ändert sich dadurch nichts.
- **Gemeinsames Sicherungsmodul 1.2.1:** Ein Vermerk, der nie aus einer Sicherungsdatei kommt (etwa, dass ein Sprechtoken aus einem Nachbar-Plugin übernommen wurde), behält beim Zurückspielen seinen geltenden Wert. Dieses Plugin führt keinen solchen Vermerk; an ihm ändert sich nichts.
- **Gemeinsames Sicherungsmodul 1.2.2:** Fehlt eine Einstellung in der Sicherung und setzt das Plugin ihren Wert nach einer eigenen Übergangsregel (etwa aus einer verwandten Einstellung derselben Sicherung), bleibt dieser Wert beim Zurückspielen, und die Meldung nennt ihn als nach einer Übergangsregel abgeleitet – nicht als Werkseinstellung und nicht als bisherigen Wert. An diesem Plugin ändert sich dadurch nichts.
- Gemessen mit einem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4, der über einen Webserver die echten Knöpfe drückt, mit den Sicherungen der Fassungen 0.9.5, 0.9.3 und 0.9.0; am Gerät nicht gemessen.

## Was das Plugin tut

- **Sprachziel für andere Plugins:** lokaler HTTP-Endpunkt mit Token, Bauart wie Chromecast 4 Lox NG.
- **Weg:** AirPlay (RAOP) über die freie Bibliothek [pyatv](https://pyatv.dev) 0.18.0 (MIT). Apple bietet keine offizielle Schnittstelle; pyatv nennt `stream_file` selbst „incubating“.
- **Kein Dauerdienst:** Das Plugin startet nur für eine Ansage und endet danach. Ohne Gerät und ohne Auftrag läuft nichts, es gibt keinen Cron-Eintrag. Auch die Gerätesuche läuft nur auf Knopfdruck oder einmal nach einem Ausfall (höchstens alle 5 Minuten).
- **Sprachquelle:** ab Werk lokal mit espeak-ng, ohne Internet, klingt maschinell. Wahlweise Google Translate: Dann gehen die Ansagetexte an Google, und die Schnittstelle ist nicht zugesichert.
- **Warteschlange:** höchstens 20 Aufträge; eine Ansage, die länger als 120 s warten musste, verfällt (einstellbar). Gleicher Text an dasselbe Ziel binnen 30 s wird nicht wiederholt; höchstens 60 Ansagen je Stunde (einstellbar).
- **Ausfall:** Der Endpunkt prüft die Erreichbarkeit und antwortet `503 GERAETE_OFFLINE`; eine Ansage wird nie still auf einen anderen Lautsprecher umgeleitet. Was erst danach schiefgeht, steht im Reiter Test und unter `?aktion=status`.
- **Ab Werk ein:** Die Sprachausgabe ist ab Werk eingeschaltet, ohne gefundenes Gerät geschieht nichts. Ausschalten im Reiter Einstellungen.

## Was am HomePod einzustellen ist

1. Home-App auf iPhone oder iPad: Einstellungen des Zuhauses, „Zugriff auf Lautsprecher und TV erlauben“ auf **„Jeder“** oder **„Jeder im selben Netzwerk“** stellen. **Kein Kennwort.**
2. HomePod neu starten.
3. LoxBerry und HomePod müssen im selben Netz liegen (mDNS, Bonjour). Router trennen oft WLAN und LAN.

Bei „Nur Personen in diesem Zuhause“ meldet das Plugin `KOPPLUNG_NOETIG`. Eine Kopplung über Code gibt es nicht: Der HomePod hat keine Anzeige. Ein Kennwort trägt das Plugin nicht; es meldet `KENNWORT_NOETIG`.

## Grenzen

Aus der Dokumentation von pyatv und fremden Berichten, **nichts davon hier gemessen**:

- Stereopaar: Laut einem Bericht aus der Home-Assistant-Gemeinde und dem Plugin homebridge-homepod-radio geht es nicht verlässlich. Im Zweifel ein einzelnes Gerät ansprechen oder das Paar auflösen.
- Gruppen aus der Home-App sieht das Plugin nicht. Mehrere Namen oder `alle` spielen nacheinander gestartet, nicht lippensynchron.
- Eine laufende Wiedergabe wird unterbrochen und nicht fortgesetzt.
- Lautstärke: Ab Werk wird sie nicht verstellt. Ob ein gesetzter Wert nach der Ansage zurückgeht, ist nicht bekannt.
- Ein stiller Vorlauf von 500 ms ist Vorsorge gegen einen abgeschnittenen Anfang.

## Installation

Das Plugin braucht beim ersten Einspielen Internet: `postinstall.sh` legt eine virtuelle Umgebung an und lädt pyatv aus PyPI. Fremder Code liegt nicht im Archiv. **Für aarch64 (Raspberry Pi 4/5, LoxBerry 4) gibt es `miniaudio` nicht als fertiges Paket; es wird auf dem Gerät übersetzt.** Das dauert einige Minuten (nicht gemessen), braucht `gcc` und `python3-dev` aus `dpkg/apt` und rund 75 MB (auf x86_64 gemessen). Bei einem Upgrade bleibt die Umgebung liegen. 32-Bit-Geräte (armv7) sind nicht geprüft.

## Für Entwickler anderer Plugins

```
POST http://127.0.0.1:<Webport>/plugins/homepod4lox/index.php
aktion=sprechen&token=<T>&text=<Text>[&geraet=<Name|Name,Name|alle>][&laut=1-100]
```

Nur von diesem LoxBerry. Gesendet ist `HTTP 200` mit Zeilenanfang `SPRECHEN;OK=1`; das heißt eingereiht, nicht gesprochen. Die Schnittstelle gleicht Chromecast 4 Lox NG. Das Token steht in `config/plugins/homepod4lox/homepod4lox.cfg` (`sprechtoken`), das gemeinsame Sprachmodul liest es von dort.

## Herkunft und Lizenz

Eigenständiges Plugin (MIT, siehe `LICENSE`). Es lädt beim Einspielen pyatv (MIT, Pierre Ståhl) und miniaudio (MIT) aus PyPI; siehe `NOTICE`. „Apple“, „HomePod“ und „AirPlay“ sind Marken von Apple Inc.; dieses Plugin hat mit Apple nichts zu tun.

## Neu in 0.9.5

- **Marke der Aktualisierung ohne PHP-Erweiterung ctype:** Ob gerade eine Aktualisierung läuft, prüfte das Plugin mit einer Funktion der Erweiterung ctype. Fehlt sie, galt die Marke unter PHP 7.4 nie (das Plugin sprach und vervollständigte die Konfiguration auch während einer Aktualisierung), und unter PHP 8 endete bei liegender Marke jeder Aufruf mit „Call to undefined function“. Jetzt prüft ein regulärer Ausdruck die Ziffern. Auf einem LoxBerry mit ctype (Debian liefert sie mit PHP aus) ändert sich nichts.
- **Kopf der Sicherungsdatei mit Umlauten:** „Enthält … wäre“ statt „Enthaelt … waere“.

## Neu in 0.9.4

- **Konfiguration wird vervollständigt.** Fehlt in `homepod4lox.cfg` eine Einstellung, trägt das Plugin sie jetzt einmal als Zeile mit ihrer Vorgabe nach – am Ende einer Aktualisierung (`postupgrade.sh`) und beim Öffnen der Oberfläche. Bis 0.9.3 ergänzte es sie nur beim Lesen im Speicher. Die vorhandenen Zeilen bleiben unverändert stehen, auch unbekannte und solche, deren Wert das Plugin nicht annimmt; angehängt wird nur, was fehlt, mit den Zeilenenden der Datei. Die Einstellungen, mit denen das Plugin arbeitet, ändern sich dadurch nicht.
- Geschrieben wird nur in eine Datei mit gültigem Aktionstoken, nicht während einer Aktualisierung, mit Rechten 0600 und derselben Zweitschrift wie beim Speichern. Ein fehlendes Sprechtoken wird nicht als leere Zeile nachgetragen: es entsteht wie bisher beim Öffnen der Oberfläche. Das Protokoll nennt die Namen der nachgetragenen Einstellungen, nie ihre Werte.
- Geprüft auf dem Arbeitsplatz unter PHP 7.4 und 8.4 mit einem Prüfstand in nachgebildeter Gestalt; am LoxBerry noch nicht gemessen.

## Neu in 0.9.3

- **Update-Schutz:** Lassen sich die Konfiguration oder die Geräteliste vor einem Update nicht sichern (etwa volle Karte, fehlende Rechte), bricht das Update jetzt ab, und die bisherige Fassung bleibt mit allen Einstellungen installiert. Bisher lief es weiter, und der Installer löschte die Originale. Dasselbe gilt, wenn kein LoxBerry-Verzeichnis gefunden wird. Eine vorhandene Sicherung wird erst ersetzt, wenn die neue vollständig und geprüft vorliegt.
- **Gescheiterte Ansage:** Wiederholt Loxone eine Ansage, die nicht gesprochen werden konnte, wird sie binnen 30 Sekunden nicht mehr als „unverändert“ verschluckt.

## Neu in 0.9.2

- Vor einem Download wird jeder offene Ausgabepuffer geleert: PHP-Warnungen oder Reste landen nicht mehr in der heruntergeladenen Datei.

## Neu in 0.9.1

Korrekturen nach einer Fremdprüfung des Codes. Weiter ohne HomePod gebaut, nicht am Gerät gemessen.

- **Das Sprechtoken steht nicht mehr in der Seite.** Das Beispiel im Reiter „Einbindung in Loxone“ druckte es im Klartext in den HTML-Quelltext. Jetzt steht dort ein Platzhalter. Das Sprachmodul anderer Plugins liest das Token weiter selbst aus der Konfiguration.
- **Lautstärke 0 ist abgewiesen.** Der HomePod hätte stumm „gesprochen“ und das Plugin OK=1 gemeldet. Erlaubt sind 1 bis 100 oder -1 für „nicht verstellen“, wie im gemeinsamen Sprachmodul.
- **Eine fehlgeschlagene Ansage sperrt die Wiederholung nicht mehr.** Scheiterte das Einreihen, antwortete die Wiederholung 30 Sekunden lang fälschlich „OK, unverändert“.
- **Upgrade ohne Marke bricht ab.** Sonst gingen Einstellungen und Token verloren. Die bisherige Fassung bleibt dann installiert.
- **Geheimnisse nie mit offenen Rechten.** Konfiguration, Zweitschrift und Warteschlange bekommen ihre Rechte, bevor der Inhalt geschrieben wird; dasselbe gilt für die Hakenskripte.
- **Neue Adresse eines HomePod.** Fehlt ein Teil einer Gruppe, wird jetzt auch neu gesucht; vorher blieb ein umgezogener HomePod bis zur nächsten Suche draußen. Findet der Einzelscan eine neue Adresse, wird sie gemerkt.
- **Protokolle wachsen nicht mehr unbegrenzt.** `homepod4lox.log` wird auch im Sprechbetrieb gekappt, `lauf.err` ebenso; sie erscheint im Reiter „Logdateien“ und wird mit geleert.
- **Formular nach einer Beanstandung.** Haken und Sprachquelle bleiben wie eingegeben, beanstandete Felder sind markiert.
- **Kleineres:** gleiche Wurzelprüfung überall, unbekannte Schalter von `hp_ausgabe.py` werden abgewiesen, eine laufende Ausgabe wird beim Upgrade abgewartet, eine Konfiguration ohne Token bleibt als `.kaputt` lesbar liegen, die Deinstallation überschreibt Dateien mit Token vor dem Entfernen.
- **Texte:** Das gemeinsame Sprachmodul kennt das Ziel seit 1.4.0.
- **Neues Symbol.**
- **Neu: LoxBerry-Healthcheck.** `bin/healthcheck` meldet aus Dateien, ohne Netz, ob Bibliothek, Konfiguration, Token und Geräte stimmen und ob die letzte Ansage gelang.

## Änderungen

### 0.9.1

Siehe „Neu in 0.9.1“.

### 0.9.0

Erste Fassung, ohne HomePod gebaut, nicht am Gerät gemessen, Rückmeldung erbeten. Sprachziel für andere Plugins über AirPlay (pyatv 0.18.0), kein Dauerdienst, lokale Sprachquelle espeak-ng, Google wahlweise.
