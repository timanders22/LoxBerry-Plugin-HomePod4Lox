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
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
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
    rm -f "$CF.neu"
    if ( umask 077; cp "$BK" "$CF.neu" && chmod 600 "$CF.neu" ) 2>/dev/null && cmp -s "$BK" "$CF.neu" && mv -f "$CF.neu" "$CF" 2>/dev/null; then
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
# Konfiguration vervollstaendigen (0.9.4, Regeln/05): fehlende Vorgaben einmal als Zeile in homepod4lox.cfg - ueber
# hp_lib.php (bin/hp_vervollstaendigen.php), ohne Token zu erzeugen. Das Plugin hat keinen Dienst und keinen Takt, also
# hier. Mit Marke ruht das Vervollstaendigen; deshalb faellt sie unmittelbar davor (der trap raeumt sonst erst am Ende).
# Laeuft als loxberry, Rechte 0600 bleiben. "cd /": Handstart aus einem fremden Heimverzeichnis (Regeln/06).
hp_marke_weg
HP_VV="$BASE/bin/plugins/$PFOLDER/hp_vervollstaendigen.php"
if [ -f "$CF" ] && [ -f "$HP_VV" ]; then
    if command -v php >/dev/null 2>&1; then
        HP_VV_ZEILE=$(cd / && LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php "$HP_VV" "$PFOLDER" 2>&1 | tail -1)
        case "$HP_VV_ZEILE" in
            *";STAND=ergaenzt;"*)     echo "<OK> Konfiguration vervollstaendigt: ${HP_VV_ZEILE#*SCHLUESSEL=}" ;;
            *";STAND=vollstaendig;"*) echo "<OK> Konfiguration vollstaendig, nichts ergaenzt." ;;
            *";STAND=ohne_merkwort;"*|*";STAND=ohne_datei;"*)
                echo "<INFO> Konfiguration nicht vervollstaendigt (noch ohne Aktionstoken) - das Oeffnen der Oberflaeche schreibt sie vollstaendig." ;;
            *) echo "<WARNING> Konfiguration nicht vervollstaendigt: ${HP_VV_ZEILE:-keine Ausgabe}. Das Oeffnen der Oberflaeche holt es nach." ;;
        esac
    else
        echo "<WARNING> Konfiguration nicht vervollstaendigt: php wurde nicht gefunden."
    fi
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
