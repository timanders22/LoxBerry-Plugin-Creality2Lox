#!/bin/bash
# Creality2Lox - postinstall.sh (laeuft als loxberry nach dem Kopieren; Bauart Saugroboter-Valetudo 1.1.37)
#
# Legt Konfigurations-, Daten- und Protokollordner an, eine leere Konfiguration ({}) mit 0600, wenn es keine gibt.
# Das Aktionstoken entsteht beim ersten Oeffnen der Oberflaeche. Neue Funktionen sind ab Werk aus: ohne Drucker-
# adresse fragt der Minutenlauf nichts ab, MQTT, Befehle und HTTP-Lesezweig sind aus.
# Argumente: $3 Ordnername, $5 LoxBerry-Wurzel.

ARGV3=$3
ARGV5=$5
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde nichts angelegt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts angelegt."; exit 1 ;;
esac

CDIR="$BASE/config/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
LDIR="$BASE/log/plugins/$PFOLDER"
CF="$CDIR/creality.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

mkdir -p "$CDIR" "$DDIR" "$LDIR" 2>/dev/null
if [ ! -d "$CDIR" ]; then
    echo "<FAIL> $CDIR liess sich nicht anlegen."
    exit 1
fi
if [ ! -f "$CF" ]; then
    ( umask 077; echo '{}' > "$CF" ) || { echo "<FAIL> $CF liess sich nicht schreiben."; exit 1; }
fi
chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    echo "<OK> Dateien eingespielt. Die Konfiguration spielt der naechste Schritt (postupgrade.sh) zurueck."
    exit 0
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen und im Reiter Einstellungen die Adresse des Druckers"
echo "<INFO> eintragen (Moonraker, Port 7125). Der Reiter Test sagt danach, ob die Einrichtung traegt."
echo "<INFO> MQTT, Befehle und der HTTP-Lesezweig sind ab Werk aus."
exit 0
