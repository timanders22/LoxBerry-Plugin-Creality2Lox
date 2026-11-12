#!/bin/bash
# Creality2Lox - preinstall.sh (laeuft als loxberry vor dem Kopieren der Dateien; Bauart Saugroboter-Valetudo 1.1.37)
#
# Neuinstallation: eine Zweitschrift einer FRUEHEREN Installation (config/plugins/<ordner>.backup.json) wird
# beiseitegelegt, damit sie nicht still als Einstellungen dieser Installation zurueckkommt. Bei einem Update
# (Marke data/plugins/<ordner>.upgrade_laeuft von preupgrade.sh) geschieht nichts.
# Argumente des Installers: $3 Ordnername, $5 LoxBerry-Wurzel.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-creality2lox}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
BEISEITE=""
FEST=""
if [ -e "$BK" ] || [ -L "$BK" ]; then
    rm -rf "${BK:?}.alt" 2>/dev/null
    if mv -f "$BK" "$BK.alt" 2>/dev/null; then
        BEISEITE="$BK.alt"
    else
        FEST="$BK"
    fi
fi
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    CR_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && CR_TEXT="$CR_TEXT Beiseitegelegt: $BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && CR_TEXT="$CR_TEXT Nicht zu verschieben, bitte von Hand entfernen: $FEST"
    echo "$CR_TEXT"
fi
exit 0
