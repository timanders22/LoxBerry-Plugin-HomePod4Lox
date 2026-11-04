#!/bin/sh
# HomePod4Lox - postinstall.sh. Laeuft als loxberry, auch beim Upgrade (preupgrade -> Kopie -> postinstall -> postupgrade).
# Richtet die venv neben dem Datenordner ein und laedt die Bibliothek nur aus PyPI (bin/requirements.txt). Der Paketordner bleibt
# bei Upgrades liegen, wenn er zur verlangten Fassung passt. Ohne Bibliothek kann das Plugin nichts ausgeben: das wird gesagt.
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
    echo "<FAIL> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - die Bibliothek wurde nicht eingerichtet."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$BASE/data/plugins/$PFOLDER.venv"
REQ="$PBIN/requirements.txt"
CF="$PCONFIG/homepod4lox.cfg"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
mkdir -p "$PDATA" 2>/dev/null
chmod 700 "$PDATA" 2>/dev/null
[ -d "$PCONFIG" ] && chmod 700 "$PCONFIG" 2>/dev/null
[ -f "$CF" ] && chmod 600 "$CF" 2>/dev/null
[ -f "$PBIN/hp_ausgabe.py" ] && chmod 755 "$PBIN/hp_ausgabe.py" 2>/dev/null

# Das Sprechtoken entsteht einmal, hier, damit das Sprachmodul anderer Plugins es ohne weiteren Schritt findet. Beim Upgrade kommt
# es aus der Zweitschrift (postupgrade.sh), deshalb hier nichts anlegen, solange die Marke liegt.
if [ ! -f "$MARKE" ] && [ -f "$CF" ] && grep -q '^sprechtoken=""' "$CF" 2>/dev/null; then
    HP_T=$(od -An -N16 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')
    if [ "${#HP_T}" -eq 32 ] && sed "s/^sprechtoken=\"\"/sprechtoken=\"$HP_T\"/" "$CF" > "$CF.neu" 2>/dev/null \
       && chmod 600 "$CF.neu" 2>/dev/null && mv -f "$CF.neu" "$CF" 2>/dev/null; then
        echo "<OK> Sprechtoken angelegt."
    else
        rm -f "$CF.neu"
        echo "<WARNING> Das Sprechtoken liess sich nicht anlegen; die Oberflaeche zeigt es im Reiter Test an."
    fi
fi

if ! command -v python3 >/dev/null 2>&1; then
    echo "<FAIL> python3 fehlt - das Plugin kann nichts ausgeben."
    exit 1
fi
if [ ! -f "$REQ" ]; then
    echo "<FAIL> $REQ fehlt - das Archiv ist unvollstaendig."
    exit 1
fi
SOLL=$(sed -n 's/^pyatv==\([0-9.]*\).*/\1/p' "$REQ" | head -1)
hp_bib_ist() {
    "$VENV/bin/python3" -c 'from importlib.metadata import version; import pyatv, miniaudio; print(version("pyatv"))' 2>/dev/null
}
IST=""
[ -x "$VENV/bin/python3" ] && IST=$(hp_bib_ist)
if [ -n "$SOLL" ] && [ "$IST" = "$SOLL" ]; then
    echo "<OK> AirPlay-Bibliothek pyatv $IST ist schon eingerichtet."
else
    if [ ! -x "$VENV/bin/python3" ] || ! "$VENV/bin/python3" -c 'import pip' 2>/dev/null; then
        rm -rf "$VENV"
        if ! HP_AUS=$(python3 -m venv "$VENV" 2>&1); then
            echo "<FAIL> Die virtuelle Python-Umgebung liess sich nicht anlegen (Paket python3-venv?)."
            echo "$HP_AUS" | head -8 | sed 's/^/<FAIL>     /'
            exit 1
        fi
    fi
    if [ ! -x "$VENV/bin/python3" ]; then
        echo "<FAIL> In der virtuellen Umgebung fehlt python3."
        exit 1
    fi
    echo "<INFO> Lade die AirPlay-Bibliothek aus PyPI. Auf aarch64 wird miniaudio uebersetzt, das kann einige Minuten dauern."
    if ! HP_AUS=$(timeout 2400 "$VENV/bin/python3" -m pip install --no-cache-dir --disable-pip-version-check --prefer-binary -r "$REQ" 2>&1); then
        echo "<FAIL> pip konnte die Bibliothek nicht einrichten. Das Plugin kann so nichts ausgeben."
        echo "$HP_AUS" | tail -12 | sed 's/^/<FAIL>     /'
        exit 1
    fi
    if ! LADEFEHLER=$("$VENV/bin/python3" -c 'import pyatv, miniaudio' 2>&1); then
        echo "<FAIL> Die Bibliothek ist da, laesst sich aber nicht laden."
        echo "$LADEFEHLER" | tail -8 | sed 's/^/<FAIL>     /'
        exit 1
    fi
    IST=$(hp_bib_ist)
    if [ "$IST" != "$SOLL" ]; then
        echo "<FAIL> Verlangt ist pyatv $SOLL, eingerichtet ist '$IST'."
        exit 1
    fi
    echo "<OK> AirPlay-Bibliothek pyatv $IST eingerichtet."
    "$VENV/bin/python3" -m pip list --disable-pip-version-check 2>/dev/null | grep -Ei '^(pyatv|miniaudio|cryptography|zeroconf|aiohttp|protobuf|pydantic) ' | sed 's/^/<INFO> /'
fi
chmod -R go-rwx "$VENV" 2>/dev/null
if ! command -v espeak-ng >/dev/null 2>&1; then
    echo "<WARNING> espeak-ng fehlt: Die lokale Sprachquelle geht nicht (Paket laut dpkg/apt nachholen)."
fi
if "$VENV/bin/python3" -B "$PBIN/hp_ausgabe.py" --selbsttest 2>&1 | tail -1 | grep -q ' 0 Fehlschlaege'; then
    echo "<OK> Selbsttest bestanden."
else
    echo "<WARNING> Der Selbsttest ist nicht sauber durchgelaufen."
fi
echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte: am HomePod den Zugriff erlauben (Home-App, Jeder im selben Netzwerk), dann Reiter Einstellungen: Geraete suchen, Standardgeraet waehlen."
exit 0
