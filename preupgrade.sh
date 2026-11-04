#!/bin/sh
# HomePod4Lox - preupgrade.sh. Laeuft als loxberry VOR dem Kopieren.
# - Upgrade-Marke neben dem Datenordner: der Endpunkt antwortet solange AKTUALISIERUNG und startet nichts;
# - laufende Ausgabe (argumentweise erkannt) beenden;
# - Konfiguration in die Zweitschrift neben dem Konfigordner sichern, nur wenn sie Einstellungen traegt.
# Die venv liegt NEBEN dem Datenordner und bleibt: miniaudio hat fuer aarch64 kein fertiges Wheel, ein Neubau kostet Minuten.
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
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(hp_wurzel_suchen "$SELF") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts gesichert."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Ansagen bis zum Ende der Aktualisierung gesperrt."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen."
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
        echo "<INFO> Laufende Ausgabe beendet (PID $hp_n)."
    fi
done
CF="$BASE/config/plugins/$PFOLDER/homepod4lox.cfg"
BK="$BASE/config/plugins/$PFOLDER.backup.cfg"
if grep -Eq '^(sprechtoken|aktionstoken)="[^"]+"' "$CF" 2>/dev/null; then
    if cp "$CF" "$BK.neu" 2>/dev/null && chmod 600 "$BK.neu" 2>/dev/null && mv -f "$BK.neu" "$BK" 2>/dev/null; then
        echo "<OK> Konfiguration gesichert."
    else
        rm -f "$BK.neu"
        echo "<WARNING> Die Konfiguration liess sich nicht sichern ($BK)."
    fi
elif [ -f "$BK" ]; then
    echo "<INFO> Die Konfiguration traegt keine Einstellungen - die vorhandene Zweitschrift bleibt unberuehrt."
fi
# Die Geraeteliste ueberlebt das Upgrade nur neben dem Datenordner; sonst antwortete jedes Plugin bis zur naechsten Suche KEINE_GERAETE.
GL="$BASE/data/plugins/$PFOLDER/geraete.json"
GK="$BASE/data/plugins/$PFOLDER.geraete.json"
if [ -s "$GL" ]; then
    if cp "$GL" "$GK.neu" 2>/dev/null && chmod 600 "$GK.neu" 2>/dev/null && mv -f "$GK.neu" "$GK" 2>/dev/null; then
        echo "<OK> Geraeteliste gesichert."
    else
        rm -f "$GK.neu"
        echo "<WARNING> Die Geraeteliste liess sich nicht sichern; nach dem Upgrade bitte Geraete neu suchen."
    fi
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
