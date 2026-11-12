#!/bin/bash
# Creality2Lox - postupgrade.sh (laeuft als loxberry nach dem Austausch der Dateien; Bauart Saugroboter-Valetudo 1.1.37)
#
# Spielt zurueck, was preupgrade.sh gesichert hat (creality.json, nur mit Merkwort aktionstoken), sonst aus der
# Zweitschrift. Fehlende Schluessel traegt der naechste Minutenlauf mit ihrer Vorgabe ein (vervollstaendigen).
# Entfernt am Ende die Upgrade-Marke und startet die Befehlsbruecke, wenn die Haken es sagen.
# Argumente: $1 Arbeitsordner (relativ), $3 Ordnername, $5 LoxBerry-Wurzel, $6 Arbeitsordner (absolut).

ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6
PFOLDER="${ARGV3:-creality2lox}"
BASE="${ARGV5:-$LBHOMEDIR}"

cr_wurzel_suchen() {
    cr_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    cr_i=0
    while [ -n "$cr_v" ] && [ "$cr_v" != "/" ] && [ "$cr_i" -lt 8 ]; do
        if [ -d "$cr_v/config/plugins" ] && [ -d "$cr_v/data/plugins" ] \
           && [ -f "$cr_v/config/system/general.json" ]; then
            echo "$cr_v"
            return 0
        fi
        cr_v=$(dirname "$cr_v")
        cr_i=$((cr_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    BASE=$(cr_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde nichts zurueckgespielt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER'."; exit 1 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
cr_marke_weg() {
    cr_rc=$?
    rm -f "$MARKE" 2>/dev/null
    if [ -e "$MARKE" ]; then
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen - bitte von Hand loeschen."
    fi
    exit $cr_rc
}
trap cr_marke_weg EXIT

# Traegt die Datei ein lesbares JSON-Objekt mit nicht leerem Aktionstoken? (Selbstheilung nach Inhalt)
cr_hat_inhalt() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        exit((isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && trim($d["aktionstoken"]) !== "") ? 0 : 1);' -- "$1" 2>/dev/null
}
cr_atomar_kopieren() {
    cr_neu="$2.neu.$$"
    if ( umask 077; cp "$1" "$cr_neu" ) 2>/dev/null && cmp -s "$1" "$cr_neu" && mv -f "$cr_neu" "$2"; then
        chmod 600 "$2" 2>/dev/null
        return 0
    fi
    rm -f "$cr_neu" 2>/dev/null
    return 1
}

TMPDIR="$ARGV6"
if [ -z "$TMPDIR" ] || [ ! -d "$TMPDIR" ]; then
    TMPDIR="$PWD/$ARGV1"
fi
CDIR="$BASE/config/plugins/$PFOLDER"
LDIR="$BASE/log/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
CF="$CDIR/creality.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
mkdir -p "$CDIR" "$LDIR" "$DDIR" 2>/dev/null

if [ -f "$TMPDIR/creality.json" ]; then
    if cr_hat_inhalt "$TMPDIR/creality.json"; then
        if ! cmp -s "$TMPDIR/creality.json" "$CF"; then
            cr_atomar_kopieren "$TMPDIR/creality.json" "$CF"
        fi
        if cmp -s "$TMPDIR/creality.json" "$CF"; then
            echo "<OK> Konfiguration aus dem Upgrade uebernommen."
            if ! cmp -s "$CF" "$BK"; then
                if cr_atomar_kopieren "$CF" "$BK"; then
                    echo "<OK> Zweitschrift $PFOLDER.backup.json angeglichen."
                else
                    echo "<WARNING> Die Zweitschrift $BK liess sich nicht angleichen."
                fi
            fi
        else
            echo "<WARNING> Die Konfiguration aus dem Upgrade liess sich nicht nach $CF schreiben."
        fi
    elif [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cr_atomar_kopieren "$TMPDIR/creality.json" "$CF"
        echo "<INFO> Die Konfiguration aus dem Upgrade trug kein Aktionstoken; sie wurde unveraendert zurueckgelegt."
    fi
fi

if [ -f "$BK" ] && { [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; }; then
    if cr_hat_inhalt "$BK"; then
        if cr_atomar_kopieren "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> Die Zweitschrift $PFOLDER.backup.json liess sich nicht nach $CF schreiben."
        fi
    else
        echo "<WARNING> Die Zweitschrift $PFOLDER.backup.json traegt kein Aktionstoken - sie wurde nicht uebernommen."
    fi
fi
[ -f "$CF" ] || ( umask 077; echo '{}' > "$CF" )
chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

if [ -f "$TMPDIR/creality.log" ] && [ ! -s "$LDIR/creality.log" ]; then
    cp -p "$TMPDIR/creality.log" "$LDIR/creality.log" 2>/dev/null && echo "<OK> Protokoll aus dem Upgrade uebernommen."
fi

rm -f "$MARKE" 2>/dev/null
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    CR_BB=$(timeout 40 bash "$BBSH" start 2>&1)
    case "$CR_BB" in
        *gestartet*|*laeuft*) echo "<OK> $CR_BB" ;;
        *) echo "<INFO> Befehlsbruecke: $CR_BB" ;;
    esac
fi
exit 0
