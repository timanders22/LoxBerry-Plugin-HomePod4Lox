# HomePod4Lox

Gibt Sprachansagen anderer LoxBerry-Plugins über AirPlay auf einem Apple HomePod oder HomePod mini aus.

**Ohne HomePod gebaut, nicht am Gerät gemessen, Rückmeldung erbeten.** Gemessen ist alles gegen eine AirPlay-Attrappe (die Attrappe aus den Tests von pyatv), nicht an einem echten HomePod. Ob man die Ansage hört, ist offen. Bitte melden, was bei Ihnen geschieht; im Reiter Test stehen dafür die Merkmale des Geräts und das Ergebnis der letzten Ansage. Beides trägt keine Adressen.

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
