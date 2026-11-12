<?php
/**
 * Creality2Lox - gemeinsame Bibliothek (Oberflaeche, Endpunkt, Minutenlauf, Befehlsbruecke, Healthcheck).
 *
 * Liest einen Creality-Drucker mit Klipper und Moonraker (gemessen: K2 Plus, Moonraker-API 1.0.5) ueber die
 * HTTP-Schnittstelle von Moonraker (Port 7125, ohne Anmeldung) und liefert die Werte an Loxone - ueber das
 * MQTT-Gateway (Regelweg) und wahlweise ueber einen HTTP-Lesezweig. Befehle (Pause, Fortsetzen, Abbrechen,
 * Notaus, Licht) gehen ueber dieselbe Funktion cr_befehl_ausfuehren(), ab Werk alle aus.
 *
 * WOHER DIE ANGABEN STAMMEN
 *   Objektnamen, Felder und Antwortformen: am Drucker gemessen am 12.11.2026, nur lesend
 *   (Pruefung-Creality2Lox-0.9.1/messung_2026-11-12_*.txt). Gemessen wurde der Drucker in Ruhe
 *   (print_stats.state = standby). Die uebrigen Zustaende (printing, paused, complete, cancelled, error) und die
 *   Befehlswege (POST /printer/print/pause|resume|cancel, /printer/emergency_stop, /printer/gcode/script) stehen in
 *   der Moonraker-Dokumentation; am Geraet ist KEIN Befehl ausgeloest worden.
 *   Port 9999 (eigene Creality-Schnittstelle) wird NICHT benutzt.
 *
 * Keine persoenlichen Daten im Code - Adresse und Token kommen aus der lokalen Konfiguration.
 * Kompatibel mit PHP 7.4, 8.4 und 8.5 (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

define('CR_ORDNER_VORGABE', 'creality2lox');
define('CR_CFS_MAX', 4);                 // Creality-Materialboxen T1 bis T4 (gemessen: box.T1 ... box.T4)
define('CR_LAUF_FRIST', 50);             // so lange darf ein Minutenlauf hoechstens abfragen (s)
define('CR_BEFEHL_BREMSE', 5);           // gleicher Befehl mit gleichem Wert binnen 5 s: abgewiesen
define('CR_WIRKUNG_WARTEN', 8);          // so lange sieht cr_befehl_ausfuehren() nach der Wirkung (s)
define('CR_VOLL_SENDEN', 1800);          // voller MQTT-Satz spaetestens alle 30 Minuten

/* ---------------------------------------------------------------------------------------------------------------
 * Wurzel, Ordner, Pfade (Bauart Saugroboter-Valetudo 1.1.37 ro_lbhome()/ro_paths(); Regeln/06)
 * ------------------------------------------------------------------------------------------------------------- */

if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/** Die LoxBerry-Wurzel: erst LBHOMEDIR (mit config/plugins und data/plugins), dann die Suche aufwaerts. */
function cr_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

/** Der Plugin-Ordner - aus LBPPLUGINDIR oder dem eigenen Ablageort; nie ein Ordner, der keiner sein kann. */
function cr_plugin_ordner()
{
    $pd = (string) getenv('LBPPLUGINDIR');
    if ($pd === '') { $pd = basename(__DIR__); }
    $pd = basename($pd);
    if (in_array($pd, array('', '.', '..', '/', 'html', 'htmlauth', 'plugins', 'webfrontend', 'bin'), true)) {
        $pd = CR_ORDNER_VORGABE;
    }
    return $pd;
}

/**
 * Die Pfade der Anlage - nur, wenn diese Bibliothek dort installiert liegt oder LBHOMEDIR und LBPPLUGINDIR
 * ausdruecklich gesetzt sind (Pruefstand, Deinstallation). Sonst die Ersatzpfade im Temp-Ordner (Archivmodus).
 */
function cr_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $lb = cr_lbhome();
    $pd = cr_plugin_ordner();
    $gefunden = $lb;
    if ($lb !== '') {
        $soll = @realpath($lb . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $lbp = basename((string) getenv('LBPPLUGINDIR'));
        $ausdruecklich = ($lbp !== '' && $pd === $lbp && $lb === rtrim((string) getenv('LBHOMEDIR'), '/'));
        if (!$installiert && !$ausdruecklich) { $lb = ''; }
    }
    if ($lb !== '') {
        $p = array('config' => $lb . '/config/plugins/' . $pd . '/creality.json',
                   'backup' => $lb . '/config/plugins/' . $pd . '.backup.json',
                   'log' => $lb . '/log/plugins/' . $pd . '/creality.log',
                   'datadir' => $lb . '/data/plugins/' . $pd,
                   'tmp' => '/tmp/' . $pd,
                   'plugin' => $pd, 'lbhome' => $lb,
                   'general' => $lb . '/config/system/general.json',
                   'archiv' => '');
        return $p;
    }
    $tmp = sys_get_temp_dir() . '/creality2lox-archiv';
    $p = array('config' => $tmp . '/creality.json', 'backup' => $tmp . '/creality.backup.json',
               'log' => $tmp . '/creality.log', 'datadir' => $tmp . '/data', 'tmp' => $tmp,
               'plugin' => $pd, 'lbhome' => '', 'general' => '', 'archiv' => $gefunden);
    return $p;
}

/** Fuer bin/cron.php: ohne Wurzel (oder aus einem ausgepackten Archiv) nichts tun, Meldung, Rueckgabewert 1. */
function cr_keine_wurzel_abbruch($programm)
{
    $p = cr_paths();
    if ($p['lbhome'] !== '') { return; }
    fwrite(STDERR, $programm . ': ' . ($p['archiv'] !== ''
        ? 'Diese Datei liegt nicht in der Installation unter ' . $p['archiv'] . ' (ausgepacktes Archiv oder Pruefordner).'
        : 'Es wurde kein LoxBerry-Wurzelverzeichnis gefunden (config/plugins, data/plugins, config/system/general.json).')
        . "\nEs wurde nichts abgefragt, nichts gesendet und nichts geschrieben.\n");
    exit(1);
}

function cr_tmpdir() { $p = cr_paths(); if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); } return $p['tmp']; }
function cr_datadir() { $p = cr_paths(); if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); } return $p['datadir']; }

/* ---------------------------------------------------------------------------------------------------------------
 * Dateien und Protokoll
 * ------------------------------------------------------------------------------------------------------------- */

/** Atomar schreiben: Nebendatei mit PID, Rechte vor dem Inhalt, Laenge pruefen, rename(). */
function cr_write_atomic($datei, $inhalt, $rechte = 0600)
{
    if ($inhalt === false || $inhalt === null) { return false; }
    $inhalt = (string) $inhalt;
    $ordner = dirname($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) { return false; }
    @chmod($tmp, $rechte);
    $n = @fwrite($fp, $inhalt);
    @fclose($fp);
    if ($n !== strlen($inhalt)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}

function cr_write_json($datei, $daten, $rechte = 0644)
{
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    return cr_write_atomic($datei, $js, $rechte);
}

/** Das Ende einer Datei rueckwaerts lesen - nie die ganze Datei, nie exec('tail'). */
function cr_log_tail($datei, $max = 200, $block = 8192)
{
    if (!is_file($datei)) { return array(); }
    $fp = @fopen($datei, 'rb');
    if (!$fp) { return array(); }
    fseek($fp, 0, SEEK_END);
    $rest = ftell($fp);
    $puffer = '';
    $runden = 0;
    while ($rest > 0 && substr_count($puffer, "\n") <= $max && $runden < 400) {
        $lese = (int) min($block, $rest);
        $rest -= $lese;
        fseek($fp, $rest, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $runden++;
    }
    fclose($fp);
    $zeilen = preg_split('/\R/', $puffer, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($zeilen) ? array_slice($zeilen, -$max) : array();
}

/** Eine Protokollzeile; ab 512 kB auf die letzten 200 Zeilen gekappt. Genau dieser Weg schreibt creality.log. */
function cr_log($msg)
{
    $f = cr_paths()['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        cr_write_atomic($f, implode("\n", cr_log_tail($f, 200)) . "\n", 0644);
    }
    $msg = str_replace(array("\r\n", "\r", "\n"), ' ', (string) $msg);
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/** Nur bei Wechsel des Befundes protokollieren - ein minuetlicher Lauf schreibt sonst das Protokoll voll. */
function cr_log_if_changed($key, $line)
{
    $f = cr_tmpdir() . '/last_' . preg_replace('/[^a-z0-9_]/', '', (string) $key) . '.txt';
    $prev = is_file($f) ? (string) @file_get_contents($f) : '';
    if ($line !== $prev) { cr_log($key . ': ' . $line); @file_put_contents($f, $line); }
}

/** Beide Protokolldateien (Plugin und Fehlerausgabe der Schale), je nur das Ende. */
function cr_log_lesen($max = 300)
{
    $p = cr_paths();
    $zeilen = array();
    foreach (array($p['log'], dirname($p['log']) . '/cron.log', dirname($p['log']) . '/befehle_start.log') as $datei) {
        foreach (cr_log_tail($datei, $max) as $z) {
            if (trim($z) !== '') { $zeilen[] = (basename($datei) === 'creality.log' ? '' : '[' . basename($datei) . '] ') . $z; }
        }
    }
    return array_slice($zeilen, -$max);
}

function cr_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ---------------------------------------------------------------------------------------------------------------
 * Sprache
 * ------------------------------------------------------------------------------------------------------------- */

function cr_sprache()
{
    $sprache = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = (string) LBSystem::lblanguage();
    }
    if ($sprache === '' && getenv('LBLANG')) { $sprache = (string) getenv('LBLANG'); }
    if ($sprache === '') {
        $g = cr_paths()['general'];
        $d = ($g !== '' && is_file($g)) ? json_decode((string) @file_get_contents($g), true) : null;
        if (is_array($d) && isset($d['Base']['Lang']) && is_string($d['Base']['Lang'])) { $sprache = $d['Base']['Lang']; }
    }
    $sprache = strtolower(substr($sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/** Text zu "ABSCHNITT.SCHLUESSEL"; ein unbekannter Schluessel kommt selbst zurueck (faellt beim Lesen auf). */
function cr_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $wo = cr_paths();
        $pfad = $wo['lbhome'] !== '' ? $wo['lbhome'] . '/templates/plugins/' . $wo['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) { $pfad = dirname(dirname(__DIR__)) . '/templates/lang'; }
        $texte = @parse_ini_file($pfad . '/language_' . cr_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', (string) $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : (string) $schluessel;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Konfiguration
 * ------------------------------------------------------------------------------------------------------------- */

/** Die Vorgaben - EINE Stelle. Neue Funktionen ab Werk aus (CLAUDE.md Abschnitt 4). */
function cr_vorgaben()
{
    return array(
        'drucker_host' => '',            // IPv4-Adresse oder Rechnername des Druckers (Moonraker)
        'drucker_port' => 7125,          // Moonraker, gemessen 12.11.2026
        'abfragetakt' => 60,             // Sekunden zwischen zwei Abfragen: 10, 15, 20, 30 oder 60
        'mqtt_enabled' => 0,             // Werte ueber das MQTT-Gateway veroeffentlichen - ab Werk aus
        'mqtt_topic' => 'creality',
        'befehle_mqtt_ein' => 0,         // Befehlsbruecke: Befehle ueber MQTT annehmen - ab Werk aus
        'befehle_mqtt_steuern' => 0,     // zweiter Haken: eingreifende Befehle ueber MQTT - ab Werk aus
        'befehle_http_ein' => 0,         // Befehle ueber den HTTP-Endpunkt (mit Aktionstoken) - ab Werk aus
        'freigabe_licht' => 0,           // Stufe Licht - ab Werk aus
        'freigabe_pause' => 0,           // Stufe Pause und Fortsetzen - ab Werk aus
        'freigabe_abbruch' => 0,         // Stufe Druck abbrechen - ab Werk aus
        'freigabe_notaus' => 0,          // Stufe Notaus - ab Werk aus
        'endpunkt_ein' => 0,             // HTTP-Lesezweig fuer Loxone - ab Werk aus (MQTT ist der Regelweg)
        'aktionstoken' => '',            // schuetzt Befehle und Selbsttest am unangemeldeten Endpunkt
    );
}

/** Schalter fuer den ganzen Prozess: darf hier etwas angelegt oder geheilt werden? (Endpunkt und Bruecke: nein) */
function cr_config_erzeugen_erlauben($wert = null)
{
    static $erlaubt = true;
    if ($wert !== null) { $erlaubt = (bool) $wert; }
    return $erlaubt;
}

/** Der zuerst gesehene Zustand der Konfiguration (ok, fehlt, leer, kaputt, aus der Zweitschrift). */
function cr_cfg_zustand($setzen = null)
{
    static $zustand = 'ok';
    static $fest = false;
    if ($setzen !== null && !$fest) {
        $zustand = (string) $setzen;
        if ($setzen !== 'ok') { $fest = true; }
    }
    return $zustand;
}

/**
 * Die Konfiguration lesen. Drei Lagen werden getrennt: Datei fehlt / leer oder {} / ungueltiges JSON (beschaedigt).
 * Selbstheilung aus der Zweitschrift nach INHALT (Merkwort aktionstoken), und nur mit $erzeugen.
 */
function cr_config($erzeugen = true)
{
    $p = cr_paths();
    $erzeugen = $erzeugen && cr_config_erzeugen_erlauben();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $lage = 'ok';
    $daten = null;
    if ($roh === '' || $roh === '{}') {
        $daten = array();
        $lage = is_file($p['config']) ? 'leer' : 'fehlt';
    } else {
        $daten = json_decode($roh, true);
        if (!is_array($daten)) { $lage = 'kaputt'; $daten = null; }
    }
    if ($lage === 'kaputt' && $erzeugen) {
        $kaputt = $p['config'] . '.kaputt';
        if (!is_file($kaputt)) {
            $k = @file_get_contents($p['config']);
            if (is_string($k)) { cr_write_atomic($kaputt, $k, 0600); }
        }
        cr_log_if_changed('cfg_kaputt', 'Die Konfiguration ist beschaedigt (kein gueltiges JSON). Beiseitegelegt als '
            . basename($kaputt) . '.');
    }
    if (in_array($lage, array('fehlt', 'leer', 'kaputt'), true) && is_file($p['backup'])) {
        $bk = json_decode((string) @file_get_contents($p['backup']), true);
        if (is_array($bk) && isset($bk['aktionstoken']) && is_string($bk['aktionstoken']) && trim($bk['aktionstoken']) !== '') {
            if (!$erzeugen) {
                $daten = $bk;
            } else {
                if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
                if (cr_write_atomic($p['config'], json_encode($bk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES), 0600)) {
                    $daten = $bk;
                    cr_log_if_changed('cfg_geheilt', 'Konfiguration aus der Zweitschrift wiederhergestellt (' . $lage . ').');
                    $lage = 'aus der Zweitschrift';
                }
            }
        }
    }
    cr_cfg_zustand($lage);
    $cfg = is_array($daten) ? $daten : array();
    return $cfg + cr_vorgaben();
}

/** Zufallstoken (24 Zeichen ohne verwechselbare Zeichen). */
function cr_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $zeichen[random_int(0, strlen($zeichen) - 1)]; }
    return $t;
}

/** Formularmerkmal gegen fremde Absender - aus dem Aktionstoken abgeleitet, nie gespeichert, nie gesichert. */
function cr_formtoken($cfg = null)
{
    if ($cfg === null) { $cfg = cr_config(); }
    $grund = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}
function cr_formtoken_ok($cfg = null)
{
    $soll = cr_formtoken($cfg);
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return $soll !== '' && hash_equals($soll, $ist);
}

/** Formwache: keine Steuerzeichen, keine ueberlangen Werte (rekursiv). */
function cr_wert_taugt($v)
{
    if (is_array($v)) {
        foreach ($v as $k => $w) {
            if (!cr_wert_taugt((string) $k) || !cr_wert_taugt($w)) { return false; }
        }
        return true;
    }
    if (is_bool($v) || is_int($v) || is_float($v) || $v === null) { return true; }
    $s = (string) $v;
    return strlen($s) <= 2000 && !preg_match('/[\x00-\x1F\x7F]/', $s);
}

/** Eine ganze Zahl (als int oder Ziffernfolge) in [min, max]? */
function cr_ganz_ok($w, $min, $max)
{
    if (is_int($w)) { $n = $w; }
    elseif (is_string($w) && preg_match('/^-?[0-9]{1,9}\z/', $w)) { $n = (int) $w; }
    else { return false; }
    return $n >= $min && $n <= $max;
}

/** Inhaltliche Pruefung je Schluessel. Rueckgabe '' = in Ordnung, sonst der Grund (Klartext, ohne Auszeichnung). */
function cr_wert_pruefen($schluessel, $wert)
{
    switch ($schluessel) {
        case 'drucker_host':
            if (!is_string($wert)) { return cr_t('GRUND.HOST'); }
            if ($wert === '') { return ''; }
            if (preg_match('/^(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}\z/', $wert)) {
                return '';
            }
            if (strlen($wert) <= 253 && preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*\z/', $wert)
                && !preg_match('/^[0-9.]+\z/', $wert)) {
                return '';
            }
            return cr_t('GRUND.HOST');
        case 'drucker_port':
            return cr_ganz_ok($wert, 1, 65535) ? '' : cr_t('GRUND.PORT');
        case 'abfragetakt':
            return (cr_ganz_ok($wert, 10, 60) && in_array((int) $wert, array(10, 15, 20, 30, 60), true)) ? '' : cr_t('GRUND.TAKT');
        case 'mqtt_topic':
            return (is_string($wert) && preg_match('#^[A-Za-z0-9_\-]{1,40}(/[A-Za-z0-9_\-]{1,40}){0,3}\z#', $wert)) ? '' : cr_t('GRUND.THEMA');
        case 'mqtt_enabled': case 'befehle_mqtt_ein': case 'befehle_mqtt_steuern': case 'befehle_http_ein':
        case 'freigabe_licht': case 'freigabe_pause': case 'freigabe_abbruch': case 'freigabe_notaus': case 'endpunkt_ein':
            return ($wert === 0 || $wert === 1 || $wert === '0' || $wert === '1' || $wert === true || $wert === false)
                ? '' : cr_t('GRUND.SCHALTER');
        case 'aktionstoken':
            return (is_string($wert) && preg_match('/^[A-Za-z0-9_\-]{16,128}\z/', $wert)) ? '' : cr_t('GRUND.TOKEN');
    }
    return cr_t('GRUND.UNBEKANNT');
}

/**
 * Den ganzen Konfigurationsstand ablegen: beide Wachen (Form und Inhalt), fail closed - bei einem Durchfall wird
 * GAR NICHTS geschrieben. Danach die Zweitschrift nachziehen (gleiche Rechte 0600).
 */
function cr_config_speichern($cfg)
{
    $p = cr_paths();
    $vorgaben = cr_vorgaben();
    foreach ($cfg as $k => $v) {
        if (!cr_wert_taugt($v)) {
            cr_log('Nicht gespeichert: der Wert von "' . $k . '" traegt Steuerzeichen oder ist zu lang.');
            return false;
        }
        if (array_key_exists($k, $vorgaben)) {
            $grund = cr_wert_pruefen($k, $v);
            if ($grund !== '') {
                cr_log('Nicht gespeichert: unzulaessiger Wert bei "' . $k . '" - ' . $grund);
                return false;
            }
        }
    }
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
    if (!cr_write_atomic($p['config'], $js, 0600)) { return false; }
    if (!cr_write_atomic($p['backup'], $js, 0600)) {
        cr_log_if_changed('zweitschrift', 'Die Zweitschrift ' . $p['backup'] . ' liess sich nicht schreiben - ein Update '
            . 'wuerde einen aelteren Stand zurueckspielen.');
    }
    if (is_file(cr_tmpdir() . '/zustand.json')) { @unlink(cr_tmpdir() . '/zustand.json'); }   // nie den Zustand des alten Geraets zeigen
    return true;
}

/**
 * Vervollstaendigen statt ergaenzen: fehlende Schluessel EINMAL mit der Vorgabe in die Datei schreiben, mit
 * Protokollzeile. Nur mit Merkwort (aktionstoken), nur wo angelegt werden darf, nie waehrend eines Updates.
 */
function cr_config_vervollstaendigen()
{
    $p = cr_paths();
    if ($p['lbhome'] === '') { return array('stand' => 'ohne_wurzel', 'schluessel' => array()); }
    if (!cr_config_erzeugen_erlauben()) { return array('stand' => 'nur_lesen', 'schluessel' => array()); }
    if (cr_upgrade_marke_frisch()) { return array('stand' => 'ruht', 'schluessel' => array()); }
    clearstatcache(true, $p['config']);
    if (!is_file($p['config'])) { return array('stand' => 'ohne_datei', 'schluessel' => array()); }
    $roh = trim((string) @file_get_contents($p['config']));
    $datei = ($roh !== '' && $roh[0] === '{') ? json_decode($roh, true) : null;
    if (!is_array($datei) || !isset($datei['aktionstoken']) || !is_string($datei['aktionstoken'])
        || trim($datei['aktionstoken']) === '') {
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    $fehlend = array();
    foreach (cr_vorgaben() as $k => $w) {
        if (!array_key_exists($k, $datei)) { $datei[$k] = $w; $fehlend[] = $k; }
    }
    if (!$fehlend) { return array('stand' => 'vollstaendig', 'schluessel' => array()); }
    if (!cr_config_speichern($datei)) {
        cr_log_if_changed('vervollstaendigen', 'Die Konfiguration liess sich nicht vervollstaendigen (fehlend: '
            . implode(', ', $fehlend) . ').');
        return array('stand' => 'fehler', 'schluessel' => $fehlend);
    }
    cr_log('Konfiguration vervollstaendigt: ' . count($fehlend) . ' fehlende Einstellung(en) mit der Vorgabe eingetragen ('
        . implode(', ', $fehlend) . ').');
    return array('stand' => 'ergaenzt', 'schluessel' => $fehlend);
}

/** Fehlende und fremde Schluessel der Datei (fuer Reiter Test und Healthcheck); Fremdes bleibt stehen. */
function cr_cfg_lage()
{
    $p = cr_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $d = $roh !== '' ? json_decode($roh, true) : array();
    if (!is_array($d)) { $d = array(); }
    $v = cr_vorgaben();
    $fehlt = array_values(array_diff(array_keys($v), array_keys($d)));
    $fremd = array();
    foreach (array_keys($d) as $k) {
        $k = (string) $k;
        if (!array_key_exists($k, $v) && !($k !== '' && $k[0] === '_')) { $fremd[] = $k; }
    }
    return array('soll' => count($v), 'da' => count($v) - count($fehlt), 'fehlt' => $fehlt, 'fremd' => $fremd);
}

/** Ein Update laeuft (Marke von preupgrade.sh, juenger als eine Stunde): der Minutenlauf ruht. */
function cr_upgrade_marke_frisch()
{
    $p = cr_paths();
    if ($p['lbhome'] === '') { return false; }
    $m = $p['lbhome'] . '/data/plugins/' . $p['plugin'] . '.upgrade_laeuft';
    clearstatcache(true, $m);
    if (!is_file($m)) { return false; }
    $alter = time() - (int) @filemtime($m);
    return $alter >= 0 && $alter < 3600;
}

/** Die eigene Fassung: aus der plugindatabase.json (LBSystem), sonst aus plugin.cfg neben dem Archiv. */
function cr_pluginversion()
{
    static $v = null;
    if ($v !== null) { return $v; }
    $v = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $v = (string) LBSystem::pluginversion(basename(__DIR__));
    }
    if ($v === '') {
        foreach (array(dirname(dirname(__DIR__)) . '/plugin.cfg') as $f) {
            if (!is_file($f)) { continue; }
            $t = preg_replace('/^\s*[#;].*$/m', '', (string) @file_get_contents($f));
            $ini = @parse_ini_string((string) $t, true, INI_SCANNER_RAW);
            if (is_array($ini) && isset($ini['PLUGIN']['VERSION'])) { $v = trim((string) $ini['PLUGIN']['VERSION'], '"'); break; }
        }
    }
    return $v;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Sichern und Zurueckspielen (Muster WOLF ISM NG 3.0.8 / Saugroboter-Valetudo 1.1.37)
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * Sicherungsdatei: ALLE Schluessel der Vorgaben samt Aktionstoken (ohne ihn waere die Datei nach dem Zurueckspielen
 * wertlos) und ein lesbarer Kopf mit "_". Das Formularmerkmal gehoert nicht hinein.
 */
function cr_sicherung_bauen()
{
    $cfg = cr_config();
    $aus = array('_hinweis' => cr_t('TEXT.SICH_HINWEIS_KOPF'), '_stand' => date('Y-m-d H:i'),
                 '_fassung' => cr_pluginversion(), '_plugin' => 'creality2lox');
    foreach (array_keys(cr_vorgaben()) as $k) { $aus[$k] = isset($cfg[$k]) ? $cfg[$k] : null; }
    return $aus;
}

/**
 * Eine Sicherungsdatei pruefen. Rueckgabe array(neue Konfiguration oder null, Beanstandungen, Zahl uebernommen).
 * Fremdes wird vor dem Zusammenfuehren abgelehnt, jeder Wert geprueft, jeder Schluessel der Vorgaben verlangt;
 * eine halb gueltige Datei aendert gar nichts. Schluessel werden maskiert in die Beanstandung gesetzt.
 */
function cr_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) { return array(null, array(cr_t('TEXT.SICH_KEIN_JSON')), 0); }
    if (isset($daten['_plugin']) && $daten['_plugin'] !== 'creality2lox') {
        return array(null, array(sprintf(cr_t('TEXT.SICH_FREMDES_PLUGIN'), cr_e((string) (is_scalar($daten['_plugin']) ? $daten['_plugin'] : '?')))), 0);
    }
    $vorgaben = cr_vorgaben();
    $neu = $vorgaben;
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }
        if (!array_key_exists($k, $vorgaben)) { $mangel[] = sprintf(cr_t('TEXT.SICH_FREMD'), cr_e($k)); continue; }
        if (!cr_wert_taugt($w)) { $mangel[] = sprintf(cr_t('TEXT.SICH_WERT'), cr_e($k), cr_e(cr_t('GRUND.STEUERZEICHEN'))); continue; }
        $grund = cr_wert_pruefen($k, $w);
        if ($grund !== '') { $mangel[] = sprintf(cr_t('TEXT.SICH_WERT'), cr_e($k), cr_e($grund)); continue; }
        $neu[$k] = is_int($vorgaben[$k]) ? (int) $w : $w;
        $anzahl++;
    }
    $fehlend = array();
    foreach (array_keys($vorgaben) as $fk) {
        if (!array_key_exists($fk, $daten)) { $fehlend[] = $fk; }
    }
    if ($fehlend) { $mangel[] = sprintf(cr_t('TEXT.SICH_FEHLEND'), count($fehlend), cr_e(implode(', ', $fehlend))); }
    if ($anzahl === 0 && !$mangel) { $mangel[] = cr_t('TEXT.SICH_LEER'); }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/* ---------------------------------------------------------------------------------------------------------------
 * Moonraker abfragen
 * ------------------------------------------------------------------------------------------------------------- */

/** Die Objekte, die je Lauf abgefragt werden - Namen am Drucker gemessen (/printer/objects/list, 143 Objekte). */
function cr_objekte()
{
    return array('webhooks', 'print_stats', 'virtual_sdcard', 'display_status', 'pause_resume', 'idle_timeout',
                 'heater_bed', 'extruder', 'temperature_sensor chamber_temp', 'heater_generic chamber_heater',
                 'temperature_fan chamber_fan', 'heater_fan hotend_fan', 'output_pin fan0', 'output_pin fan1',
                 'output_pin fan2', 'output_pin LED', 'box');
}

/** Die Abfrageadresse (Pfad mit Abfrage) - Leerzeichen als %20, so wie am Geraet gemessen. */
function cr_query_pfad(array $objekte)
{
    $teile = array();
    foreach ($objekte as $o) { $teile[] = rawurlencode($o); }
    return '/printer/objects/query?' . implode('&', $teile);
}

/**
 * Ein HTTP-Aufruf an Moonraker. Rueckgabe array(http_code, rumpf, fehlertext). Zeitschranke beim Verbindungsaufbau
 * UND beim Lesen; Umleitungen werden auf beiden Wegen nicht verfolgt. Kopfzeilen nach Regeln/03 Abschnitt 9.
 */
function cr_http($methode, $pfad, $tmo = 4, $host = null, $port = null)
{
    $cfg = cr_config(false);
    $host = $host === null ? (string) $cfg['drucker_host'] : (string) $host;
    $port = $port === null ? (int) $cfg['drucker_port'] : (int) $port;
    if ($host === '' || cr_wert_pruefen('drucker_host', $host) !== '' || $port < 1 || $port > 65535) {
        return array(0, '', 'ADRESSE');
    }
    $kopf = array('User-Agent: Creality2Lox/' . (cr_pluginversion() !== '' ? cr_pluginversion() : '0'),
                  'Accept: application/json', 'Accept-Language: ' . cr_sprache(), 'Accept-Encoding: identity');
    $url = 'http://' . $host . ':' . $port . $pfad;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(3, (int) $tmo), CURLOPT_TIMEOUT => (int) $tmo, CURLOPT_HTTPHEADER => $kopf,
            CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_MAXREDIRS => 0));
        if ($methode === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, ''); }
        $rumpf = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = $rumpf === false ? 'VERBINDUNG' : '';
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        return array($code, is_string($rumpf) ? $rumpf : '', $fehler);
    }
    $fp = @fsockopen($host, $port, $en, $es, min(3, (int) $tmo));
    if (!$fp) { return array(0, '', 'VERBINDUNG'); }
    stream_set_timeout($fp, (int) $tmo);
    $anfrage = $methode . ' ' . $pfad . " HTTP/1.0\r\nHost: " . $host . ':' . $port . "\r\n" . implode("\r\n", $kopf)
             . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    @fwrite($fp, $anfrage);
    $antwort = '';
    $runden = 0;
    while (!feof($fp) && strlen($antwort) < 1048576 && $runden < 2000) {
        $b = fread($fp, 8192);
        $runden++;
        if ($b === false) { break; }
        $antwort .= $b;
        $m = stream_get_meta_data($fp);
        if (!empty($m['timed_out'])) { fclose($fp); return array(0, '', 'ZEIT'); }
    }
    fclose($fp);
    $teil = explode("\r\n\r\n", $antwort, 2);
    if (!preg_match('#^HTTP/\d\.\d (\d{3})#', $teil[0], $mm)) { return array(0, '', 'ANTWORT'); }
    return array((int) $mm[1], isset($teil[1]) ? $teil[1] : '', '');
}

/**
 * Den Drucker einmal abfragen. Rueckgabe array(ok, status-Feld der Moonraker-Antwort, grund).
 * ok nur bei HTTP 200 UND gueltigem JSON mit result.status - eine HTTP-Antwort ist kein Lebenszeichen.
 */
function cr_abfragen($tmo = 4)
{
    list($code, $rumpf, $fehler) = cr_http('GET', cr_query_pfad(cr_objekte()), $tmo);
    if ($fehler === 'ADRESSE') { return array(0, array(), 'KEINE_ADRESSE'); }
    if ($fehler !== '') { return array(0, array(), 'NICHT_ERREICHBAR'); }
    if ($code !== 200) { return array(0, array(), 'HTTP_' . $code); }
    $j = json_decode($rumpf, true);
    if (!is_array($j) || !isset($j['result']['status']) || !is_array($j['result']['status'])) {
        return array(0, array(), 'ANTWORT_UNGUELTIG');
    }
    return array(1, $j['result']['status'], '');
}

/** Ein Objekt aus der Antwort - ein leeres Objekt ({} fuer einen unbekannten Namen, gemessen) zaehlt als fehlend. */
function cr_obj(array $s, $name)
{
    return (isset($s[$name]) && is_array($s[$name]) && $s[$name]) ? $s[$name] : null;
}

/** Eine Zahl aus einem Objekt oder null. */
function cr_zahl($o, $feld)
{
    if (!is_array($o) || !array_key_exists($feld, $o)) { return null; }
    $v = $o[$feld];
    if (is_int($v) || is_float($v)) { return is_finite((float) $v) ? (float) $v : null; }
    if (is_string($v) && is_numeric($v)) { return (float) $v; }
    return null;
}

/** Text aus einem Objekt oder null. */
function cr_text($o, $feld)
{
    return (is_array($o) && isset($o[$feld]) && is_string($o[$feld])) ? $o[$feld] : null;
}

/** print_stats.state -> Statuszahl (Moonraker-Dokumentation; am Geraet gemessen nur "standby"). */
function cr_status_code($roh)
{
    $t = array('standby' => 0, 'printing' => 1, 'paused' => 2, 'complete' => 3, 'cancelled' => 4, 'error' => 5);
    return (is_string($roh) && isset($t[$roh])) ? $t[$roh] : -1;
}

/** webhooks.state -> Zahl (ready gemessen; startup, shutdown, error aus der Moonraker-Dokumentation). */
function cr_klipper_code($roh)
{
    $t = array('ready' => 1, 'startup' => 2, 'shutdown' => 3, 'error' => 4);
    return (is_string($roh) && isset($t[$roh])) ? $t[$roh] : 0;
}

/**
 * Aus der Moonraker-Antwort den Zustand bilden - EINE Stelle. Was fehlt, bleibt null (wird nicht gesendet und steht
 * in der Antwortzeile als Fehlwert). Abgeleitete Werte entstehen nur, wenn alle Zutaten da sind.
 */
function cr_zustand_bilden(array $s, $ts)
{
    $ps = cr_obj($s, 'print_stats');
    $vs = cr_obj($s, 'virtual_sdcard');
    $wh = cr_obj($s, 'webhooks');
    $st = array('ok' => 1, 'ts' => (int) $ts);
    $st['klipper_roh'] = cr_text($wh, 'state');
    $st['klipper'] = $wh === null ? null : cr_klipper_code($st['klipper_roh']);
    $st['klipper_text'] = cr_text($wh, 'state_message');
    $st['status_roh'] = cr_text($ps, 'state');
    $st['status'] = $ps === null ? null : cr_status_code($st['status_roh']);
    $p = cr_zahl($vs, 'progress');
    $st['fortschritt'] = $p === null ? null : round(max(0.0, min(1.0, $p)) * 100, 1);
    $dauer = cr_zahl($ps, 'print_duration');
    $gesamt = cr_zahl($ps, 'total_duration');
    $st['druckzeit'] = $dauer === null ? null : (int) floor($dauer / 60);
    $st['gesamtzeit'] = $gesamt === null ? null : (int) floor($gesamt / 60);
    /* Restzeit GESCHAETZT aus Fortschritt und reiner Druckzeit (Pausen zaehlen nicht mit). Moonraker meldet keine
     * Restzeit; die Datei des gemessenen Druckers traegt keine Schaetzung des Slicers (metadata ohne estimated_time,
     * gemessen 12.11.2026). Ohne Druck: 0. Druck unter 1 Prozent: -1 (noch nicht schaetzbar). */
    $st['restzeit'] = null;
    if ($st['status'] !== null) {
        if (in_array($st['status'], array(1, 2), true)) {
            $st['restzeit'] = ($p !== null && $dauer !== null && $p >= 0.01 && $dauer > 0)
                ? (int) round($dauer * (1 - $p) / $p / 60) : -1;
        } else {
            $st['restzeit'] = 0;
        }
    }
    $info = (is_array($ps) && isset($ps['info']) && is_array($ps['info'])) ? $ps['info'] : array();
    $sch = cr_zahl($info, 'current_layer');
    $schg = cr_zahl($info, 'total_layer');
    if ($sch === null) { $sch = cr_zahl($vs, 'layer'); }
    if ($schg === null) { $schg = cr_zahl($vs, 'layer_count'); }
    $st['schicht'] = $sch === null ? null : (int) $sch;
    $st['schichten'] = $schg === null ? null : (int) $schg;
    $fil = cr_zahl($ps, 'filament_used');
    $st['filament'] = $fil === null ? null : round($fil / 1000, 2);
    $st['auftrag'] = cr_text($ps, 'filename');
    $st['meldung'] = cr_text($ps, 'message');
    foreach (array('duese' => 'extruder', 'bett' => 'heater_bed', 'kammerheizer' => 'heater_generic chamber_heater') as $k => $o) {
        $ob = cr_obj($s, $o);
        $t = cr_zahl($ob, 'temperature');
        $z = cr_zahl($ob, 'target');
        $l = cr_zahl($ob, 'power');
        $st[$k] = $t === null ? null : round($t, 1);
        $st[$k . '_soll'] = $z === null ? null : round($z, 1);
        $st[$k . '_leistung'] = $l === null ? null : round(max(0.0, min(1.0, $l)) * 100, 0);
    }
    $kt = cr_zahl(cr_obj($s, 'temperature_sensor chamber_temp'), 'temperature');
    $st['kammer'] = $kt === null ? null : round($kt, 1);
    foreach (array('fan0' => 'output_pin fan0', 'fan1' => 'output_pin fan1', 'fan2' => 'output_pin fan2') as $k => $o) {
        $v = cr_zahl(cr_obj($s, $o), 'value');
        /* Klipper meldet output_pin.value als Anteil 0..1 (Skala 255 in der Konfiguration, gemessen). Ein Wert ueber 1
         * waere die Rohskala - er wird nicht umgerechnet, sondern als nicht gemessen behandelt. */
        $st['l_' . $k] = ($v === null || $v > 1.0) ? null : round(max(0.0, $v) * 100, 0);
    }
    foreach (array('hotend' => 'heater_fan hotend_fan', 'kammer' => 'temperature_fan chamber_fan') as $k => $o) {
        $v = cr_zahl(cr_obj($s, $o), 'speed');
        $st['l_' . $k] = ($v === null || $v > 1.0) ? null : round(max(0.0, $v) * 100, 0);
    }
    $led = cr_zahl(cr_obj($s, 'output_pin LED'), 'value');
    $st['licht'] = $led === null ? null : ($led > 0 ? 1 : 0);
    $err = ($st['status'] === 5) || in_array($st['klipper'], array(3, 4), true);
    $st['fehler'] = ($st['status'] === null && $st['klipper'] === null) ? null : ($err ? 1 : 0);
    $st['fehler_text'] = '';
    if (in_array($st['klipper'], array(3, 4), true)) { $st['fehler_text'] = (string) $st['klipper_text']; }
    elseif ($st['status'] === 5) { $st['fehler_text'] = (string) $st['meldung']; }
    $st['cfs'] = cr_cfs_bilden(cr_obj($s, 'box'));
    $st['cfs_anzahl'] = $st['cfs'] === null ? null : count(array_filter($st['cfs'], function ($b) { return !empty($b['verbunden']); }));
    return $st;
}

/**
 * Die Creality-Materialboxen (CFS) aus dem Objekt "box" (gemessen 12.11.2026: T1 und T2 verbunden, T3/T4 "None").
 * Je Box: verbunden, Temperatur, Feuchte; je Fach A-D: belegt, Materialname (aus same_material) oder Code, Farbe,
 * Rest (Rohwert, Einheit nicht belegt). Rueckgabe null, wenn es das Objekt nicht gibt.
 */
function cr_cfs_bilden($box)
{
    if (!is_array($box)) { return null; }
    $namen = array();
    if (isset($box['same_material']) && is_array($box['same_material'])) {
        foreach ($box['same_material'] as $e) {
            if (is_array($e) && isset($e[2], $e[3]) && is_array($e[2]) && is_string($e[3])) {
                foreach ($e[2] as $fach) { if (is_string($fach)) { $namen[$fach] = $e[3]; } }
            }
        }
    }
    $aus = array();
    for ($n = 1; $n <= CR_CFS_MAX; $n++) {
        $t = (isset($box['T' . $n]) && is_array($box['T' . $n])) ? $box['T' . $n] : array();
        $verb = isset($t['state']) && $t['state'] === 'connect';
        $b = array('verbunden' => $verb ? 1 : 0, 'temperatur' => null, 'feuchte' => null, 'faecher' => array());
        if ($verb) {
            $tt = cr_zahl($t, 'temperature');
            $ff = cr_zahl($t, 'dry_and_humidity');
            $b['temperatur'] = $tt === null ? null : round($tt, 1);
            $b['feuchte'] = ($ff === null || $ff < 0 || $ff > 100) ? null : round($ff, 0);
            foreach (array('a', 'b', 'c', 'd') as $i => $k) {
                $typ = (isset($t['material_type'][$i]) && is_string($t['material_type'][$i])) ? $t['material_type'][$i] : null;
                $farbe = (isset($t['color_value'][$i]) && is_string($t['color_value'][$i])) ? $t['color_value'][$i] : null;
                $rest = (isset($t['remain_len'][$i]) && is_numeric($t['remain_len'][$i])) ? (int) $t['remain_len'][$i] : null;
                $belegt = ($typ !== null && $typ !== '-1' && $typ !== '' && $typ !== 'None') ? 1 : 0;
                $fach = 'T' . $n . strtoupper($k);
                $b['faecher'][$k] = array(
                    'belegt' => $typ === null ? null : $belegt,
                    'typ' => $belegt ? (isset($namen[$fach]) ? $namen[$fach] : $typ) : '',
                    /* Schluss, nicht gemessen: sieben Hexzeichen, die letzten sechs als RGB gedeutet. */
                    'farbe' => ($belegt && $farbe !== null && preg_match('/^[0-9A-Fa-f]([0-9A-Fa-f]{6})\z/', $farbe, $m)) ? '#' . strtoupper($m[1]) : '',
                    'rest' => $belegt ? $rest : null,
                );
            }
        }
        $aus[$n] = $b;
    }
    return $aus;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Lauf und Zustand (der Minutenlauf ist die einzige Stelle, die beides fortschreibt)
 * ------------------------------------------------------------------------------------------------------------- */

function cr_lauf_lesen()
{
    $f = cr_tmpdir() . '/lauf.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d)) { $d = array(); }
    $d += array('ts' => 0, 'zaehler' => -1, 'ok' => 0, 'grund' => '', 'dauer_ms' => -1);
    return array('ts' => (int) $d['ts'], 'zaehler' => (int) $d['zaehler'], 'ok' => (int) $d['ok'],
                 'grund' => (string) $d['grund'], 'dauer_ms' => (int) $d['dauer_ms']);
}

function cr_lauf_setzen($ok, $grund, $dauer_ms)
{
    $a = cr_lauf_lesen();
    $neu = array('ts' => time(), 'zaehler' => ((int) max(-1, $a['zaehler']) + 1) % 1000, 'ok' => $ok ? 1 : 0,
                 'grund' => (string) $grund, 'dauer_ms' => (int) $dauer_ms);
    cr_write_json(cr_tmpdir() . '/lauf.json', $neu);
    return $neu;
}

/** Alter zur Lesezeit; -1 heisst "noch nie" (Zeitstempel 0). */
function cr_lauf_alter()
{
    $a = cr_lauf_lesen();
    return $a['ts'] > 0 ? max(0, time() - $a['ts']) : -1;
}

/** Der zuletzt GEMESSENE Zustand (eine Stoerung ueberschreibt ihn nicht). null = noch nie gemessen. */
function cr_zustand_lesen()
{
    $f = cr_tmpdir() . '/zustand.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($d) && isset($d['ts']) ? $d : null;
}

/**
 * Ein Durchgang: abfragen, bei Erfolg den Zustand schreiben, den Lauf fortschreiben. Rueckgabe array(lauf, zustand).
 * Bei einer Stoerung bleibt zustand.json der letzte gemessene Stand.
 */
function cr_durchgang()
{
    $t0 = microtime(true);
    list($ok, $status, $grund) = cr_abfragen(4);
    $st = null;
    if ($ok) {
        $st = cr_zustand_bilden($status, time());
        if (!cr_write_json(cr_tmpdir() . '/zustand.json', $st)) {
            cr_log_if_changed('zustand', 'Der Zustand liess sich nicht nach ' . cr_tmpdir() . ' schreiben.');
        }
    }
    $lauf = cr_lauf_setzen($ok, $grund, (int) round((microtime(true) - $t0) * 1000));
    cr_log_if_changed('erreichbar', $ok ? 'Drucker antwortet (Moonraker).' : 'Drucker antwortet nicht: ' . $grund);
    return array($lauf, $st);
}

/* ---------------------------------------------------------------------------------------------------------------
 * Feldtabelle - EINE Quelle fuer Antwortzeile, MQTT, Vorlage, Namenstabelle und Anleitung
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * name => array(
 *   0 analog (1/0), 1 MinVal, 2 MaxVal, 3 Einheit, 4 Schluessel der Bedeutung, 5 retain (1 = zurueckbehalten),
 *   6 Schluessel des Kachelnamens, 7 MQTT-Thema ('' = nur HTTP), 8 in der Antwortzeile (1/0), 9 Fehlwert der Zeile,
 *   10 Art ('zahl' oder 'text'), 11 Nachkommastellen, 12 Platzhalter fuer sprintf in Bedeutung/Kachel (Box, Fach)
 * )
 * Retain nach Hausstandard: Zustaende retained, Messwerte mit Zeitbezug nicht, das Lebenszeichen nie.
 */
function cr_felder()
{
    static $f = null;
    if ($f !== null) { return $f; }
    $f = array(
        'OK'          => array(0, 0, 1, '', 'OK', 0, 'OK', '', 1, 0, 'zahl', 0, array()),
        'ALTER'       => array(1, -1, 100000, 's', 'ALTER', 0, 'ALTER', '', 1, -1, 'zahl', 0, array()),
        'ZAEHLER'     => array(1, -1, 999, '', 'ZAEHLER', 0, 'ZAEHLER', '', 1, -1, 'zahl', 0, array()),
        'KLIPPER'     => array(1, 0, 4, '', 'KLIPPER', 1, 'KLIPPER', 'klipper_code', 1, 0, 'zahl', 0, array()),
        'STATUS'      => array(1, -1, 5, '', 'STATUS', 1, 'STATUS', 'status_code', 1, -1, 'zahl', 0, array()),
        'BEREIT'      => array(0, 0, 1, '', 'BEREIT', 1, 'BEREIT', 'zustand/bereit', 1, 0, 'zahl', 0, array()),
        'DRUCKT'      => array(0, 0, 1, '', 'DRUCKT', 1, 'DRUCKT', 'zustand/druckt', 1, 0, 'zahl', 0, array()),
        'PAUSIERT'    => array(0, 0, 1, '', 'PAUSIERT', 1, 'PAUSIERT', 'zustand/pausiert', 1, 0, 'zahl', 0, array()),
        'FERTIG'      => array(0, 0, 1, '', 'FERTIG', 1, 'FERTIG', 'zustand/fertig', 1, 0, 'zahl', 0, array()),
        'ABGEBROCHEN' => array(0, 0, 1, '', 'ABGEBROCHEN', 1, 'ABGEBROCHEN', 'zustand/abgebrochen', 1, 0, 'zahl', 0, array()),
        'FEHLER'      => array(0, 0, 1, '', 'FEHLER', 1, 'FEHLER', 'zustand/fehler', 1, 0, 'zahl', 0, array()),
        'FORTSCHRITT' => array(1, -1, 100, '%', 'FORTSCHRITT', 0, 'FORTSCHRITT', 'fortschritt_prozent', 1, -1, 'zahl', 1, array()),
        'RESTZEIT'    => array(1, -1, 100000, 'min', 'RESTZEIT', 0, 'RESTZEIT', 'restzeit_min', 1, -1, 'zahl', 0, array()),
        'DRUCKZEIT'   => array(1, -1, 100000, 'min', 'DRUCKZEIT', 0, 'DRUCKZEIT', 'druckzeit_min', 1, -1, 'zahl', 0, array()),
        'GESAMTZEIT'  => array(1, -1, 100000, 'min', 'GESAMTZEIT', 0, 'GESAMTZEIT', 'gesamtzeit_min', 1, -1, 'zahl', 0, array()),
        'SCHICHT'     => array(1, -1, 100000, '', 'SCHICHT', 0, 'SCHICHT', 'schicht', 1, -1, 'zahl', 0, array()),
        'SCHICHTEN'   => array(1, -1, 100000, '', 'SCHICHTEN', 1, 'SCHICHTEN', 'schichten_gesamt', 1, -1, 'zahl', 0, array()),
        'FILAMENT'    => array(1, -1, 100000, 'm', 'FILAMENT', 0, 'FILAMENT', 'filament_m', 1, -1, 'zahl', 2, array()),
        'DUESE'       => array(1, -999, 500, '°C', 'DUESE', 0, 'DUESE', 'duese_c', 1, -999, 'zahl', 1, array()),
        'DUESESOLL'   => array(1, -999, 500, '°C', 'DUESESOLL', 1, 'DUESESOLL', 'duese_soll_c', 1, -999, 'zahl', 1, array()),
        'DUESELEISTUNG' => array(1, -1, 100, '%', 'DUESELEISTUNG', 0, 'DUESELEISTUNG', 'duese_leistung_prozent', 1, -1, 'zahl', 0, array()),
        'BETT'        => array(1, -999, 200, '°C', 'BETT', 0, 'BETT', 'bett_c', 1, -999, 'zahl', 1, array()),
        'BETTSOLL'    => array(1, -999, 200, '°C', 'BETTSOLL', 1, 'BETTSOLL', 'bett_soll_c', 1, -999, 'zahl', 1, array()),
        'BETTLEISTUNG' => array(1, -1, 100, '%', 'BETTLEISTUNG', 0, 'BETTLEISTUNG', 'bett_leistung_prozent', 1, -1, 'zahl', 0, array()),
        'KAMMER'      => array(1, -999, 150, '°C', 'KAMMER', 0, 'KAMMER', 'kammer_c', 1, -999, 'zahl', 1, array()),
        'KAMMERSOLL'  => array(1, -999, 150, '°C', 'KAMMERSOLL', 1, 'KAMMERSOLL', 'kammer_soll_c', 1, -999, 'zahl', 1, array()),
        'KAMMERHEIZUNG' => array(1, -1, 100, '%', 'KAMMERHEIZUNG', 0, 'KAMMERHEIZUNG', 'kammerheizung_prozent', 1, -1, 'zahl', 0, array()),
        'LFAN0'       => array(1, -1, 100, '%', 'LFAN0', 0, 'LFAN0', 'luefter/fan0_prozent', 1, -1, 'zahl', 0, array()),
        'LFAN1'       => array(1, -1, 100, '%', 'LFAN1', 0, 'LFAN1', 'luefter/fan1_prozent', 1, -1, 'zahl', 0, array()),
        'LFAN2'       => array(1, -1, 100, '%', 'LFAN2', 0, 'LFAN2', 'luefter/fan2_prozent', 1, -1, 'zahl', 0, array()),
        'LHOTEND'     => array(1, -1, 100, '%', 'LHOTEND', 0, 'LHOTEND', 'luefter/hotend_prozent', 1, -1, 'zahl', 0, array()),
        'LKAMMER'     => array(1, -1, 100, '%', 'LKAMMER', 0, 'LKAMMER', 'luefter/kammer_prozent', 1, -1, 'zahl', 0, array()),
        'LICHT'       => array(0, -1, 1, '', 'LICHT', 1, 'LICHT', 'licht', 1, -1, 'zahl', 0, array()),
        'CFS'         => array(1, -1, 4, '', 'CFS', 1, 'CFS', 'cfs/anzahl', 1, -1, 'zahl', 0, array()),
        'STATUSROH'   => array(0, 0, 0, '', 'STATUSROH', 1, 'STATUSROH', 'status_roh', 0, '', 'text', 0, array()),
        'STATUSTEXT'  => array(0, 0, 0, '', 'STATUSTEXT', 1, 'STATUSTEXT', 'status_text', 0, '', 'text', 0, array()),
        'KLIPPERTEXT' => array(0, 0, 0, '', 'KLIPPERTEXT', 0, 'KLIPPERTEXT', 'klipper_text', 0, '', 'text', 0, array()),
        'AUFTRAG'     => array(0, 0, 0, '', 'AUFTRAG', 1, 'AUFTRAG', 'auftrag', 0, '', 'text', 0, array()),
        'MELDUNG'     => array(0, 0, 0, '', 'MELDUNG', 0, 'MELDUNG', 'meldung', 0, '', 'text', 0, array()),
        'FEHLERTEXT'  => array(0, 0, 0, '', 'FEHLERTEXT', 0, 'FEHLERTEXT', 'fehler_text', 0, '', 'text', 0, array()),
    );
    for ($n = 1; $n <= CR_CFS_MAX; $n++) {
        $f['CFS' . $n . 'VERB'] = array(0, 0, 1, '', 'CFS_VERB', 1, 'CFS_VERB', 'cfs/' . $n . '/verbunden', 1, 0, 'zahl', 0, array($n));
        $f['CFS' . $n . 'TEMP'] = array(1, -999, 100, '°C', 'CFS_TEMP', 0, 'CFS_TEMP', 'cfs/' . $n . '/temperatur_c', 1, -999, 'zahl', 1, array($n));
        $f['CFS' . $n . 'FEUCHTE'] = array(1, -1, 100, '%', 'CFS_FEUCHTE', 0, 'CFS_FEUCHTE', 'cfs/' . $n . '/feuchte_prozent', 1, -1, 'zahl', 0, array($n));
        foreach (array('a', 'b', 'c', 'd') as $k) {
            $K = strtoupper($k);
            $f['CFS' . $n . $K . 'BELEGT'] = array(0, 0, 1, '', 'CFS_BELEGT', 1, 'CFS_BELEGT', 'cfs/' . $n . '/fach/' . $k . '/belegt', 0, 0, 'zahl', 0, array($n, $K));
            $f['CFS' . $n . $K . 'REST'] = array(1, -1, 1000, '', 'CFS_REST', 1, 'CFS_REST', 'cfs/' . $n . '/fach/' . $k . '/rest_roh', 0, -1, 'zahl', 0, array($n, $K));
            $f['CFS' . $n . $K . 'TYP'] = array(0, 0, 0, '', 'CFS_TYP', 1, 'CFS_TYP', 'cfs/' . $n . '/fach/' . $k . '/material', 0, '', 'text', 0, array($n, $K));
            $f['CFS' . $n . $K . 'FARBE'] = array(0, 0, 0, '', 'CFS_FARBE', 1, 'CFS_FARBE', 'cfs/' . $n . '/fach/' . $k . '/farbe', 0, '', 'text', 0, array($n, $K));
        }
    }
    return $f;
}

/** Bedeutung eines Feldes (Sprachdatei, Spalte "Bedeutung" der Feldtabelle). */
function cr_feld_bedeutung($name)
{
    $f = cr_felder();
    return vsprintf(cr_t('FELD.' . $f[$name][4]), $f[$name][12]);
}

/** Kachelname eines Feldes (wird in Loxone Config zum Anzeigenamen, kurz). */
function cr_feld_kachel($name)
{
    $f = cr_felder();
    return vsprintf(cr_t('KACHEL.' . $f[$name][6]), $f[$name][12]);
}

/** Der Statustext in der Sprache der Anlage. */
function cr_status_text($code)
{
    return cr_t('STATUS.S' . ($code === null ? 'X' : ((int) $code < 0 ? 'X' : (int) $code)));
}

/**
 * Die Werte aller Felder aus einem Zustand - EINE Stelle fuer HTTP, MQTT und Vorlage. null = nicht gemessen.
 * OK, ALTER und ZAEHLER kommen aus dem Lauf, nicht aus dem Zustand.
 */
function cr_werte($st, $lauf = null)
{
    if ($lauf === null) { $lauf = cr_lauf_lesen(); }
    $st = is_array($st) ? $st : array();
    $g = function ($k) use ($st) { return array_key_exists($k, $st) ? $st[$k] : null; };
    $code = $g('status');
    $w = array();
    $w['OK'] = (int) $lauf['ok'];
    $w['ALTER'] = $lauf['ts'] > 0 ? max(0, time() - (int) $lauf['ts']) : -1;
    $w['ZAEHLER'] = (int) $lauf['zaehler'];
    $w['KLIPPER'] = $g('klipper');
    $w['STATUS'] = $code;
    foreach (array('BEREIT' => 0, 'DRUCKT' => 1, 'PAUSIERT' => 2, 'FERTIG' => 3, 'ABGEBROCHEN' => 4) as $n => $c) {
        $w[$n] = $code === null ? null : ((int) $code === $c ? 1 : 0);
    }
    $w['FEHLER'] = $g('fehler');
    $w['FORTSCHRITT'] = $g('fortschritt');
    $w['RESTZEIT'] = $g('restzeit');
    $w['DRUCKZEIT'] = $g('druckzeit');
    $w['GESAMTZEIT'] = $g('gesamtzeit');
    $w['SCHICHT'] = $g('schicht');
    $w['SCHICHTEN'] = $g('schichten');
    $w['FILAMENT'] = $g('filament');
    $w['DUESE'] = $g('duese');
    $w['DUESESOLL'] = $g('duese_soll');
    $w['DUESELEISTUNG'] = $g('duese_leistung');
    $w['BETT'] = $g('bett');
    $w['BETTSOLL'] = $g('bett_soll');
    $w['BETTLEISTUNG'] = $g('bett_leistung');
    $w['KAMMER'] = $g('kammer');
    $w['KAMMERSOLL'] = $g('kammerheizer_soll');
    $w['KAMMERHEIZUNG'] = $g('kammerheizer_leistung');
    $w['LFAN0'] = $g('l_fan0');
    $w['LFAN1'] = $g('l_fan1');
    $w['LFAN2'] = $g('l_fan2');
    $w['LHOTEND'] = $g('l_hotend');
    $w['LKAMMER'] = $g('l_kammer');
    $w['LICHT'] = $g('licht');
    $w['CFS'] = $g('cfs_anzahl');
    $w['STATUSROH'] = $g('status_roh');
    $w['STATUSTEXT'] = $code === null ? null : cr_status_text($code);
    $w['KLIPPERTEXT'] = $g('klipper_text');
    $w['AUFTRAG'] = $g('auftrag');
    $w['MELDUNG'] = $g('meldung');
    $w['FEHLERTEXT'] = $g('fehler') === null ? null : (string) $g('fehler_text');
    $cfs = is_array($g('cfs')) ? $g('cfs') : null;
    for ($n = 1; $n <= CR_CFS_MAX; $n++) {
        $b = ($cfs !== null && isset($cfs[$n]) && is_array($cfs[$n])) ? $cfs[$n] : null;
        $w['CFS' . $n . 'VERB'] = $b === null ? null : (int) $b['verbunden'];
        $w['CFS' . $n . 'TEMP'] = $b === null ? null : $b['temperatur'];
        $w['CFS' . $n . 'FEUCHTE'] = $b === null ? null : $b['feuchte'];
        foreach (array('a', 'b', 'c', 'd') as $k) {
            $K = strtoupper($k);
            $x = ($b !== null && isset($b['faecher'][$k]) && is_array($b['faecher'][$k])) ? $b['faecher'][$k] : null;
            $w['CFS' . $n . $K . 'BELEGT'] = $x === null ? null : $x['belegt'];
            $w['CFS' . $n . $K . 'REST'] = $x === null ? null : $x['rest'];
            $w['CFS' . $n . $K . 'TYP'] = $x === null ? null : $x['typ'];
            $w['CFS' . $n . $K . 'FARBE'] = $x === null ? null : $x['farbe'];
        }
    }
    return $w;
}

/** Einen Zahlenwert ausgeben (Punkt als Dezimalzeichen, feste Nachkommastellen). */
function cr_zahl_text($v, $stellen)
{
    if ($stellen <= 0) { return (string) (int) round((float) $v); }
    return number_format((float) $v, (int) $stellen, '.', '');
}

/** Die Befehlserkennung fuer Loxone - EINE Stelle, mit fuehrendem Semikolon (Regeln/07 Abschnitt 6). */
function cr_check($feld) { return '\i;' . $feld . '=\i\v'; }

/** Die Antwortzeile fuer den Miniserver - aus der Feldliste, Textfelder nie. */
function cr_zeile($st, $lauf = null)
{
    $w = cr_werte($st, $lauf);
    $teile = array('CREALITY');
    foreach (cr_felder() as $name => $f) {
        if ($f[8] !== 1) { continue; }
        $v = $w[$name];
        $teile[] = $name . '=' . ($v === null ? cr_zahl_text($f[9], 0) : cr_zahl_text($v, $f[11]));
    }
    return implode(';', $teile);
}

/* ---------------------------------------------------------------------------------------------------------------
 * MQTT ueber den UDP-Eingang des Gateways (Bauart Saugroboter-Valetudo 1.1.37)
 * ------------------------------------------------------------------------------------------------------------- */

function cr_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim((string) preg_replace('/ {2,}/', ' ', $wert));
}

/** Das Praefix gesaeubert - auch eine zurueckgespielte Datei kann keine zweite publish-Zeile einschmuggeln. */
function cr_mqtt_thema_saeubern($t)
{
    $t = trim((string) preg_replace('#[^\w/\-]#', '', (string) $t), '/');
    return $t !== '' ? $t : 'creality';
}

/** Eine Zeile fuer den UDP-Eingang; ein leerer Wert geht nie retained hinaus (er loeschte das Thema). */
function cr_mqtt_zeile($retain, $thema, $wert)
{
    $w = cr_mqtt_wert_saeubern($wert);
    return (($retain && $w !== '') ? 'retain' : 'publish') . ' ' . $thema . ' ' . $w;
}

/** Der UDP-Eingangsport des Gateways aus general.json - 0, wenn keiner da ist. */
function cr_mqtt_udpport()
{
    $p = cr_paths();
    if ($p['lbhome'] === '') { return 0; }
    $gen = json_decode((string) @file_get_contents($p['general']), true);
    $udp = (is_array($gen) && isset($gen['Mqtt']['Udpinport'])) ? (int) $gen['Mqtt']['Udpinport'] : 0;
    return ($udp > 0 && $udp <= 65535) ? $udp : 0;
}

/** UDP-Pakete an den Gateway-Eingang, mindestens 5 ms Abstand (der Eingang verwirft stossweise, Regeln/07). */
function cr_udp_senden($port, $zeilen)
{
    $en = 0; $es = '';
    $fp = @stream_socket_client('udp://127.0.0.1:' . (int) $port, $en, $es, 2);
    if ($fp === false) { return 0; }
    $n = 0;
    static $letzt = 0.0;
    foreach ((array) $zeilen as $z) {
        $warte = 0.005 - (microtime(true) - $letzt);
        if ($warte > 0) { usleep((int) ceil($warte * 1000000)); }
        if (@fwrite($fp, $z) !== false) { $n++; }
        $letzt = microtime(true);
    }
    @fclose($fp);
    return $n;
}

/** Zustand und Fassung des MQTT-Gateways aus general.json; null = nicht lesbar. Fassung 0 = unbekannt. */
function cr_mqtt_gateway_info()
{
    $p = cr_paths();
    if ($p['lbhome'] === '') { return null; }
    $d = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return null; }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    return array('autostart' => in_array((string) $auto, array('1', 'true'), true),
                 'fassung' => isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0);
}

/** Die Abo-Datei des Gateways V1 (config/plugins/<ordner>/mqtt_subscriptions.cfg) fuer ein einstellbares Praefix. */
function cr_abo_datei($praefix, $schreiben = false)
{
    $p = cr_paths();
    if ($p['lbhome'] === '') { return array('', false); }
    $pfad = dirname($p['config']) . '/mqtt_subscriptions.cfg';
    $soll = $praefix . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && cr_wert_pruefen('mqtt_topic', $praefix) === '' && $roh !== $soll . "\n" && is_dir(dirname($pfad))) {
        if (cr_write_atomic($pfad, $soll . "\n", 0644)) {
            cr_log('MQTT: Abo-Datei des Gateways auf ' . $soll . ' gesetzt.');
            $da = true;
        } else {
            cr_log_if_changed('mqtt_abo', 'Die Abo-Datei ' . $pfad . ' liess sich nicht schreiben.');
        }
    }
    return array($pfad, $da);
}

/** Was in diesem Zustand ueber MQTT hinausgeht: Thema (ohne Praefix) => Wert. null wird nie gesendet. */
function cr_mqtt_werte($st)
{
    $w = cr_werte($st);
    $aus = array();
    foreach (cr_felder() as $name => $f) {
        if ($f[7] === '' || $w[$name] === null) { continue; }
        $aus[$f[7]] = $f[10] === 'text' ? (string) $w[$name] : cr_zahl_text($w[$name], $f[11]);
    }
    return $aus;
}

/** Thema (ohne Praefix) -> retained? Positivliste aus der Feldtabelle (Spalte 5). */
function cr_mqtt_retain($thema)
{
    foreach (cr_felder() as $f) {
        if ($f[7] === $thema) { return $f[5] === 1; }
    }
    return false;
}

/**
 * Die Werte senden: nur Geaendertes, alle 30 Minuten und nach einem Praefixwechsel der volle Satz. Bei einer
 * Stoerung geht NICHTS von den Werten hinaus (nur das Lebenszeichen) - die Werte im Broker sind das zuletzt Gemessene.
 */
function cr_mqtt_senden($st, $voll = false)
{
    $cfg = cr_config(false);
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $udp = cr_mqtt_udpport();
    if (!$udp || !is_array($st) || empty($st['ok'])) { return 0; }
    $praefix = cr_mqtt_thema_saeubern($cfg['mqtt_topic']);
    $af = cr_tmpdir() . '/mqtt_gesendet.json';
    $abbild = is_file($af) ? json_decode((string) @file_get_contents($af), true) : null;
    $gilt = is_array($abbild) && isset($abbild['praefix'], $abbild['werte'], $abbild['voll'])
            && $abbild['praefix'] === $praefix && is_array($abbild['werte']);
    if (!$gilt || time() - (int) $abbild['voll'] >= CR_VOLL_SENDEN || (int) $abbild['voll'] > time()) { $voll = true; }
    $gesendet = $gilt ? $abbild['werte'] : array();
    $zeilen = array();
    foreach (cr_mqtt_werte($st) as $t => $v) {
        $v = cr_mqtt_wert_saeubern($v);
        if (!$voll && array_key_exists($t, $gesendet) && (string) $gesendet[$t] === $v) { continue; }
        $zeilen[] = cr_mqtt_zeile(cr_mqtt_retain($t), $praefix . '/' . $t, $v);
        $gesendet[$t] = $v;
    }
    $n = $zeilen ? cr_udp_senden($udp, $zeilen) : 0;
    cr_write_json($af, array('praefix' => $praefix, 'werte' => $gesendet, 'voll' => $voll ? time() : (int) $abbild['voll']));
    return $n;
}

/** Das Lebenszeichen - bei JEDEM Durchgang, am Doppelt-senden-Filter vorbei, nie retained. */
function cr_mqtt_lebenszeichen($lauf = null)
{
    $cfg = cr_config(false);
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $udp = cr_mqtt_udpport();
    if (!$udp) { return 0; }
    if ($lauf === null) { $lauf = cr_lauf_lesen(); }
    $w = cr_mqtt_thema_saeubern($cfg['mqtt_topic']);
    return cr_udp_senden($udp, array('publish ' . $w . '/status/ok ' . (int) $lauf['ok'],
                                     'publish ' . $w . '/status/ts ' . (int) $lauf['ts'],
                                     'publish ' . $w . '/status/zaehler ' . (int) $lauf['zaehler']));
}

/**
 * ALLE Themen dieses Plugins mit Feld, Bedeutung und Retain - die Tabelle im Reiter MQTT. Die Pruefzeile im Reiter
 * Test haelt sie gegen das, was cr_mqtt_werte() fuer einen voll belegten Zustand wirklich bildet.
 */
function cr_mqtt_themen($praefix = null)
{
    $w = cr_mqtt_thema_saeubern($praefix === null ? cr_config(false)['mqtt_topic'] : $praefix);
    $aus = array(
        $w . '/status/ok' => array('feld' => 'OK', 'bedeutung' => cr_t('MQTT.T_OK'), 'retain' => 0, 'einheit' => ''),
        $w . '/status/ts' => array('feld' => '', 'bedeutung' => cr_t('MQTT.T_TS'), 'retain' => 0, 'einheit' => 's'),
        $w . '/status/zaehler' => array('feld' => 'ZAEHLER', 'bedeutung' => cr_t('MQTT.T_ZAEHLER'), 'retain' => 0, 'einheit' => ''),
        $w . '/status/befehle' => array('feld' => '', 'bedeutung' => cr_t('MQTT.T_BEFEHLE'), 'retain' => 0, 'einheit' => ''),
    );
    foreach (cr_felder() as $name => $f) {
        if ($f[7] === '') { continue; }
        $aus[$w . '/' . $f[7]] = array('feld' => $name, 'bedeutung' => cr_feld_bedeutung($name), 'retain' => $f[5],
                                       'einheit' => $f[3], 'text' => $f[10] === 'text' ? 1 : 0);
    }
    return $aus;
}

/** Name des Virtuellen Eingangs, den das Gateway fuer ein Thema mit Werten fuellt (nur "/" und "%" werden "_",
 *  Regeln/07). Den Eingang selbst legt der Anwender in Loxone Config mit genau diesem Namen an. */
function cr_gateway_name($thema)
{
    return str_replace(array('/', '%'), '_', (string) $thema);
}

/**
 * Ein voll belegter Beispielzustand (alle Felder gemessen) - nur fuer die Pruefzeilen und die Vorlage vor dem ersten
 * Abruf. Kein Messwert.
 */
function cr_beispielzustand()
{
    $box = array('same_material' => array(array('101001', '0C12E1F', array('T1B'), 'PLA')));
    for ($n = 1; $n <= CR_CFS_MAX; $n++) {
        $box['T' . $n] = array('state' => 'connect', 'temperature' => '23', 'dry_and_humidity' => '40',
            'material_type' => array('101001', '101001', '101001', '101001'),
            'color_value' => array('0FFFFFF', '0C12E1F', '0FFFFFF', '0FFFFFF'), 'remain_len' => array('100', '100', '100', '100'));
    }
    $s = array('webhooks' => array('state' => 'ready', 'state_message' => 'Printer is ready'),
        'print_stats' => array('filename' => 'beispiel.gcode', 'total_duration' => 600.0, 'print_duration' => 540.0,
            'filament_used' => 1234.5, 'state' => 'printing', 'message' => '', 'info' => array('total_layer' => 100, 'current_layer' => 10)),
        'virtual_sdcard' => array('progress' => 0.25, 'layer' => 10, 'layer_count' => 100),
        'heater_bed' => array('temperature' => 60.0, 'target' => 60.0, 'power' => 0.3),
        'extruder' => array('temperature' => 220.0, 'target' => 220.0, 'power' => 0.5),
        'temperature_sensor chamber_temp' => array('temperature' => 35.0),
        'heater_generic chamber_heater' => array('temperature' => 35.0, 'target' => 40.0, 'power' => 0.2),
        'temperature_fan chamber_fan' => array('speed' => 0.4), 'heater_fan hotend_fan' => array('speed' => 1.0),
        'output_pin fan0' => array('value' => 0.5), 'output_pin fan1' => array('value' => 0.0), 'output_pin fan2' => array('value' => 0.3),
        'output_pin LED' => array('value' => 1.0), 'box' => $box);
    return cr_zustand_bilden($s, time());
}

/* ---------------------------------------------------------------------------------------------------------------
 * Loxone-Vorlagen (Nachbau nach den Ausfuhren aus Loxone Config 17.1, Regeln/07 Abschnitt 5)
 * ------------------------------------------------------------------------------------------------------------- */

function cr_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/** Der Rechnername fuer die angezeigten und erzeugten Adressen - EINE Stelle. */
function cr_host()
{
    return isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? (string) preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/** Die Adresse des Endpunkts - EINE Stelle fuer Vorlage, Tabelle und Knoepfe. */
function cr_endpunkt_pfad($werte = array())
{
    $teile = array();
    foreach ($werte as $k => $v) { $teile[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v); }
    return '/plugins/' . cr_plugin_ordner() . '/drucker.php' . ($teile ? '?' . implode('&', $teile) : '');
}

/** Gepruefter PHP-Nachbau des LoxoneTemplateBuilder (VirtualInHttp): Attributreihenfolge, CRLF, Tabulator. */
function cr_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="' . cr_x($kopf['title']) . '" Comment="' . cr_x($kopf['comment'])
        . '" Address="' . cr_x($kopf['address']) . '" PollingTime="' . cr_x($kopf['polling']) . '">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd Title="' . cr_x($c['title']) . '" Comment="' . cr_x($c['comment'])
            . '" Check="' . cr_x($c['check']) . '" Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" Analog="'
            . ($c['analog'] ? 'true' : 'false') . '" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" '
            . 'MinVal="' . (int) $c['min'] . '" MaxVal="' . (int) $c['max'] . '" Unit="' . cr_x($c['unit']) . '" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Vorlage der Werte ueber den HTTP-Lesezweig (VI). Nur Felder, die der Drucker in der letzten Messung lieferte;
 * vor der ersten Messung alle, mit Hinweis im Kopf. Rueckgabe array(Dateiname, Inhalt).
 */
function cr_vorlage_vi()
{
    $st = cr_zustand_lesen();
    $w = $st !== null ? cr_werte($st) : null;
    $cmds = array();
    foreach (cr_felder() as $name => $f) {
        if ($f[8] !== 1) { continue; }
        if ($w !== null && !in_array($name, array('OK', 'ALTER', 'ZAEHLER'), true) && $w[$name] === null) { continue; }
        $cmds[] = array('title' => 'CREALITY_' . $name, 'comment' => cr_t('LOX.VORSATZ') . cr_feld_kachel($name)
            . ($f[3] !== '' ? ' [' . $f[3] . ']' : ''), 'check' => cr_check($name),
            'unit' => $f[3] !== '' ? '<v.1> ' . $f[3] : '<v.1>', 'analog' => $f[0], 'min' => $f[1], 'max' => $f[2]);
    }
    $kom = sprintf(cr_t('LOX.VORLAGE_KOMMENTAR'), date('d.m.Y')) . ($st === null ? ' ' . cr_t('LOX.VORLAGE_VOR_MESSUNG') : '');
    return array('VI_creality2lox.xml', cr_xml_virtual_in_http(array('title' => '3D-Drucker (Creality2Lox)', 'comment' => $kom,
        'address' => 'http://' . cr_host() . cr_endpunkt_pfad(), 'polling' => '30'), $cmds));
}

/** Vorlage der Befehle ueber den HTTP-Endpunkt (VirtualOut, mit Aktionstoken). Nur freigegebene Befehle. */
function cr_vorlage_vq()
{
    $cfg = cr_config();
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="3D-Drucker steuern (Creality2Lox)" Comment="' . cr_x(cr_t('LOX.VQ_KOMMENTAR'))
        . '" Address="http://' . cr_x(cr_host()) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (cr_befehle() as $name => $b) {
        if (empty($cfg[$b['freigabe']])) { continue; }
        $ein = array('cmd' => $name); $aus = '';
        if ($b['art'] === 'schalter') { $ein['wert'] = '1'; $aus = cr_endpunkt_pfad(array('cmd' => $name, 'wert' => '0', 'token' => $cfg['aktionstoken'])); }
        $ein['token'] = $cfg['aktionstoken'];
        $o .= "\t" . '<VirtualOutCmd Title="' . cr_x('CREALITY_' . strtoupper($name)) . '" Comment="' . cr_x(cr_t('LOX.VORSATZ') . cr_t('BEFEHL.K_' . strtoupper($name)))
            . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . cr_x(cr_endpunkt_pfad($ein)) . '" CmdOnHTTP="" CmdOnPost="" CmdOff="'
            . cr_x($aus) . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_creality2lox_steuern.xml', $o);
}

/* ---------------------------------------------------------------------------------------------------------------
 * Befehle - EINE Funktion fuer HTTP-Endpunkt und Befehlsbruecke. NICHT AM GERAET ERPROBT.
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * Die Befehle: name => art (puls/schalter), Freigabe-Schluessel, Moonraker-Weg, erlaubte Statuszahlen (null = jeder),
 * eingreifend. Wege aus der Moonraker-Dokumentation (Printer Administration / Print Management); am Geraet NICHT
 * ausgeloest (Auftrag des Hausherrn 12.11.2026: nur lesend messen).
 */
function cr_befehle()
{
    return array(
        'pause'      => array('art' => 'puls', 'freigabe' => 'freigabe_pause', 'weg' => '/printer/print/pause',
                              'status' => array(1), 'eingreifend' => true),
        'fortsetzen' => array('art' => 'puls', 'freigabe' => 'freigabe_pause', 'weg' => '/printer/print/resume',
                              'status' => array(2), 'eingreifend' => true),
        'abbrechen'  => array('art' => 'puls', 'freigabe' => 'freigabe_abbruch', 'weg' => '/printer/print/cancel',
                              'status' => array(1, 2), 'eingreifend' => true),
        'notaus'     => array('art' => 'puls', 'freigabe' => 'freigabe_notaus', 'weg' => '/printer/emergency_stop',
                              'status' => null, 'eingreifend' => true),
        /* Licht: SET_PIN PIN=LED VALUE=0|1 ueber /printer/gcode/script. Der Drucker ueberschreibt SET_PIN mit einem
         * eigenen Makro (gemessen in configfile: gcode_macro set_pin, rename_existing SET_PIN_BASE, Sonderfall nur
         * fuer fan0); [output_pin LED] ist pwm, scale 1.0. Ob das Licht damit schaltet: nicht gemessen. */
        'licht'      => array('art' => 'schalter', 'freigabe' => 'freigabe_licht', 'weg' => '/printer/gcode/script',
                              'status' => null, 'eingreifend' => false),
    );
}

/** Die Bremse: gleicher Befehl mit gleichem Wert binnen CR_BEFEHL_BREMSE Sekunden -> abgewiesen. Unter flock. */
function cr_bremse($schluessel)
{
    $f = cr_tmpdir() . '/befehl_letzt.json';
    $fp = @fopen($f, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) { return 'MERKER'; }
    $roh = stream_get_contents($fp);
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { $d = array(); }
    $jetzt = time();
    $urteil = (isset($d[$schluessel]) && $jetzt - (int) $d[$schluessel] >= 0 && $jetzt - (int) $d[$schluessel] < CR_BEFEHL_BREMSE) ? 'GESPERRT' : 'FREI';
    if ($urteil === 'FREI') {
        foreach ($d as $k => $t) { if ($jetzt - (int) $t > 3600) { unset($d[$k]); } }
        $d[$schluessel] = $jetzt;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, (string) json_encode($d));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $urteil;
}

/**
 * Einen Befehl pruefen und ausfuehren. Quelle 'http' (Endpunkt, Token schon geprueft) oder 'mqtt' (Befehlsbruecke,
 * Haken schon geprueft). Pruefreihenfolge: Name -> Wert -> Weg eingeschaltet -> Freigabe der Stufe -> Adresse ->
 * Bremse -> frischer Zustand und Vorbedingung -> Senden -> Wirkung nachsehen. Rueckgabe array(http, zeile, ok).
 */
function cr_befehl_ausfuehren($cmd, $wert, $quelle = 'http')
{
    $raus = function ($http, $zeile) {
        return array('http' => (int) $http, 'zeile' => $zeile . "\n",
                     'ok' => ((int) $http === 200 && strpos($zeile, ';OK=1') !== false) ? 1 : 0);
    };
    $cmd = is_string($cmd) ? $cmd : '';
    $alle = cr_befehle();
    if (!preg_match('/^[a-z]{1,20}\z/', $cmd) || !isset($alle[$cmd])) { return $raus(400, 'CMD;OK=0;GRUND=BEFEHL_UNBEKANNT'); }
    $b = $alle[$cmd];
    $wert = is_string($wert) ? $wert : (is_int($wert) ? (string) $wert : '');
    if ($b['art'] === 'schalter') {
        if ($wert !== '0' && $wert !== '1') { return $raus(400, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=WERT'); }
    } elseif ($wert !== '' && $wert !== '1') {
        return $raus(400, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=WERT');
    }
    $cfg = cr_config(false);
    if ($quelle === 'http' && empty($cfg['befehle_http_ein'])) { return $raus(403, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=HTTP_AUS'); }
    if (empty($cfg[$b['freigabe']])) { return $raus(403, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=FREIGABE_AUS'); }
    if ((string) $cfg['drucker_host'] === '') { return $raus(503, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=KEINE_ADRESSE'); }
    $bremse = cr_bremse($cmd . '|' . $wert);
    if ($bremse === 'MERKER') { return $raus(503, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=BREMSE_MERKER'); }
    if ($bremse === 'GESPERRT') { return $raus(429, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=BREMSE'); }
    list($ok, $status) = cr_abfragen(4);
    if (!$ok) { return $raus(503, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=DRUCKER_NICHT_ERREICHBAR'); }
    $vor = cr_zustand_bilden($status, time());
    if ($b['status'] !== null && !in_array($vor['status'], $b['status'], true)) {
        return $raus(409, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=ZUSTAND;STATUS=' . (int) $vor['status']);
    }
    $weg = $b['weg'];
    if ($cmd === 'licht') { $weg .= '?script=' . rawurlencode('SET_PIN PIN=LED VALUE=' . $wert); }
    list($code, $rumpf, $fehler) = cr_http('POST', $weg, 6);
    $j = json_decode((string) $rumpf, true);
    $angenommen = ($fehler === '' && $code === 200 && is_array($j) && isset($j['result']));
    cr_log('Befehl ' . $cmd . ($wert !== '' ? '=' . $wert : '') . ' (' . ($quelle === 'mqtt' ? 'MQTT' : ($quelle === 'http' ? 'HTTP' : 'Oberflaeche')) . '): '
        . ($angenommen ? 'von Moonraker angenommen' : 'abgewiesen, HTTP ' . (int) $code . ($fehler !== '' ? ' ' . $fehler : '')));
    if (!$angenommen) {
        return $raus(502, 'CMD;OK=0;BEFEHL=' . $cmd . ';GRUND=MOONRAKER;HTTP=' . (int) $code);
    }
    $gesehen = cr_befehl_wirkung($cmd, $wert);
    return $raus(200, 'CMD;OK=1;BEFEHL=' . $cmd . ';WIRKUNG=' . $gesehen);
}

/**
 * Nach einem angenommenen Befehl nachsehen, ob die Wirkung eintritt (hoechstens CR_WIRKUNG_WARTEN s). 1 gesehen,
 * 0 in der Frist nicht gesehen (keine Fehlmeldung - eine Pause kann laenger dauern), -1 nicht feststellbar.
 */
function cr_befehl_wirkung($cmd, $wert)
{
    $ende = microtime(true) + CR_WIRKUNG_WARTEN;
    $lesbar = false;
    do {
        list($ok, $status) = cr_abfragen(3);
        if ($ok) {
            $lesbar = true;
            $s = cr_zustand_bilden($status, time());
            if ($cmd === 'pause' && $s['status'] === 2) { return 1; }
            if ($cmd === 'fortsetzen' && $s['status'] === 1) { return 1; }
            if ($cmd === 'abbrechen' && in_array($s['status'], array(0, 4), true)) { return 1; }
            if ($cmd === 'notaus' && $s['klipper'] === 3) { return 1; }
            if ($cmd === 'licht' && $s['licht'] !== null && (string) $s['licht'] === $wert) { return 1; }
        }
        usleep(1000000);
    } while (microtime(true) < $ende);
    return $lesbar ? 0 : -1;
}

/** Quittung fuer die Befehlsbruecke: array(ok, grund). */
function cr_befehl_quittung(array $e)
{
    if (!empty($e['ok'])) { return array(true, ''); }
    $z = trim((string) (isset($e['zeile']) ? $e['zeile'] : ''));
    return preg_match('/;GRUND=([A-Z_]+)/', $z, $m) ? array(false, $m[1]) : array(false, 'UNBEKANNT');
}

/* ---------------------------------------------------------------------------------------------------------------
 * Befehlsbruecke (gemeinsames Modul bin/befehlsbruecke.php, byte-gleich mit Werkzeuge/gemeinsam/)
 * ------------------------------------------------------------------------------------------------------------- */

/** Der bin-Ordner mit der Bruecke: installiert <wurzel>/bin/plugins/<ordner>, im Archiv bin/. '' = keiner. */
function cr_bin_dir()
{
    $p = cr_paths();
    if ($p['lbhome'] !== '') {
        $d = $p['lbhome'] . '/bin/plugins/' . $p['plugin'];
        if (is_file($d . '/befehlsbruecke.php')) { return $d; }
    }
    $d = dirname(dirname(__DIR__)) . '/bin';
    return is_file($d . '/befehlsbruecke.php') ? $d : '';
}

function cr_befehle_tabelle()
{
    $bin = cr_bin_dir();
    if ($bin === '') { return array(null, 'bin/befehlsbruecke.php fehlt'); }
    if (!function_exists('bb_zuordnung_laden')) {
        if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
        require_once $bin . '/befehlsbruecke.php';
    }
    list($z, $fehler) = bb_zuordnung_laden($bin . '/befehle_zuordnung.php');
    if ($z === null) { return array(null, $fehler); }
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) { return array(null, $fehl[0]); }
    return array($z, '');
}

/** Nach dem Speichern des Reiters MQTT: die Bruecke starten oder anhalten, wie die Haken es sagen. */
function cr_bruecke_schalten()
{
    list($z, $fehler) = cr_befehle_tabelle();
    if ($z === null) { return array(1, $fehler, ''); }
    $p = bb_pfade();
    if (!$p) { return array(1, 'keine LoxBerry-Installation erkannt', ''); }
    $e = bb_einstellungen($z);
    if (is_array($e) && $e['annehmen']) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, $aus, 'start');
    }
    if (bb_dienste()) {
        list($rc, $aus) = bb_sh($p, 'stop');
        return array($rc, $aus, 'stop');
    }
    return array(0, '', '');
}

function cr_befehle_liste()
{
    list($z, $fehler) = cr_befehle_tabelle();
    if ($z === null) { return array(null, $fehler); }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Vorlage der MQTT-Befehle (VirtualOut an den UDP-Eingang des Gateways) aus der Tabelle der Bruecke. */
function cr_vorlage_mqtt()
{
    list($l) = cr_befehle_liste();
    if ($l === null) { return null; }
    $port = cr_mqtt_udpport();
    return array('VQ_creality2lox_mqtt.xml', bb_vorlage($l, cr_host(), $port > 0 ? $port : 11884));
}

/** Zustand der Bruecke fuer Reiter MQTT, Test und Healthcheck: array(1 laeuft | 2 aus | 0 Stoerung, Text). */
function cr_bruecke_zustand()
{
    list($z, $fehler) = cr_befehle_tabelle();
    if ($z === null) { return array(0, sprintf(cr_t('BEFEHL.Z_DEFEKT'), $fehler)); }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) { return array(2, cr_t('BEFEHL.Z_AUS')); }
    if ($soll !== 0) { return array(0, sprintf(cr_t('BEFEHL.Z_DEFEKT'), $grund)); }
    $zu = bb_zustand_lesen();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : -1;
    if (!bb_dienste() || $alter < 0 || $alter > BB_WACHE_GRENZE || empty($zu['abo'])) {
        return array(0, sprintf(cr_t('BEFEHL.Z_STEHT'), (is_array($zu) && !empty($zu['fehler'])) ? ' (' . $zu['fehler'] . ')' : ''));
    }
    $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
    return array(1, sprintf(cr_t('BEFEHL.Z_LAEUFT'), date('d.m.Y H:i', (int) (isset($zu['start']) ? $zu['start'] : 0)),
        (int) (isset($zl['angenommen']) ? $zl['angenommen'] : 0), (int) (isset($zl['abgelehnt']) ? $zl['abgelehnt'] : 0)));
}

/* ---------------------------------------------------------------------------------------------------------------
 * Gesundheit (bin/healthcheck) und Selbstpruefung (Reiter Test)
 * ------------------------------------------------------------------------------------------------------------- */

/** Grenze, ab der der Minutenlauf als stehend gilt: deutlich ueber dem Takt (drei Minuten plus Takt). */
function cr_lauf_grenze()
{
    $c = cr_config(false);
    return 180 + (int) $c['abfragetakt'];
}

/** Healthcheck: nur aus Dateien, ohne Netz, ohne etwas anzulegen. Rueckgabe array(Status 3/4/5/6, Text). */
function cr_gesundheit()
{
    $cfg = cr_config(false);
    $cz = cr_cfg_zustand();
    $lage = cr_cfg_lage();
    if ($cz === 'kaputt' || $lage['fremd']) { return array(3, cr_t('HEALTH.KONFIG')); }
    if ((string) $cfg['drucker_host'] === '') { return array(6, cr_t('HEALTH.KEINE_ADRESSE')); }
    $alter = cr_lauf_alter();
    if ($alter < 0) { return array(6, cr_t('HEALTH.NOCH_KEIN_LAUF')); }
    if ($alter > cr_lauf_grenze()) { return array(3, sprintf(cr_t('HEALTH.LAUF_STEHT'), (int) round($alter / 60))); }
    $lauf = cr_lauf_lesen();
    if (!$lauf['ok']) { return array(6, sprintf(cr_t('HEALTH.NICHT_ERREICHBAR'), $lauf['grund'])); }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_enabled'])) {
        list($bz) = cr_bruecke_zustand();
        if ($bz === 0) { return array(4, cr_t('HEALTH.BRUECKE')); }
    }
    $st = cr_zustand_lesen();
    if (is_array($st) && !empty($st['fehler'])) { return array(4, cr_t('HEALTH.DRUCKERFEHLER')); }
    return array(5, sprintf(cr_t('HEALTH.OK'), $alter));
}

/** Die Oberflaechendatei fuer die Pruefzeilen (installiert im htmlauth-Baum, sonst im Archiv daneben). */
function cr_oberflaeche_datei()
{
    $p = cr_paths();
    if ($p['lbhome'] !== '') {
        $f = $p['lbhome'] . '/webfrontend/htmlauth/plugins/' . $p['plugin'] . '/index.php';
        if (is_file($f)) { return $f; }
    }
    $f = dirname(__DIR__) . '/htmlauth/index.php';
    return is_file($f) ? $f : '';
}

/**
 * Reiterliste, Leiste und Bereiche gegeneinander - aus der eigenen Datei gelesen. Rueckgabe array(1|0|2, Text).
 * Gezaehlt wird die Zahl der angesehenen Stellen; null Stellen ist kein "in Ordnung".
 */
function cr_reiterlage()
{
    $f = cr_oberflaeche_datei();
    $s = $f !== '' ? (string) @file_get_contents($f) : '';
    if ($s === '') { return array(2, cr_t('PRUEF.DATEI_FEHLT')); }
    $liste = array();
    if (preg_match('/\$cr_reiterliste\s*=\s*array\(([^)]*)\)/', $s, $m)) {
        preg_match_all("/'(tab-[a-z]+)'/", $m[1], $x);
        $liste = $x[1];
    }
    preg_match_all('/data-ziel="(tab-[a-z]+)"/', $s, $l);
    preg_match_all('/class="sm-seite<\?= \$cr_tab === \'(tab-[a-z]+)\' \? \' sm-active\' : \'\' \?>" id="(tab-[a-z]+)"/', $s, $b);
    $leiste = $l[1];
    $bereiche = $b[2];
    $gleich_ziel = ($b[1] === $b[2]);
    if (!$liste || !$leiste || !$bereiche) {
        return array(0, sprintf(cr_t('PRUEF.REITER_LEER'), count($liste), count($leiste), count($bereiche)));
    }
    if ($liste === $leiste && $leiste === $bereiche && $gleich_ziel) {
        return array(1, sprintf(cr_t('PRUEF.REITER_OK'), count($liste)));
    }
    return array(0, sprintf(cr_t('PRUEF.REITER_ABW'), implode(',', $liste), implode(',', $leiste), implode(',', $bereiche)));
}

/** Setzt der Server sm-active an Leiste UND Bereichen (sonst ist die Seite ohne JavaScript leer)? Vorlage 20.08.2026. */
function cr_smactive_probe()
{
    $f = cr_oberflaeche_datei();
    $s = $f !== '' ? (string) @file_get_contents($f) : '';
    if ($s === '') { return array(2, cr_t('PRUEF.DATEI_FEHLT')); }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $s, $y);
    $anzahl = count($y[1]);
    $leiste = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    if ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl) { return array(1, sprintf(cr_t('PRUEF.SMACTIVE_OK'), $anzahl)); }
    return array(0, sprintf(cr_t('PRUEF.SMACTIVE_NEIN'), $leiste, $bereiche, $anzahl));
}

/** Tragen alle Formulare das Merkmal und ein formular=-Feld? Gezaehlt in der eigenen Datei. */
function cr_formularlage()
{
    $f = cr_oberflaeche_datei();
    $s = $f !== '' ? (string) @file_get_contents($f) : '';
    if ($s === '') { return array(2, cr_t('PRUEF.DATEI_FEHLT')); }
    $teile = preg_split('/<form\b/', $s);
    $formulare = count($teile) - 1;
    if ($formulare <= 0) { return array(0, sprintf(cr_t('PRUEF.FORM_LEER'), 0)); }
    $ohne = 0;
    for ($i = 1; $i < count($teile); $i++) {
        $rumpf = (string) strstr($teile[$i], '</form>', true);
        if (strpos($rumpf, 'cw_fmt()') === false || strpos($rumpf, 'name="formular"') === false) { $ohne++; }
    }
    return $ohne === 0 ? array(1, sprintf(cr_t('PRUEF.FORM_OK'), $formulare))
                       : array(0, sprintf(cr_t('PRUEF.FORM_FEHLT'), $ohne, $formulare));
}

/** Ist der Cron-Eintrag an einem der Cron-Orte da - als Datei? */
function cr_cron_lage()
{
    $p = cr_paths();
    if ($p['lbhome'] === '') { return array(2, cr_t('PRUEF.OHNE_WURZEL')); }
    $gefunden = array(); $verzeichnis = array();
    foreach (array('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min', 'cron.15min', 'cron.30min', 'cron.hourly') as $o) {
        $c = $p['lbhome'] . '/system/cron/' . $o . '/' . $p['plugin'];
        if (is_file($c)) { $gefunden[] = $o; } elseif (is_dir($c)) { $verzeichnis[] = $o; }
    }
    if ($verzeichnis) { return array(0, sprintf(cr_t('PRUEF.CRON_VERZ'), implode(', ', $verzeichnis))); }
    if ($gefunden === array('cron.01min')) { return array(1, cr_t('PRUEF.CRON_OK')); }
    if (!$gefunden) { return array(0, cr_t('PRUEF.CRON_FEHLT')); }
    return array(0, sprintf(cr_t('PRUEF.CRON_MEHRFACH'), implode(', ', $gefunden)));
}

/** Den eigenen Endpunkt wirklich aufrufen (127.0.0.1, Selbsttest mit Token) - drei Ausgaenge. */
function cr_endpunkt_probe($token)
{
    if ($token === '') { return array(0, cr_t('PRUEF.EP_KEIN_TOKEN')); }
    if (!function_exists('curl_init')) { return array(2, cr_t('PRUEF.EP_NICHT_FESTSTELLBAR')); }
    $ch = curl_init('http://127.0.0.1' . cr_endpunkt_pfad(array('selftest' => 1, 'token' => $token)));
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3,
                                 CURLOPT_FOLLOWLOCATION => false));
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    if ($r === false || $code === 0) { return array(2, cr_t('PRUEF.EP_KEINE_ANTWORT')); }
    if ($code === 200 && strpos((string) $r, 'SELFTEST;OK=1') === 0) { return array(1, sprintf(cr_t('PRUEF.EP_OK'), trim((string) $r))); }
    return array(0, sprintf(cr_t('PRUEF.EP_FEHLER'), $code, substr(preg_replace('/[^\x20-\x7E]/', '?', (string) $r), 0, 80)));
}

/** Stimmt die Themenliste mit dem Sendecode ueberein? Gegen einen voll belegten Beispielzustand. */
function cr_themenlage()
{
    $praefix = cr_mqtt_thema_saeubern(cr_config(false)['mqtt_topic']);
    $tabelle = array();
    foreach (cr_mqtt_themen($praefix) as $t => $x) {
        if ($x['feld'] !== 'OK' && $x['feld'] !== 'ZAEHLER' && $x['feld'] !== '') { $tabelle[] = $t; }
    }
    $sender = array();
    foreach (array_keys(cr_mqtt_werte(cr_beispielzustand())) as $t) { $sender[] = $praefix . '/' . $t; }
    if (!$tabelle || !$sender) { return array(0, sprintf(cr_t('PRUEF.THEMEN_LEER'), count($tabelle), count($sender))); }
    $nur_t = array_diff($tabelle, $sender);
    $nur_s = array_diff($sender, $tabelle);
    if (!$nur_t && !$nur_s) { return array(1, sprintf(cr_t('PRUEF.THEMEN_OK'), count($sender))); }
    return array(0, sprintf(cr_t('PRUEF.THEMEN_ABW'), implode(', ', array_slice($nur_t, 0, 5)), implode(', ', array_slice($nur_s, 0, 5))));
}

/** Ist jedes Suchmuster in der Antwortzeile eindeutig (genau ein Treffer von ";NAME=")? */
function cr_suchmusterlage()
{
    $zeile = cr_zeile(cr_beispielzustand());
    $namen = array();
    foreach (cr_felder() as $n => $f) { if ($f[8] === 1) { $namen[] = $n; } }
    if (!$namen) { return array(0, sprintf(cr_t('PRUEF.MUSTER_LEER'), 0)); }
    $mehr = array();
    foreach ($namen as $n) {
        if (substr_count($zeile, ';' . $n . '=') !== 1) { $mehr[] = $n; }
        if (strpos(cr_check($n), '\i;') !== 0) { $mehr[] = $n . ' (ohne Semikolon)'; }
    }
    return $mehr ? array(0, sprintf(cr_t('PRUEF.MUSTER_ABW'), implode(', ', $mehr)))
                 : array(1, sprintf(cr_t('PRUEF.MUSTER_OK'), count($namen)));
}

/** Sind die erzeugbaren Vorlagen wohlgeformt und gueltiges UTF-8 (simplexml)? */
function cr_vorlagenlage()
{
    if (!function_exists('simplexml_load_string')) { return array(2, cr_t('PRUEF.XML_OHNE')); }
    $dateien = array(cr_vorlage_vi(), cr_vorlage_vq());
    $mq = cr_vorlage_mqtt();
    if ($mq !== null) { $dateien[] = $mq; }
    $schlecht = array();
    $alt = libxml_use_internal_errors(true);
    foreach ($dateien as $d) {
        if (!preg_match('//u', $d[1]) || @simplexml_load_string($d[1]) === false || strpos($d[1], "\r\n") === false) { $schlecht[] = $d[0]; }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    return $schlecht ? array(0, sprintf(cr_t('PRUEF.XML_FEHLER'), implode(', ', $schlecht)))
                     : array(1, sprintf(cr_t('PRUEF.XML_OK'), count($dateien)));
}

/** Haben alle Zustaende und Gruende einen Text in der Sprachdatei? */
function cr_sprachlage()
{
    $schl = array('STATUS.SX', 'STATUS.S0', 'STATUS.S1', 'STATUS.S2', 'STATUS.S3', 'STATUS.S4', 'STATUS.S5',
                  'GRUND.KEINE_ADRESSE', 'GRUND.NICHT_ERREICHBAR', 'GRUND.ANTWORT_UNGUELTIG', 'GRUND.HTTP');
    foreach (cr_felder() as $f) { $schl[] = 'FELD.' . $f[4]; $schl[] = 'KACHEL.' . $f[6]; }
    foreach (array_keys(cr_befehle()) as $b) { $schl[] = 'BEFEHL.K_' . strtoupper($b); }
    $schl = array_values(array_unique($schl));
    $fehlt = array();
    foreach ($schl as $k) { if (cr_t($k) === $k) { $fehlt[] = $k; } }
    return $fehlt ? array(0, sprintf(cr_t('PRUEF.SPRACHE_FEHLT'), count($fehlt), implode(', ', array_slice($fehlt, 0, 6))))
                  : array(1, sprintf(cr_t('PRUEF.SPRACHE_OK'), count($schl)));
}

/** Grund eines Ausfalls als Text. */
function cr_grund_text($grund)
{
    if (strpos((string) $grund, 'HTTP_') === 0) { return sprintf(cr_t('GRUND.HTTP'), substr((string) $grund, 5)); }
    $k = 'GRUND.' . preg_replace('/[^A-Z_]/', '', (string) $grund);
    $t = cr_t($k);
    return $t === $k ? (string) $grund : $t;
}

/**
 * Die Selbstpruefung: je Zeile array(ok 1|0|2, Bezeichnungsschluessel, Text). 2 = nicht feststellbar (Strich).
 * Netzpruefungen (eigener Endpunkt) nur mit $opt['test_offen'].
 */
function cr_selbsttest($opt = array())
{
    $z = array();
    $add = function ($ok, $bez, $text) use (&$z) { $z[] = array('ok' => (int) $ok, 'bez' => $bez, 'text' => (string) $text); };
    $cfg = cr_config();
    $cz = cr_cfg_zustand();
    $add($cz === 'ok' ? 1 : ($cz === 'fehlt' || $cz === 'leer' ? 2 : 0), 'TEST.Z_KONFIG', cr_t('CFGZ.' . str_replace(' ', '_', $cz)));
    $lage = cr_cfg_lage();
    $add($lage['fehlt'] || $lage['fremd'] ? 0 : 1, 'TEST.Z_VOLLSTAENDIG', $lage['fehlt'] || $lage['fremd']
        ? sprintf(cr_t('PRUEF.CFG_LUECKE'), $lage['da'], $lage['soll'], implode(', ', $lage['fehlt']), implode(', ', $lage['fremd']))
        : sprintf(cr_t('PRUEF.CFG_OK'), $lage['da'], $lage['soll']));
    $p = cr_paths();
    $add(is_file($p['backup']) ? 1 : 0, 'TEST.Z_ZWEITSCHRIFT', is_file($p['backup']) ? cr_t('PRUEF.ZWEIT_OK') : cr_t('PRUEF.ZWEIT_FEHLT'));
    $add((string) $cfg['drucker_host'] !== '' ? 1 : 0, 'TEST.Z_ADRESSE', (string) $cfg['drucker_host'] !== ''
        ? sprintf(cr_t('PRUEF.ADRESSE_OK'), $cfg['drucker_host'], (int) $cfg['drucker_port']) : cr_t('PRUEF.ADRESSE_FEHLT'));
    list($co, $ct) = cr_cron_lage();
    $add($co, 'TEST.Z_CRON', $ct);
    $alter = cr_lauf_alter();
    $lauf = cr_lauf_lesen();
    if ((string) $cfg['drucker_host'] === '') {
        $add(2, 'TEST.Z_LAUF', cr_t('PRUEF.LAUF_OHNE_ADRESSE'));
    } elseif ($alter < 0) {
        $add(2, 'TEST.Z_LAUF', cr_t('PRUEF.LAUF_NOCH_NIE'));
    } else {
        $add($alter <= cr_lauf_grenze() ? 1 : 0, 'TEST.Z_LAUF', sprintf(cr_t('PRUEF.LAUF_ALTER'), $alter, (int) $lauf['zaehler'], cr_lauf_grenze()));
        $st = cr_zustand_lesen();
        $add($lauf['ok'] ? 1 : 0, 'TEST.Z_DRUCKER', $lauf['ok']
            ? sprintf(cr_t('PRUEF.DRUCKER_OK'), $st !== null ? cr_status_text($st['status']) : '-', (int) $lauf['dauer_ms'])
            : sprintf(cr_t('PRUEF.DRUCKER_WEG'), cr_grund_text($lauf['grund'])));
    }
    list($ro, $rt) = cr_reiterlage();
    $add($ro, 'TEST.Z_REITER', $rt);
    list($sa, $sb) = cr_smactive_probe();
    $add($sa, 'TEST.Z_SMACTIVE', $sb);
    list($fo, $ft) = cr_formularlage();
    $add($fo, 'TEST.Z_FORMULARE', $ft);
    list($to, $tt) = cr_themenlage();
    $add($to, 'TEST.Z_THEMEN', $tt);
    list($mo, $mt) = cr_suchmusterlage();
    $add($mo, 'TEST.Z_MUSTER', $mt);
    list($xo, $xt) = cr_vorlagenlage();
    $add($xo, 'TEST.Z_VORLAGEN', $xt);
    list($so, $sx) = cr_sprachlage();
    $add($so, 'TEST.Z_SPRACHE', $sx);
    $g = cr_mqtt_gateway_info();
    if (empty($cfg['mqtt_enabled'])) {
        $add(2, 'TEST.Z_MQTT', cr_t('PRUEF.MQTT_AUS'));
    } elseif ($g === null) {
        $add(2, 'TEST.Z_MQTT', cr_t('PRUEF.GW_UNKLAR'));
    } else {
        $udp = cr_mqtt_udpport();
        $add($g['autostart'] && $udp > 0 ? 1 : 0, 'TEST.Z_MQTT', sprintf(cr_t('PRUEF.GW_LAGE'), $g['fassung'],
            cr_t($g['autostart'] ? 'WORT.JA' : 'WORT.NEIN'), $udp));
        if ($g['fassung'] < 2) {
            list($ap, $ada) = cr_abo_datei(cr_mqtt_thema_saeubern($cfg['mqtt_topic']));
            $add($ada ? 1 : 0, 'TEST.Z_ABO', $ada ? sprintf(cr_t('PRUEF.ABO_OK'), cr_mqtt_thema_saeubern($cfg['mqtt_topic']) . '/#')
                : sprintf(cr_t('PRUEF.ABO_FEHLT'), cr_mqtt_thema_saeubern($cfg['mqtt_topic']) . '/#'));
        }
    }
    if (!empty($cfg['befehle_mqtt_ein'])) {
        list($bz, $bt) = cr_bruecke_zustand();
        $add($bz === 1 ? 1 : 0, 'TEST.Z_BRUECKE', $bt);
    } else {
        $add(2, 'TEST.Z_BRUECKE', cr_t('BEFEHL.Z_AUS'));
    }
    if (!empty($opt['test_offen'])) {
        list($eo, $et) = cr_endpunkt_probe((string) $cfg['aktionstoken']);
        $add($eo, 'TEST.Z_ENDPUNKT', $et);
    } else {
        $add(2, 'TEST.Z_ENDPUNKT', cr_t('PRUEF.EP_NUR_OFFEN'));
    }
    return $z;
}
