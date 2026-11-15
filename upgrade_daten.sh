# upgrade_daten.sh - den Datenordner eines Plugins ueber ein LoxBerry-Update tragen
# (gemeinsamer Baustein der LoxBerry-Plugins des Hauses; wird mit "." geladen, nicht ausgefuehrt)
#
# DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
# Die Stammfassung liegt unter Werkzeuge/gemeinsam/upgrade_daten.sh im Arbeitsordner. Jede Linie
# traegt eine Abschrift an der Wurzel ihres Archivs, neben preupgrade.sh. Ein LoxBerry-Plugin kann
# keine Datei eines anderen Plugins laden. Werkzeuge/upgrade_daten_einbauen.py legt die Abschrift
# byte-gleich hinein, ergaenzt preupgrade.sh, postinstall.sh und uninstall/uninstall um den Aufruf
# und meldet mit --pruefen jede Abschrift, die von der Stammfassung abweicht. Wer hier etwas
# aendert, aendert es in der Stammfassung, hebt UPGRADE_DATEN_FASSUNG an und verteilt danach.
#
# WARUM: Beim Upgrade ruft plugininstall.pl nach preupgrade.sh purge_installation und loescht
# data/plugins/<ordner>/ vollstaendig (Regeln/06). Am 14.11.2026 am LoxBerry gemessen: MGiSmart
# 1.1.43 verlor dabei ansage_regeln.json (Sperrzeiten und Merker der Ansage-Regeln) und die
# *_letzte.json, Chromecast4lox am 12.11.2026 zonen.json und mitglieder.json. Eine Liste
# einzelner Dateien vergisst, was spaeter dazukommt. Entscheidung des Hausherrn 14.11.2026: jede
# Linie traegt ihren ganzen Datenordner ueber das Update, ueber diesen einen Baustein.
#
# WIE DER INSTALLER DIE HAKEN RUFT (Geraet/2026-09-05/08_plugininstall.pl, LoxBerry 4.0.0.15,
# :853 preupgrade, :1311 postinstall, :1337 postupgrade):
#     cd "<arbeitsordner>" && sudo -n -u loxberry "<arbeitsordner>/<haken>.sh" \
#         <kennung> <name> <ordner> <fassung> <lbhomedir> <arbeitsordner>
# Der Arbeitsordner ist das ausgepackte NEUE Archiv; diese Datei liegt dort neben dem Skript und
# wird ueber dessen eigenen Ablageort geladen. Rueckgabe 1 eines Hakens meldet der Installer als
# Fehler und macht weiter (danach purge_installation), erst eine Rueckgabe groesser 1 bricht ab
# (:856-:863, &fail vor :886). Deshalb gibt upgrade_daten_sichern bei einem Fehler 2 zurueck.
# Vor postinstall.sh (:1304) hat der Installer data/ des neuen Archivs schon nach
# data/plugins/<ordner>/ kopiert (:1008-:1022, "cp -r <arbeitsordner>/data/*"); postinstall.sh laeuft
# bei jeder Installation, postupgrade.sh nur beim Update und erst danach.
#
# AUFRUF (setzt Werkzeuge/upgrade_daten_einbauen.py; Entscheidung des Hausherrn 14.11.2026: in allen
# Linien einheitlich am Anfang von postinstall.sh, vor jedem Dienststart der Linie; die Argumente
# werden oben im Skript genommen, weil manche Linien sie spaeter mit "set --" umsetzen):
#     preupgrade.sh  oben:              UPGD_ORDNER="$3"; UPGD_WURZEL="$5"
#                    vor dem exit 0:    upgrade_daten_sichern "$UPGD_ORDNER" "$UPGD_WURZEL" || exit 2
#     postinstall.sh oben:              upgrade_daten_zurueck "$3" "$5" "<ablageort des skripts>"
#
# SICHERN (preupgrade.sh): data/plugins/<ordner>/ vollstaendig, mit Unterordnern, Rechten und
# Zeitstempeln (tar), ohne *.lock, *.pid und Sockets, nach data/plugins/<ordner>.upgrade_daten/daten/
# - neben dem Ordner, den purge_installation loescht, nicht darin. Gebaut wird unter
# <ordner>.upgrade_daten.neu; danach wird gegengezaehlt: Art, Rechte und Verweisziel jedes Eintrags
# und die Pruefsumme jeder Datei, Quelle gegen Kopie (bis zu drei Versuche, falls ein Dienst in
# diesem Augenblick schreibt). Erst dann wird umbenannt; eine aeltere Sicherung faellt zuletzt.
# Misslingt etwas: <FAIL>, die Marke <ordner>.upgrade_laeuft wird entfernt und Rueckgabe 2 - der
# Installer bricht ab, und die bisherige Fassung bleibt mit ihrem Datenordner installiert.
#
# ZURUECKSPIELEN (postinstall.sh, ganz oben): jede Datei der Sicherung, die in der neuen
# Installation fehlt, kommt mit Rechten und Zeitstempel zurueck. Was die neue Installation schon
# angelegt hat (aus data/ des Archivs, aus einem Takt in der Luecke), wird NIE ueberschrieben; was
# davon nicht aus dem Archiv stammt und anders aussieht, wird mit Namen gemeldet. Eine Sicherung,
# die nicht aus diesem Update stammt (Zeitpunkt fehlt, aelter als 3600 s oder in der Zukunft),
# spielt nichts ein und bleibt liegen (Regeln/06, Sprachsteuerung 0.11.7). Danach wird die
# Sicherung entfernt - ausser eine Datei liess sich nicht zurueckspielen. Gibt es keine Sicherung
# (Neuinstallation, oder der zweite Lauf von postinstall.sh, den viele postupgrade.sh anstossen),
# geschieht nichts, ohne Meldung, Rueckgabe 0.
#
# Nur POSIX-sh (elf Linien rufen ihre Haken mit /bin/sh, auf dem LoxBerry dash), dazu GNU tar,
# find, md5sum, sort, du und df aus dem Grundsystem. Alle Namen tragen die Vorsilbe upgd_.

UPGRADE_DATEN_FASSUNG=1.0.0

# Wurzel ist, was config/plugins, data/plugins UND config/system/general.json traegt (Regeln/06).
upgd_wurzel_ok() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}

# Ein Ordnername ist ein schlichter Name - kein Pfad hinaus.
upgd_ordner_ok() {
    case "$1" in
        ''|.|..|*/*|*[!A-Za-z0-9._-]*) return 1 ;;
    esac
    return 0
}

# Was gegengezaehlt wird: je Eintrag Art, Rechte, Verweisziel und Pfad, dazu die Pruefsumme jeder
# Datei - ausgelassen genau das, was auch tar auslaesst.
upgd_liste() {
    ( cd "$1" || exit 1
      find . \( -name '*.lock' -o -name '*.pid' -o -type s \) -prune -o -printf '%y %m %l|%P\n' \
          | LC_ALL=C sort
      find . \( -name '*.lock' -o -name '*.pid' -o -type s \) -prune -o -type f -exec md5sum {} + \
          | LC_ALL=C sort
    )
}

upgd_abbruch() {   # $1 Grund
    echo "<FAIL> $1"
    echo "<FAIL> Die Aktualisierung wird abgebrochen; die bisherige Fassung bleibt mit ihrem Datenordner installiert."
    if [ -n "$upgd_n" ]; then
        rm -rf "$upgd_n" 2>/dev/null
    fi
    if [ -n "$upgd_marke" ]; then
        rm -f "$upgd_marke" 2>/dev/null
    fi
    return 2
}

# preupgrade.sh: $1 Ordner (drittes Argument des Installers), $2 LoxBerry-Wurzel (fuenftes).
# Rueckgabe 0 gesichert oder nichts zu sichern, 2 Fehler (das Update muss abbrechen).
upgrade_daten_sichern() {
    upgd_o=$1
    upgd_w=$2
    upgd_n=""
    upgd_marke=""
    if ! upgd_ordner_ok "$upgd_o"; then
        upgd_abbruch "upgrade_daten: unbrauchbarer Ordnername '$upgd_o' - der Datenordner wurde nicht gesichert."
        return 2
    fi
    if ! upgd_wurzel_ok "$upgd_w"; then
        upgd_abbruch "upgrade_daten: kein LoxBerry-Wurzelverzeichnis ('$upgd_w') - der Datenordner wurde nicht gesichert."
        return 2
    fi
    upgd_marke="$upgd_w/data/plugins/$upgd_o.upgrade_laeuft"
    upgd_q="$upgd_w/data/plugins/$upgd_o"
    upgd_z="$upgd_q.upgrade_daten"
    for upgd_t in tar find md5sum sort du df cmp; do
        if ! command -v "$upgd_t" >/dev/null 2>&1; then
            upgd_abbruch "upgrade_daten: das Programm $upgd_t fehlt - der Datenordner liesse sich nicht pruefbar sichern."
            return 2
        fi
    done
    if [ ! -e "$upgd_q" ] && [ ! -L "$upgd_q" ]; then
        echo "<INFO> Kein Datenordner vorhanden - nichts zu sichern ($upgd_q)."
        return 0
    fi
    if [ ! -d "$upgd_q" ] || [ ! -r "$upgd_q" ] || [ ! -x "$upgd_q" ]; then
        upgd_abbruch "upgrade_daten: der Datenordner ist kein lesbarer Ordner: $upgd_q"
        return 2
    fi
    if [ -z "$(ls -A "$upgd_q" 2>/dev/null)" ]; then
        echo "<INFO> Der Datenordner ist leer - nichts zu sichern ($upgd_q)."
        return 0
    fi
    upgd_bedarf=$(du -sk "$upgd_q" 2>/dev/null | awk 'NR == 1 { print $1 }')
    upgd_frei=$(df -Pk "$upgd_w/data/plugins" 2>/dev/null | awk 'NR == 2 { print $4 }')
    case "$upgd_bedarf" in ''|*[!0-9]*) upgd_bedarf="" ;; esac
    case "$upgd_frei" in ''|*[!0-9]*) upgd_frei="" ;; esac
    if [ -z "$upgd_bedarf" ] || [ -z "$upgd_frei" ]; then
        upgd_abbruch "upgrade_daten: der Platzbedarf liess sich nicht messen (du/df) - nichts gesichert."
        return 2
    fi
    if [ "$upgd_frei" -lt $((upgd_bedarf + 10240)) ]; then
        upgd_abbruch "upgrade_daten: zu wenig Platz - die Sicherung braucht $upgd_bedarf kB und 10240 kB Reserve, frei sind $upgd_frei kB."
        return 2
    fi

    upgd_n="$upgd_z.neu"
    rm -rf "$upgd_n" 2>/dev/null
    if [ -e "$upgd_n" ] || [ -L "$upgd_n" ]; then
        upgd_abbruch "upgrade_daten: ein Rest einer frueheren Sicherung laesst sich nicht entfernen (Rechte?): $upgd_n"
        return 2
    fi
    if ! ( umask 077 && mkdir "$upgd_n" ) 2>/dev/null; then
        upgd_abbruch "upgrade_daten: die Sicherung laesst sich nicht anlegen (Rechte? Platz?): $upgd_n"
        return 2
    fi
    upgd_versuch=0
    upgd_gleich=0
    while [ "$upgd_versuch" -lt 3 ]; do
        upgd_versuch=$((upgd_versuch + 1))
        rm -rf "$upgd_n/daten" 2>/dev/null
        if ! ( umask 077 && mkdir "$upgd_n/daten" ) 2>/dev/null; then
            break
        fi
        { tar -C "$upgd_q" --exclude='*.lock' --exclude='*.pid' --warning=no-file-ignored -cf - . \
              2>"$upgd_n/fehler_lesen"
          echo $? >"$upgd_n/rc_lesen"
        } | tar -C "$upgd_n/daten" -xpf - 2>"$upgd_n/fehler_schreiben"
        upgd_rc_s=$?
        upgd_rc_l=$(cat "$upgd_n/rc_lesen" 2>/dev/null)
        upgd_liste "$upgd_q" >"$upgd_n/liste_quelle" 2>/dev/null
        upgd_liste "$upgd_n/daten" >"$upgd_n/liste_kopie" 2>/dev/null
        if [ "$upgd_rc_l" = 0 ] && [ "$upgd_rc_s" = 0 ] && [ -s "$upgd_n/liste_quelle" ] \
           && cmp -s "$upgd_n/liste_quelle" "$upgd_n/liste_kopie"; then
            upgd_gleich=1
            break
        fi
        sleep 1
    done
    if [ "$upgd_gleich" != 1 ]; then
        echo "<FAIL> upgrade_daten: die Kopie des Datenordners gleicht ihrer Quelle nach $upgd_versuch Versuch(en) nicht"
        echo "<FAIL> (tar lesen rc=${upgd_rc_l:-?}, schreiben rc=${upgd_rc_s:-?}). Meldungen:"
        cat "$upgd_n/fehler_lesen" "$upgd_n/fehler_schreiben" 2>/dev/null | head -n 10 | sed 's/^/<FAIL>     /'
        if [ -f "$upgd_n/liste_quelle" ] && [ -f "$upgd_n/liste_kopie" ]; then
            diff "$upgd_n/liste_quelle" "$upgd_n/liste_kopie" 2>/dev/null | grep '^[<>]' | head -n 10 \
                | sed 's/^/<FAIL>     /'
        fi
        upgd_abbruch "upgrade_daten: der Datenordner liess sich nicht vollstaendig sichern: $upgd_q"
        return 2
    fi
    upgd_dateien=$(grep -c '^f ' "$upgd_n/liste_quelle" 2>/dev/null)
    upgd_ordner=$(grep -c '^d ' "$upgd_n/liste_quelle" 2>/dev/null)
    upgd_aus=$(find "$upgd_q" \( -name '*.lock' -o -name '*.pid' -o -type s \) -prune -print 2>/dev/null | wc -l)
    rm -f "$upgd_n/rc_lesen" "$upgd_n/fehler_lesen" "$upgd_n/fehler_schreiben" "$upgd_n/liste_kopie" 2>/dev/null
    date +%s >"$upgd_n/zeitpunkt" 2>/dev/null
    if ! grep -qx '[0-9][0-9]*' "$upgd_n/zeitpunkt" 2>/dev/null; then
        upgd_abbruch "upgrade_daten: der Zeitpunkt der Sicherung liess sich nicht schreiben: $upgd_n/zeitpunkt"
        return 2
    fi
    rm -rf "$upgd_z.alt" 2>/dev/null
    if [ -e "$upgd_z" ] || [ -L "$upgd_z" ]; then
        if ! mv "$upgd_z" "$upgd_z.alt" 2>/dev/null; then
            upgd_abbruch "upgrade_daten: eine aeltere Sicherung laesst sich nicht beiseitelegen: $upgd_z"
            return 2
        fi
    fi
    if ! mv "$upgd_n" "$upgd_z" 2>/dev/null; then
        if [ -e "$upgd_z.alt" ]; then
            mv "$upgd_z.alt" "$upgd_z" 2>/dev/null
        fi
        upgd_abbruch "upgrade_daten: die Sicherung laesst sich nicht an ihren Platz bringen: $upgd_z"
        return 2
    fi
    upgd_n=""
    rm -rf "$upgd_z.alt" 2>/dev/null
    chmod 700 "$upgd_z" 2>/dev/null
    echo "<OK> Datenordner fuer das Update gesichert und gegengezaehlt: $upgd_dateien Datei(en) in $upgd_ordner Ordner(n), $upgd_aus ausgelassen (*.lock, *.pid, Sockets) - $upgd_z"
    return 0
}

# postinstall.sh (ganz oben): $1 Ordner (drittes Argument), $2 LoxBerry-Wurzel (fuenftes), $3 der
# Ordner des neuen Archivs (Ablageort des Skripts; wahlweise) - daran wird erkannt, was aus data/
# des Archivs stammt. Rueckgabe 0 zurueckgespielt oder nichts zu tun (dann still), 1 etwas fehlt
# (dann bleibt die Sicherung).
upgrade_daten_zurueck() {
    upgd_o=$1
    upgd_w=$2
    upgd_a=$3
    if ! upgd_ordner_ok "$upgd_o"; then
        echo "<WARNING> upgrade_daten: unbrauchbarer Ordnername '$upgd_o' - der Datenordner wurde nicht zurueckgespielt."
        return 1
    fi
    if ! upgd_wurzel_ok "$upgd_w"; then
        echo "<WARNING> upgrade_daten: kein LoxBerry-Wurzelverzeichnis ('$upgd_w') - der Datenordner wurde nicht zurueckgespielt."
        return 1
    fi
    upgd_d="$upgd_w/data/plugins/$upgd_o"
    upgd_z="$upgd_d.upgrade_daten"
    upgd_s="$upgd_z/daten"
    if [ ! -e "$upgd_z" ] && [ ! -L "$upgd_z" ]; then
        return 0
    fi
    if [ -L "$upgd_z" ] || [ ! -d "$upgd_s" ]; then
        echo "<WARNING> upgrade_daten: die Sicherung ist unbrauchbar und bleibt liegen: $upgd_z"
        return 1
    fi
    upgd_zeit=$(cat "$upgd_z/zeitpunkt" 2>/dev/null)
    upgd_jetzt=$(date +%s)
    case "$upgd_zeit" in
        ''|*[!0-9]*) upgd_alter=-1 ;;
        *) upgd_alter=$((upgd_jetzt - upgd_zeit)) ;;
    esac
    if [ "$upgd_alter" -lt 0 ] || [ "$upgd_alter" -ge 3600 ]; then
        echo "<WARNING> upgrade_daten: die Sicherung des Datenordners stammt nicht aus diesem Update (Zeitpunkt '${upgd_zeit}')."
        echo "<WARNING> Sie wird nicht eingespielt und bleibt liegen: $upgd_z"
        return 1
    fi
    if [ ! -d "$upgd_d" ]; then
        upgd_m=$(find "$upgd_s" -maxdepth 0 -printf '%m' 2>/dev/null)
        if ! mkdir -p "$upgd_d" 2>/dev/null; then
            echo "<WARNING> upgrade_daten: der Datenordner laesst sich nicht anlegen; die Sicherung bleibt liegen: $upgd_z"
            return 1
        fi
        [ -n "$upgd_m" ] && chmod "$upgd_m" "$upgd_d" 2>/dev/null
    fi
    if ! find "$upgd_s" -mindepth 1 -printf '%y %m %P\n' >"$upgd_z/eintraege" 2>/dev/null; then
        echo "<WARNING> upgrade_daten: die Sicherung laesst sich nicht lesen und bleibt liegen: $upgd_z"
        return 1
    fi
    : >"$upgd_z/angelegt"
    upgd_zurueck=0; upgd_gleich=0; upgd_archiv=0; upgd_neu=0; upgd_fehl=0
    upgd_neu_namen=""; upgd_fehl_namen=""
    while IFS= read -r upgd_zeile; do
        upgd_art=${upgd_zeile%% *}
        upgd_rest=${upgd_zeile#* }
        upgd_m=${upgd_rest%% *}
        upgd_p=${upgd_rest#* }
        upgd_q="$upgd_s/$upgd_p"
        upgd_t="$upgd_d/$upgd_p"
        if [ "$upgd_art" = d ]; then
            if [ -d "$upgd_t" ] && [ ! -L "$upgd_t" ]; then
                :
            elif [ -e "$upgd_t" ] || [ -L "$upgd_t" ]; then
                upgd_fehl=$((upgd_fehl + 1)); upgd_fehl_namen="$upgd_fehl_namen $upgd_p/"
            elif mkdir "$upgd_t" 2>/dev/null; then
                printf '%s %s\n' "$upgd_m" "$upgd_p" >>"$upgd_z/angelegt"
            else
                upgd_fehl=$((upgd_fehl + 1)); upgd_fehl_namen="$upgd_fehl_namen $upgd_p/"
            fi
            continue
        fi
        if [ -e "$upgd_t" ] || [ -L "$upgd_t" ]; then
            if [ "$upgd_art" = f ] && [ -f "$upgd_t" ] && [ ! -L "$upgd_t" ] && cmp -s "$upgd_q" "$upgd_t"; then
                upgd_gleich=$((upgd_gleich + 1))
            elif [ -n "$upgd_a" ] && { [ -e "$upgd_a/data/$upgd_p" ] || [ -L "$upgd_a/data/$upgd_p" ]; }; then
                upgd_archiv=$((upgd_archiv + 1))
            else
                upgd_neu=$((upgd_neu + 1)); upgd_neu_namen="$upgd_neu_namen $upgd_p"
            fi
            continue
        fi
        case "$upgd_art" in
            f) cp -p "$upgd_q" "$upgd_t" 2>/dev/null && cmp -s "$upgd_q" "$upgd_t" ;;
            l) cp -pP "$upgd_q" "$upgd_t" 2>/dev/null ;;
            p) mkfifo -m "$upgd_m" "$upgd_t" 2>/dev/null ;;
            *) false ;;
        esac
        if [ $? = 0 ]; then
            upgd_zurueck=$((upgd_zurueck + 1))
        else
            rm -f "$upgd_t" 2>/dev/null
            upgd_fehl=$((upgd_fehl + 1)); upgd_fehl_namen="$upgd_fehl_namen $upgd_p"
        fi
    done <"$upgd_z/eintraege"
    while IFS= read -r upgd_zeile; do
        chmod "${upgd_zeile%% *}" "$upgd_d/${upgd_zeile#* }" 2>/dev/null
    done <"$upgd_z/angelegt"
    echo "<OK> Datenordner zurueckgespielt: $upgd_zurueck Eintrag/Eintraege aus der Sicherung, $upgd_gleich schon gleich, $upgd_archiv aus dem neuen Archiv behalten."
    if [ "$upgd_neu" -gt 0 ]; then
        echo "<WARNING> $upgd_neu Datei(en) hat die neue Installation schon angelegt; sie wurden nicht ueberschrieben, der Stand von vor dem Update ist verworfen:$(echo "$upgd_neu_namen" | cut -c1-300)"
    fi
    if [ "$upgd_fehl" -gt 0 ]; then
        echo "<WARNING> $upgd_fehl Eintrag/Eintraege liessen sich nicht zurueckspielen:$(echo "$upgd_fehl_namen" | cut -c1-300)"
        echo "<WARNING> Die Sicherung bleibt liegen: $upgd_z"
        return 1
    fi
    rm -rf "$upgd_z" 2>/dev/null
    if [ -e "$upgd_z" ]; then
        echo "<WARNING> upgrade_daten: die Sicherung liess sich nicht entfernen: $upgd_z"
        return 1
    fi
    return 0
}
