#!/bin/bash
# Creality2Lox - preupgrade.sh (laeuft als loxberry vor dem Austausch der Dateien; Bauart Saugroboter-Valetudo 1.1.37)
#
# Der Installer raeumt config/plugins/<ordner>/ beim Upgrade ab. Gesichert wird, was der Code dort schreibt:
# creality.json (0600, mit Aktionstoken). Die Zweitschrift liegt daneben (config/plugins/<ordner>.backup.json) und
# bleibt ohnehin stehen. Die Abo-Datei mqtt_subscriptions.cfg liefert das Archiv mit; der Minutenlauf schreibt sie
# fuer ein geaendertes Praefix neu. Laufzeitdaten liegen in /tmp/<ordner>/ und brauchen keine Sicherung.
# Scheitert die Sicherung, bricht das Update ab (Rueckgabe 2), damit der Purge das Original nicht loescht.
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde nichts gesichert."
    exit 2
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER'."; exit 2 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
{ date +%s > "$MARKE"; } 2>/dev/null
if ! grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen. Ohne sie hielte die Installation dieses Update"
    echo "<FAIL> fuer eine Neuinstallation und legte die Einstellungen beiseite. Die Aktualisierung wird"
    echo "<FAIL> abgebrochen; die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi

BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    if timeout 40 bash "$BBSH" stop >/dev/null 2>&1; then
        echo "<OK> Befehlsbruecke fuer das Update angehalten."
    else
        echo "<WARNING> Die Befehlsbruecke liess sich nicht anhalten - der Installer tauscht sie trotzdem aus."
    fi
fi

TMPDIR="$ARGV6"
if [ -z "$TMPDIR" ] || [ ! -d "$TMPDIR" ]; then
    TMPDIR="$PWD/$ARGV1"
fi
[ -n "$TMPDIR" ] || { echo "<FAIL> Kein Arbeitsordner - nichts gesichert, die Aktualisierung wird abgebrochen."; rm -f "$MARKE" 2>/dev/null; exit 2; }

CF="$BASE/config/plugins/$PFOLDER/creality.json"
mkdir -p "$TMPDIR" 2>/dev/null
if [ -f "$CF" ]; then
    NEU="$TMPDIR/creality.json.neu.$$"
    rm -f "$NEU" 2>/dev/null
    if { : > "$NEU"; } 2>/dev/null && chmod 600 "$NEU" 2>/dev/null && cp "$CF" "$NEU" 2>/dev/null \
       && cmp -s "$CF" "$NEU" && mv -f "$NEU" "$TMPDIR/creality.json" 2>/dev/null; then
        echo "<OK> Konfiguration fuer das Update gesichert."
    else
        rm -f "$NEU" 2>/dev/null
        if [ -s "$CF" ]; then
            echo "<FAIL> creality.json liess sich nicht sichern - die Aktualisierung wird abgebrochen, damit der"
            echo "<FAIL> Purge das Original nicht loescht. Bitte Platz und Rechte unter config/plugins pruefen."
            rm -f "$MARKE" 2>/dev/null
            exit 2
        fi
    fi
fi
cp -p "$BASE/log/plugins/$PFOLDER/creality.log" "$TMPDIR/creality.log" 2>/dev/null
exit 0
