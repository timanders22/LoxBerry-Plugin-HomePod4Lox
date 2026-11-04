#!/bin/sh
# HomePod4Lox - postupgrade.sh. Laeuft als loxberry NACH postinstall: Konfiguration zurueck, Marke weg (auch bei Abbruch: trap).
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts zurueckgespielt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
hp_marke_weg() { rm -f "$MARKE"; }
trap hp_marke_weg EXIT
CF="$BASE/config/plugins/$PFOLDER/homepod4lox.cfg"
BK="$BASE/config/plugins/$PFOLDER.backup.cfg"
hp_traegt() { grep -Eq '^(sprechtoken|aktionstoken)="[^"]+"' "$1" 2>/dev/null; }
if ! hp_traegt "$CF" && hp_traegt "$BK"; then
    mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
    if cp "$BK" "$CF.neu" 2>/dev/null && chmod 600 "$CF.neu" 2>/dev/null && mv -f "$CF.neu" "$CF" 2>/dev/null; then
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
    else
        rm -f "$CF.neu"
        echo "<WARNING> Die Konfiguration liess sich nicht zurueckspielen - die Zweitschrift liegt unter $BK."
    fi
fi
# War vorher nichts da, entsteht das Sprechtoken jetzt. Ein bewusst geloeschtes bleibt leer: dann traegt die Datei ein aktionstoken.
if [ -f "$CF" ] && grep -q '^sprechtoken=""' "$CF" 2>/dev/null && ! grep -Eq '^aktionstoken="[^"]+"' "$CF" 2>/dev/null; then
    HP_T=$(od -An -N16 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')
    if [ "${#HP_T}" -eq 32 ] && sed "s/^sprechtoken=\"\"/sprechtoken=\"$HP_T\"/" "$CF" > "$CF.neu" 2>/dev/null \
       && chmod 600 "$CF.neu" 2>/dev/null && mv -f "$CF.neu" "$CF" 2>/dev/null; then
        echo "<OK> Sprechtoken angelegt."
    else
        rm -f "$CF.neu"
    fi
fi
[ -f "$CF" ] && chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null
GL="$BASE/data/plugins/$PFOLDER/geraete.json"
GK="$BASE/data/plugins/$PFOLDER.geraete.json"
if [ -s "$GK" ]; then
    mkdir -p "$BASE/data/plugins/$PFOLDER" 2>/dev/null
    if [ ! -s "$GL" ] && mv -f "$GK" "$GL" 2>/dev/null; then
        chmod 600 "$GL" 2>/dev/null
        echo "<OK> Geraeteliste wiederhergestellt."
    else
        rm -f "$GK"
    fi
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
