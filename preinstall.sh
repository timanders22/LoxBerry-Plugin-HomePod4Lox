#!/bin/sh
# HomePod4Lox - preinstall.sh. Laeuft als loxberry VOR dem Kopieren. $3 Ordner, $5 Wurzel.
# Nur Pruefungen. Bei einer NEUinstallation wird die Zweitschrift einer frueheren Installation beiseitegelegt: kein fremder Stand
# mit Token wird eingespielt. Nichts geloescht; die Deinstallation raeumt ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-homepod4lox}"
BASE="${ARGV5:-$LBHOMEDIR}"
if command -v python3 >/dev/null 2>&1; then
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)' 2>/dev/null; then
        echo "<OK> $(python3 --version 2>&1) - ausreichend (mindestens 3.9)."
    else
        echo "<WARNING> Python ist aelter als 3.9 - die Bibliothek pyatv laeuft dort nicht."
    fi
else
    echo "<WARNING> python3 fehlt - das Plugin kann nichts ausgeben."
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0
ZIEL="$BASE/config/plugins/$PFOLDER.backup.cfg"
if [ -e "$ZIEL" ]; then
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        chmod 600 "$ZIEL.alt" 2>/dev/null
        echo "<WARNING> Neuinstallation: die Zweitschrift einer frueheren Installation wird NICHT eingespielt; sie liegt als $(basename "$ZIEL").alt daneben."
    else
        echo "<WARNING> $ZIEL liess sich nicht beiseitelegen - bitte von Hand entfernen."
    fi
fi
exit 0
