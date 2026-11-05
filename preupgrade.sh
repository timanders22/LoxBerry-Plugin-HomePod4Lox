#!/bin/sh
# HomePod4Lox - preupgrade.sh. Laeuft als loxberry VOR dem Kopieren.
# - Upgrade-Marke neben dem Datenordner: der Endpunkt antwortet solange AKTUALISIERUNG und startet nichts;
# - laufende Ausgabe (argumentweise erkannt) beenden;
# - Konfiguration in die Zweitschrift neben dem Konfigordner sichern, nur wenn sie Einstellungen traegt.
# Die venv liegt NEBEN dem Datenordner und bleibt: miniaudio hat fuer aarch64 kein fertiges Wheel, ein Neubau kostet Minuten.
#
# 0.9.3: Rueckgabewert 1 ist fuer plugininstall.pl nur eine Fehlerzeile, gleich danach folgt purge_installation (:854-864, :872-874).
# Wo etwas nicht gesichert ist, endet dieses Skript deshalb mit exit 2 (&fail): Der Purge laeuft nicht, die alte Fassung bleibt.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-homepod4lox}"
BASE="${ARGV5:-$LBHOMEDIR}"
hp_wurzel_suchen() {
    hp_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    hp_i=0
    while [ -n "$hp_v" ] && [ "$hp_v" != "/" ] && [ "$hp_i" -lt 8 ]; do
        if [ -d "$hp_v/config/plugins" ] && [ -d "$hp_v/data/plugins" ] && [ -f "$hp_v/config/system/general.json" ]; then
            echo "$hp_v"
            return 0
        fi
        hp_v=$(dirname "$hp_v")
        hp_i=$((hp_i + 1))
    done
    return 1
}
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    BASE=$(hp_wurzel_suchen "$SELF") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<FAIL> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts gesichert, die Aktualisierung wird abgebrochen."
    exit 2
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER' - nichts getan, die Aktualisierung wird abgebrochen."; exit 2 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
# 0.9.3: Bauart Govee 0.9.34. Die Umlenkung in { } faengt auch die Meldung der Schale. Es zaehlen das Schreiben UND der gelesene Inhalt:
# Bis 0.9.2 genuegte [ -s ], das auch die alte Marke eines abgebrochenen Laufs in einem schreibgeschuetzten Ordner bestand.
if { date +%s > "$MARKE"; } 2>/dev/null && grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Ansagen bis zum Ende der Aktualisierung gesperrt."
else
    # Ohne Marke hielte preinstall das Upgrade fuer eine Neuinstallation: Zweitschrift beiseite, neues Token, Einstellungen weg.
    rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
HP_AUSG="$BASE/bin/plugins/$PFOLDER/hp_ausgabe.py"
hp_ist_lauf() {
    [ -r "/proc/$1/cmdline" ] || return 1
    hp_roh=$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null)
    printf '%s\n' "$hp_roh" | grep -Fxq "$HP_AUSG"
}
for hp_d in /proc/[0-9]*; do
    hp_n=${hp_d#/proc/}
    if [ "$hp_n" != "$$" ] && hp_ist_lauf "$hp_n"; then
        kill "$hp_n" 2>/dev/null
        hp_w=0
        while hp_ist_lauf "$hp_n" && [ "$hp_w" -lt 3 ]; do
            sleep 1
            hp_w=$((hp_w + 1))
        done
        hp_ist_lauf "$hp_n" && kill -9 "$hp_n" 2>/dev/null
        echo "<INFO> Laufende Ausgabe beendet (PID $hp_n)."
    fi
done
# 0.9.3: atomar, Bauart Govee 0.9.34 (gv_hinlegen): Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600 BEVOR Inhalt hineinkommt, wird
# mit cmp verglichen und erst dann mit mv an ihren Platz gestellt. Ein Verzeichnis als Ziel ist ein Fehlschlag (mv legte sonst hinein und
# meldete Erfolg). Vorweg faellt, was ein abgeschossener frueherer Lauf liegen liess, auch das .neu bis 0.9.2: Es kann ein Token tragen.
# Anders als im Vorbild (bash) steht das Anlegen in ( ): Dieses Skript laeuft unter dash, und dort beendet eine gescheiterte Umlenkung am
# Sonderbefehl ":" die ganze Schale mit rc 2, auch in { }; die Marke bliebe liegen, ohne ein Wort (gemessen, Fall R in upgrade_093.sh).
hp_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    hp_neu="$2.neu.$$"
    rm -f "$2.neu" "$2".neu.* 2>/dev/null
    if ( : > "$hp_neu" ) 2>/dev/null && chmod 600 "$hp_neu" 2>/dev/null \
       && cp "$1" "$hp_neu" 2>/dev/null && cmp -s "$1" "$hp_neu" \
       && mv -f "$hp_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$hp_neu" 2>/dev/null
    return 1
}
# 0.9.3: Ein Fehlschlag bei einer Datei MIT Inhalt wird gesammelt; nach allen Sicherungen faellt die Marke, und das Skript endet mit exit 2
# (Schluss der Datei). Bis 0.9.2 stand hier eine <WARNING>, exit 0 meldete Erfolg, und der Purge loeschte das Original.
HP_SICH_FEHL=""
CF="$BASE/config/plugins/$PFOLDER/homepod4lox.cfg"
BK="$BASE/config/plugins/$PFOLDER.backup.cfg"
if grep -Eq '^(sprechtoken|aktionstoken)="[^"]+"' "$CF" 2>/dev/null; then
    if hp_hinlegen "$CF" "$BK"; then
        echo "<OK> Konfiguration gesichert."
    else
        echo "<WARNING> Die Konfiguration liess sich nicht sichern ($BK)."
        HP_SICH_FEHL="$HP_SICH_FEHL Konfiguration"
    fi
else
    # Beschaedigt oder ohne Token: das Upgrade ersetzt die Datei, deshalb bleibt ihr Stand zum Nachlesen liegen.
    if [ -s "$CF" ]; then
        if hp_hinlegen "$CF" "$BK.kaputt"; then
            echo "<WARNING> Die Konfiguration traegt kein Token - ihr Stand liegt als $(basename "$BK").kaputt daneben."
        else
            echo "<WARNING> Die Konfiguration traegt kein Token, und ihr Stand liess sich nicht sichern ($BK.kaputt)."
            HP_SICH_FEHL="$HP_SICH_FEHL Konfigurationsstand"
        fi
    fi
    [ -f "$BK" ] && echo "<INFO> Die Konfiguration traegt keine Einstellungen - die vorhandene Zweitschrift bleibt unberuehrt."
fi
# Die Geraeteliste ueberlebt das Upgrade nur neben dem Datenordner; sonst antwortete jedes Plugin bis zur naechsten Suche KEINE_GERAETE.
GL="$BASE/data/plugins/$PFOLDER/geraete.json"
GK="$BASE/data/plugins/$PFOLDER.geraete.json"
if [ -s "$GL" ]; then
    if hp_hinlegen "$GL" "$GK"; then
        echo "<OK> Geraeteliste gesichert."
    else
        echo "<WARNING> Die Geraeteliste liess sich nicht sichern ($GK)."
        HP_SICH_FEHL="$HP_SICH_FEHL Geraeteliste"
    fi
fi
# 0.9.3: Mit exit 2 endet die Installation hier, postinstall.sh und postupgrade.sh laufen nie und raeumten Marke und Nebendatei nie weg;
# deshalb hier. Bliebe die Nebendatei liegen, spielte ein spaeteres Upgrade den alten Stand zurueck, sobald die Liste dann leer ist.
if [ -n "$HP_SICH_FEHL" ]; then
    rm -f "$MARKE" "$GK" 2>/dev/null
    echo "<FAIL> Nicht gesichert:$HP_SICH_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die"
    echo "<FAIL> Originale nicht loescht. Die bisherige Fassung bleibt installiert. Platz und Rechte unter"
    echo "<FAIL> $BASE/config/plugins und $BASE/data/plugins pruefen."
    exit 2
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
