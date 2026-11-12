<?php
/**
 * Creality2Lox - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 * Kompatibel mit PHP 7.4, 8.4 und 8.5 (LoxBerry 3.x/4.x). Bauvorbild: Saugroboter-Valetudo 1.1.37.
 *
 * LBWeb::lbheader() setzt SDK-Globals (u.a. $cfg) - daher tragen hier ALLE Variablen das Kuerzel cr_ und alle
 * Funktionen dieser Datei das Kuerzel cw_ (die Bibliothek fuehrt cr_).
 *
 * DIE REIHENFOLGE IN DIESER DATEI IST BAUVORSCHRIFT (Regeln/03 Abschnitt 3):
 *   1. Bibliothek laden   2. Konfiguration, Token   3. WACHPOSTEN   4. Reiterwahl
 *   5. Handler - darunter JEDER Download, der mit exit endet, und jede Umleitung (PRG)
 *   6. ERST JETZT LBWeb::lbheader()   7. HTML
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* ---------- 1. Bibliothek: installiert liegen html/ und htmlauth/ in getrennten Baeumen ---------- */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $cr_gefunden = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/cr_lib.php';
} else {
    $cr_gefunden = dirname(__DIR__) . '/html/cr_lib.php';   // ausgepacktes Archiv
}
if (!is_file($cr_gefunden)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Creality2Lox: cr_lib.php nicht gefunden.\nGesucht in:\n  " . $cr_gefunden . "\n";
    exit;
}
require_once $cr_gefunden;

/* ---------- 2. Konfiguration ---------- */
$cr_p = cr_paths();
if ($cr_p['lbhome'] !== '' && is_file($cr_p['lbhome'] . '/libs/phplib/loxberry_system.php')) {
    require_once $cr_p['lbhome'] . '/libs/phplib/loxberry_system.php';
    require_once $cr_p['lbhome'] . '/libs/phplib/loxberry_web.php';
}

$cr_cfg = cr_config();
/* Das Aktionstoken entsteht EINMAL: fehlt der Schluessel in der Datei (array_key_exists, nicht empty), wird es
 * gewuerfelt. Steht er leer darin, ist das kein Neuanfang, sondern ein Schaden - dann wird ebenfalls gewuerfelt, aber
 * die Seite sagt, dass die Adressen in Loxone nicht mehr passen. */
$cr_token_meldung = '';
$cr_roh = is_file($cr_p['config']) ? json_decode((string) @file_get_contents($cr_p['config']), true) : null;
$cr_roh_hat = is_array($cr_roh) && array_key_exists('aktionstoken', $cr_roh);
if (!is_string($cr_cfg['aktionstoken']) || $cr_cfg['aktionstoken'] === '') {
    $cr_cfg['aktionstoken'] = cr_token_erzeugen();
    if (cr_config_speichern($cr_cfg)) {
        if ($cr_roh_hat) { $cr_token_meldung = cr_t('TEXT.TOKEN_WAR_LEER'); }
    } else {
        $cr_token_meldung = cr_t('TEXT.TOKEN_NICHT_GESPEICHERT');
    }
    $cr_cfg = cr_config();
}
$cr_fmt = cr_formtoken($cr_cfg);
$cr_meldungen = array();
$cr_fehler = array();

/* ---------- PRG: jede Handlung endet mit einer Umleitung; das Ergebnis reist als Einmalmeldung (0600, 2 min) ---------- */
function cw_flash_datei() { return cr_datadir() . '/einmalmeldung.json'; }
function cw_umleiten($tab, array $inhalt)
{
    $inhalt['tab'] = $tab;
    $inhalt['zeit'] = time();
    $js = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($js === false || !cr_write_atomic(cw_flash_datei(), $js, 0600)) {
        cr_log('Die Einmalmeldung liess sich nicht schreiben - nach der Umleitung fehlt die Rueckmeldung.');
    }
    header('Location: index.php?form=' . rawurlencode((string) preg_replace('/^tab-/', '', $tab)), true, 303);
    exit;
}
function cw_flash_lesen()
{
    $f = cw_flash_datei();
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || time() - (int) $d['zeit'] > 120) { return array(); }
    return $d;
}

/* ---------- Eingaben nach einer Beanstandung (Hausregel 30.09.2026): nur die Felder EINES Formulars ---------- */
function cw_eingabe_felder($formular)
{
    if ($formular === 'drucker') { return array('drucker_host', 'drucker_port', 'abfragetakt'); }
    if ($formular === 'mqtt') { return array('mqtt_topic'); }
    return array();
}
function cw_eingaben($setzen = null)
{
    static $e = null;
    if ($setzen !== null) {
        $e = null;
        if (is_array($setzen) && isset($setzen['formular'], $setzen['werte'], $setzen['falsch']) && is_string($setzen['formular'])
            && is_array($setzen['werte']) && is_array($setzen['falsch'])) {
            $erlaubt = cw_eingabe_felder($setzen['formular']);
            $w = array();
            foreach ($setzen['werte'] as $k => $v) {
                if (in_array((string) $k, $erlaubt, true) && is_string($v) && strlen($v) <= 300 && preg_match('//u', $v)) { $w[(string) $k] = $v; }
            }
            $f = array();
            foreach ($setzen['falsch'] as $n) { if (is_string($n) && in_array($n, $erlaubt, true)) { $f[] = $n; } }
            if ($erlaubt) { $e = array('formular' => $setzen['formular'], 'werte' => $w, 'falsch' => $f); }
        }
    }
    return $e;
}
/** Wert eines Textfelds: die abgewiesene Eingabe, sonst der gespeicherte Wert. */
function cw_w($feld, $gespeichert)
{
    $e = cw_eingaben();
    return ($e !== null && isset($e['werte'][$feld])) ? $e['werte'][$feld] : (string) $gespeichert;
}
/** Markierung eines beanstandeten Felds. */
function cw_m($feld)
{
    $e = cw_eingaben();
    return ($e !== null && in_array($feld, $e['falsch'], true)) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
function cw_post($name)
{
    return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
}

/* ---------- 3. Wachposten gegen fremde Absender: das Merkmal fehlt -> $_POST bis auf den Reiter leeren ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($cr_fmt === '') {
        $cr_fehler[] = cr_e(cr_t('FEHLER.CSRF_KEIN_TOKEN'));
    } elseif (!cr_formtoken_ok($cr_cfg)) {
        $cr_fehler[] = cr_e(cr_t('FEHLER.CSRF'));
        cr_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if ($cr_fehler) {
        $cr_behalten = isset($_POST['activetab']) && is_string($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($cr_behalten !== null) { $_POST['activetab'] = $cr_behalten; }
    }
}

/* ---------- 4. Reiterwahl. Diese Liste, die Leiste und die id der Bereiche muessen deckungsgleich sein -
 * cr_reiterlage() im Reiter Test haelt alle drei gegeneinander. ---------- */
$cr_reiterliste = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$cr_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab']) && in_array($_POST['activetab'], $cr_reiterliste, true)) {
    $cr_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form']) && in_array('tab-' . $_GET['form'], $cr_reiterliste, true)) {
    $cr_tab = 'tab-' . $_GET['form'];
}

/* ---------- 5. Handler ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cr_fehler) {
    cw_umleiten($cr_tab, array('fehler' => $cr_fehler));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cr_flash = cw_flash_lesen();
    foreach (array('meldungen' => 'cr_meldungen', 'fehler' => 'cr_fehler') as $cr_fk => $cr_fv) {
        if (isset($cr_flash[$cr_fk]) && is_array($cr_flash[$cr_fk])) {
            foreach ($cr_flash[$cr_fk] as $cr_fm) { if (is_string($cr_fm)) { ${$cr_fv}[] = $cr_fm; } }
        }
    }
    if (isset($cr_flash['tab']) && is_string($cr_flash['tab']) && in_array($cr_flash['tab'], $cr_reiterliste, true)) {
        $cr_tab = $cr_flash['tab'];
    }
    cw_eingaben(isset($cr_flash['eingaben']) ? $cr_flash['eingaben'] : array());
}
if ($cr_token_meldung !== '') { $cr_fehler[] = cr_e($cr_token_meldung); }

$cr_formular = ($_SERVER['REQUEST_METHOD'] === 'POST') ? cw_post('formular') : '';
$cr_formulare = array('drucker', 'befehle', 'mqtt', 'endpunkt', 'sichern', 'zurueck', 'token', 'vorlage_vi', 'vorlage_vq',
                      'vorlage_mqtt', 'abfragen', 'befehl', 'log');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($cr_formular, $cr_formulare, true)) {
    cw_umleiten($cr_tab, array('fehler' => array(cr_e(cr_t('FEHLER.FORMULAR_UNBEKANNT')))));
}

/* --- Downloads: sie enden mit exit und stehen VOR lbheader(). Sie veraendern nichts. --- */
if ($cr_formular === 'vorlage_vi' || $cr_formular === 'vorlage_vq' || $cr_formular === 'vorlage_mqtt') {
    $cr_v = $cr_formular === 'vorlage_vi' ? cr_vorlage_vi() : ($cr_formular === 'vorlage_vq' ? cr_vorlage_vq() : cr_vorlage_mqtt());
    if ($cr_v === null) {
        list(, $cr_vf) = cr_befehle_liste();
        cw_umleiten('tab-loxone', array('fehler' => array(cr_e(sprintf(cr_t('BEFEHL.LISTE_FEHLT'), $cr_vf)))));
    }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $cr_v[0] . '"');
    echo $cr_v[1];
    exit;
}
/* Einstellungen sichern: die VOLLE Konfiguration samt Aktionstoken (ohne ihn waere die Datei wertlos), nie das
 * Formularmerkmal. */
if ($cr_formular === 'sichern') {
    $cr_js = json_encode(cr_sicherung_bauen(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($cr_js !== false) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="creality2lox_einstellungen_' . date('Ymd_His') . '.json"');
        header('Content-Length: ' . strlen($cr_js));
        echo $cr_js;
        exit;
    }
    cw_umleiten('tab-settings', array('fehler' => array(cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER')))));
}
/* Einstellungen zurueckspielen: is_uploaded_file() zuerst, Groessengrenze, ALLE Beanstandungen, halb gueltig aendert
 * nichts; danach die Befehlsbruecke nachziehen und sagen, was mit ihr geschah. */
if ($cr_formular === 'zurueck') {
    if (!isset($_FILES['cr_sicherung']['tmp_name']) || !is_string($_FILES['cr_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['cr_sicherung']['tmp_name'])) {
        $cr_fehler[] = cr_e(cr_t('TEXT.SICH_KEINE_DATEI'));
    } elseif ((int) $_FILES['cr_sicherung']['size'] > 65536) {
        $cr_fehler[] = cr_e(cr_t('TEXT.SICH_ZU_GROSS'));
    } else {
        list($cr_neu, $cr_mangel, $cr_n) = cr_sicherung_lesen((string) @file_get_contents($_FILES['cr_sicherung']['tmp_name']));
        if ($cr_neu === null) {
            $cr_fehler[] = cr_e(cr_t('TEXT.SICH_ABGELEHNT')) . ' ' . implode(' ', $cr_mangel);
        } elseif (cr_config_speichern($cr_neu)) {
            $cr_meldungen[] = cr_e(sprintf(cr_t('TEXT.SICH_UEBERNOMMEN'), $cr_n));
            list($cr_brc, $cr_baus, $cr_bwas) = cr_bruecke_schalten();
            $cr_meldungen[] = cr_e($cr_bwas === '' ? cr_t('BEFEHL.NACH_SICHERUNG_NICHTS')
                : sprintf(cr_t('BEFEHL.NACH_SICHERUNG'), $cr_bwas, $cr_brc === 0 ? cr_t('WORT.JA') : cr_t('WORT.NEIN'), trim((string) $cr_baus)));
        } else {
            $cr_fehler[] = cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER'));
        }
    }
    cw_umleiten('tab-settings', array('meldungen' => $cr_meldungen, 'fehler' => $cr_fehler));
}

/* Drucker: Adresse, Port, Abfragetakt. Jedes Feld einzeln geprueft; gueltige Felder werden gespeichert, ungueltige
 * abgewiesen und gemeldet (alle, nicht die erste), die Eingabe steht danach wieder im Formular. */
if ($cr_formular === 'drucker') {
    $cr_neu = cr_config();
    $cr_falsch = array();
    $cr_werte = array('drucker_host' => cw_post('drucker_host'), 'drucker_port' => cw_post('drucker_port'),
                      'abfragetakt' => cw_post('abfragetakt'));
    foreach ($cr_werte as $cr_k => $cr_w) {
        $cr_g = cr_wert_pruefen($cr_k, $cr_w);
        if ($cr_g !== '') { $cr_fehler[] = cr_e(sprintf(cr_t('FEHLER.FELD'), cr_t('EINST.L_' . strtoupper($cr_k)), $cr_g)); $cr_falsch[] = $cr_k; continue; }
        $cr_neu[$cr_k] = $cr_k === 'drucker_host' ? $cr_w : (int) $cr_w;
    }
    if (cr_config_speichern($cr_neu)) {
        $cr_meldungen[] = cr_e(cr_t($cr_falsch ? 'TEXT.TEIL_GESPEICHERT' : 'TEXT.GESPEICHERT'));
    } else {
        $cr_fehler[] = cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER'));
    }
    cw_umleiten('tab-settings', array('meldungen' => $cr_meldungen, 'fehler' => $cr_fehler,
        'eingaben' => $cr_falsch ? array('formular' => 'drucker', 'werte' => $cr_werte, 'falsch' => $cr_falsch) : array()));
}
/* Befehle: Weg HTTP und die vier Freigabestufen - alle ab Werk aus. */
if ($cr_formular === 'befehle') {
    $cr_neu = cr_config();
    foreach (array('befehle_http_ein', 'freigabe_licht', 'freigabe_pause', 'freigabe_abbruch', 'freigabe_notaus') as $cr_k) {
        $cr_neu[$cr_k] = isset($_POST[$cr_k]) ? 1 : 0;
    }
    $cr_ok = cr_config_speichern($cr_neu);
    cw_umleiten('tab-settings', $cr_ok ? array('meldungen' => array(cr_e(cr_t('TEXT.GESPEICHERT'))))
                                       : array('fehler' => array(cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER')))));
}
/* MQTT: wohnt vollstaendig im Reiter MQTT, eigener Handler. Danach die Befehlsbruecke nach den Haken schalten. */
if ($cr_formular === 'mqtt') {
    $cr_neu = cr_config();
    $cr_falsch = array();
    $cr_thema = cw_post('mqtt_topic');
    $cr_g = cr_wert_pruefen('mqtt_topic', $cr_thema);
    if ($cr_g !== '') { $cr_fehler[] = cr_e(sprintf(cr_t('FEHLER.FELD'), cr_t('MQTT.L_THEMA'), $cr_g)); $cr_falsch[] = 'mqtt_topic'; }
    else { $cr_neu['mqtt_topic'] = $cr_thema; }
    foreach (array('mqtt_enabled', 'befehle_mqtt_ein', 'befehle_mqtt_steuern') as $cr_k) { $cr_neu[$cr_k] = isset($_POST[$cr_k]) ? 1 : 0; }
    if (cr_config_speichern($cr_neu)) {
        $cr_meldungen[] = cr_e(cr_t($cr_falsch ? 'TEXT.TEIL_GESPEICHERT' : 'TEXT.GESPEICHERT'));
        list($cr_brc, $cr_baus, $cr_bwas) = cr_bruecke_schalten();
        if ($cr_bwas !== '') {
            $cr_t = sprintf(cr_t('BEFEHL.GESCHALTET'), $cr_bwas, trim((string) $cr_baus));
            if ($cr_brc === 0) { $cr_meldungen[] = cr_e($cr_t); } else { $cr_fehler[] = cr_e($cr_t); }
        }
    } else {
        $cr_fehler[] = cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER'));
    }
    cw_umleiten('tab-mqtt', array('meldungen' => $cr_meldungen, 'fehler' => $cr_fehler,
        'eingaben' => $cr_falsch ? array('formular' => 'mqtt', 'werte' => array('mqtt_topic' => $cr_thema), 'falsch' => $cr_falsch) : array()));
}
if ($cr_formular === 'endpunkt') {
    $cr_neu = cr_config();
    $cr_neu['endpunkt_ein'] = isset($_POST['endpunkt_ein']) ? 1 : 0;
    $cr_ok = cr_config_speichern($cr_neu);
    cw_umleiten('tab-loxone', $cr_ok ? array('meldungen' => array(cr_e(cr_t('TEXT.GESPEICHERT'))))
                                     : array('fehler' => array(cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER')))));
}
/* Aktionsformulare wirken nur mit ihrem Knopf (token_neu, abruf_jetzt, clearlog): ein Absenden ohne den Knopf
 * - etwa durch ein Werkzeug, das jedes Formular unveraendert abschickt - aendert nichts. */
if ($cr_formular === 'token' && isset($_POST['token_neu'])) {
    $cr_neu = cr_config();
    $cr_neu['aktionstoken'] = cr_token_erzeugen();
    $cr_ok = cr_config_speichern($cr_neu);
    cw_umleiten('tab-loxone', $cr_ok ? array('meldungen' => array(cr_e(cr_t('TEXT.TOKEN_NEU_OK'))))
                                     : array('fehler' => array(cr_e(cr_t('TEXT.SICH_SCHREIBFEHLER')))));
}
/* Jetzt abfragen: EIN Durchgang wie im Minutenlauf, unter derselben Sperre; laeuft der Minutenlauf, sagt es das. */
if ($cr_formular === 'abfragen' && isset($_POST['abruf_jetzt'])) {
    $cr_lk = @fopen(cr_tmpdir() . '/cron.lock', 'c');
    if ($cr_lk === false || !flock($cr_lk, LOCK_EX | LOCK_NB)) {
        cw_umleiten('tab-test', array('fehler' => array(cr_e(cr_t('TEST.LAUF_BELEGT')))));
    }
    list($cr_lauf, $cr_st) = cr_durchgang();
    if ($cr_st !== null && !empty($cr_cfg['mqtt_enabled'])) { cr_mqtt_senden($cr_st); cr_mqtt_lebenszeichen($cr_lauf); }
    flock($cr_lk, LOCK_UN);
    fclose($cr_lk);
    cw_umleiten('tab-test', $cr_lauf['ok'] ? array('meldungen' => array(cr_e(sprintf(cr_t('TEST.ABGEFRAGT'), (int) $cr_lauf['dauer_ms']))))
        : array('fehler' => array(cr_e(sprintf(cr_t('TEST.NICHT_ABGEFRAGT'), cr_grund_text($cr_lauf['grund']))))));
}
/* Befehl aus dem Reiter Test: dieselbe Funktion wie Endpunkt und Bruecke; es gilt die Freigabe der Stufe. */
if ($cr_formular === 'befehl') {
    $cr_e = cr_befehl_ausfuehren(cw_post('cmd'), cw_post('wert'), 'oberflaeche');
    $cr_z = trim($cr_e['zeile']);
    cw_umleiten('tab-test', $cr_e['ok'] ? array('meldungen' => array(cr_e(sprintf(cr_t('TEST.BEFEHL_OK'), $cr_z))))
                                        : array('fehler' => array(cr_e(sprintf(cr_t('TEST.BEFEHL_NEIN'), $cr_e['http'], $cr_z)))));
}
/* Protokoll leeren: nur mit Haken zur Bestaetigung. */
if ($cr_formular === 'log' && isset($_POST['clearlog'])) {
    if (empty($_POST['clearlog_ok'])) {
        cw_umleiten('tab-log', array('fehler' => array(cr_e(cr_t('TEXT.LOG_HAKEN_FEHLT')))));
    }
    $cr_weg = array(); $cr_bleibt = array();
    foreach (array($cr_p['log'], dirname($cr_p['log']) . '/cron.log', dirname($cr_p['log']) . '/befehle_start.log') as $cr_lf) {
        if (!is_file($cr_lf)) { continue; }
        if (@file_put_contents($cr_lf, '') !== false) { $cr_weg[] = basename($cr_lf); } else { $cr_bleibt[] = basename($cr_lf); }
    }
    cr_log('Protokoll geleert (Oberflaeche).');
    cw_umleiten('tab-log', array('meldungen' => $cr_weg ? array(cr_e(sprintf(cr_t('TEXT.LOG_GELEERT'), implode(', ', $cr_weg)))) : array(),
                                 'fehler' => $cr_bleibt ? array(cr_e(sprintf(cr_t('TEXT.LOG_NICHT_GELEERT'), implode(', ', $cr_bleibt)))) : array()));
}
/* Ein POST, den kein Handler genommen hat, endet ebenfalls mit einer Umleitung. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cw_umleiten($cr_tab, array());
}

/* ---------- Daten fuer die Anzeige (keine Netzabfrage; der Stand kommt aus dem Minutenlauf) ---------- */
$cr_lauf = cr_lauf_lesen();
$cr_alter = cr_lauf_alter();
$cr_st = cr_zustand_lesen();
$cr_w = cr_werte($cr_st, $cr_lauf);
$cr_host = cr_host();
$cr_praefix = cr_mqtt_thema_saeubern($cr_cfg['mqtt_topic']);
$cr_gw = cr_mqtt_gateway_info();
$cr_udp = cr_mqtt_udpport();
$cr_tok = (string) $cr_cfg['aktionstoken'];

/** Das versteckte Feld des Wachpostens - in JEDEM Formular. */
function cw_fmt()
{
    global $cr_fmt;
    return '<input data-role="none" type="hidden" name="fmt" value="' . cr_e($cr_fmt) . '">';
}
/** Ein Messwert fuer die Anzeige: Strich, wenn nicht gemessen. */
function cw_wert($v, $einheit = '')
{
    if ($v === null) { return '&ndash;'; }
    return cr_e((string) $v) . ($einheit !== '' ? '&nbsp;' . cr_e($einheit) : '');
}
/** Haken-Kaestchen (Beschriftung maskiert). */
function cw_haken($name, $an, $text)
{
    return '<label style="display:flex;align-items:center;gap:8px;font-weight:400;"><input data-role="none" type="checkbox" name="'
        . cr_e($name) . '" value="1"' . ($an ? ' checked' : '') . '> ' . cr_e($text) . '</label>';
}

/* ---------- 6. Erst jetzt der Seitenkopf ---------- */
$cr_frame = class_exists('LBWeb', false);
if ($cr_frame) {
    LBWeb::lbheader(cr_t('TEXT.TITEL') . ' ' . cr_pluginversion(), 'https://wiki.loxberry.de/plugins/start', 'help.html');
} else {
    echo '<!DOCTYPE html><html lang="' . cr_e(cr_sprache()) . '"><head><meta charset="utf-8"><title>'
       . cr_e(cr_t('TEXT.TITEL')) . '</title></head><body>';
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Aus derselben Vorlage, Abschnitt "Zwei Bausteine aus der Bewaesserungs-Sitzung": */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Eigene Zutaten dieser Linie (nicht Teil der Hausvorlage): Felder, Pruefzeilen, Protokoll, beanstandetes Feld. */
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
    width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-klein { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-pruef td:first-child { width: 26px; text-align: center; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, monospace; font-size: 0.82em; padding: 12px;
    border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* Seitenbild aus dem Musterprojekt, wie Weatherdata 0.9.17. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
</style>
<div class="sm-wrap">

<?php
/* Meldungen: die Texte sind beim Erzeugen maskiert (cr_e) - was aus einer hochgeladenen Datei stammt, ebenso. */
foreach ($cr_meldungen as $cr_m) { ?><div class="sm-hinweis"><?= $cr_m ?></div><?php }
foreach ($cr_fehler as $cr_f) { ?><div class="sm-warnung"><b><?= cr_e(cr_t('TEXT.FEHLER')) ?></b> <?= $cr_f ?></div><?php } ?>

<?php /* Kopf: Statusuebersicht ueber den Reitern (Entscheidung Nr. 43) - nur aus Dateien, keine Netzabfrage. */ ?>
<table class="sm-tbl" style="max-width:640px">
<tr><th><?= cr_e(cr_t('WORT.EIGENSCHAFT')) ?></th><th><?= cr_e(cr_t('WORT.WERT')) ?></th></tr>
<tr><td><?= cr_e(cr_t('KOPF.DRUCKER')) ?></td>
    <td><?= (string) $cr_cfg['drucker_host'] === '' ? cr_e(cr_t('KOPF.KEINE_ADRESSE'))
        : '<span class="sm-mono">' . cr_e($cr_cfg['drucker_host'] . ':' . (int) $cr_cfg['drucker_port']) . '</span>' ?></td></tr>
<tr><td><?= cr_e(cr_t('KOPF.ABRUF')) ?></td>
    <td><?= cr_e(sprintf(cr_t('KOPF.ABRUF_TEXT'), (int) $cr_cfg['abfragetakt'])) ?></td></tr>
<tr><td><?= cr_e(cr_t('KOPF.LETZTER_LAUF')) ?></td>
    <td><?= $cr_alter < 0 ? cr_e(cr_t('KOPF.NOCH_KEIN_LAUF')) : cr_e(date('d.m.Y H:i:s', $cr_lauf['ts']) . ' ' . sprintf(cr_t('KOPF.VOR_S'), $cr_alter)) ?></td></tr>
<tr><td><?= cr_e(cr_t('KOPF.ZUSTAND')) ?></td>
    <td><?php if ($cr_alter < 0) { ?>&ndash;<?php } elseif (!$cr_lauf['ok']) { ?><span class="sm-aus"><?= cr_e(cr_t('KOPF.KEINE_ANTWORT')) ?></span> <?= cr_e(cr_grund_text($cr_lauf['grund'])) ?><?php }
    else { ?><b><?= cw_wert($cr_w['STATUSTEXT']) ?></b><?php if ((int) $cr_w['DRUCKT'] === 1 || (int) $cr_w['PAUSIERT'] === 1) { ?> &middot; <?= cw_wert($cr_w['FORTSCHRITT'], '%') ?> &middot; <?= cr_e(cr_t('KOPF.REST')) ?> <?= cw_wert($cr_w['RESTZEIT'], 'min') ?><?php } ?>
    &middot; <?= cr_e(cr_t('KOPF.DUESE')) ?> <?= cw_wert($cr_w['DUESE'], '°C') ?> &middot; <?= cr_e(cr_t('KOPF.BETT')) ?> <?= cw_wert($cr_w['BETT'], '°C') ?> &middot; <?= cr_e(cr_t('KOPF.KAMMER')) ?> <?= cw_wert($cr_w['KAMMER'], '°C') ?><?php
    if ((int) $cr_w['FEHLER'] === 1) { ?><br><span class="sm-aus"><?= cr_e(cr_t('KOPF.FEHLER')) ?></span> <?= cr_e((string) $cr_w['FEHLERTEXT']) ?><?php } } ?></td></tr>
<tr><td><?= cr_e(cr_t('REITER.MQTT')) ?></td>
    <td><b><?= cr_e(cr_t(!empty($cr_cfg['mqtt_enabled']) ? 'WORT.EIN' : 'WORT.AUS')) ?></b>
    <div class="sm-klein"><?= cr_e(sprintf(cr_t('KOPF.GATEWAY'), $cr_gw === null ? cr_t('WORT.NICHT_FESTSTELLBAR') : cr_t($cr_gw['autostart'] ? 'WORT.EIN' : 'WORT.AUS'))) ?></div></td></tr>
<tr><td><?= cr_e(cr_t('KOPF.BEFEHLE')) ?></td>
    <td><?= cr_e(sprintf(cr_t('KOPF.BEFEHLE_TEXT'), cr_t(!empty($cr_cfg['befehle_mqtt_ein']) ? 'WORT.EIN' : 'WORT.AUS'), cr_t(!empty($cr_cfg['befehle_http_ein']) ? 'WORT.EIN' : 'WORT.AUS'))) ?></td></tr>
</table>

<div class="sm-tabs">
	<a class="sm-tab<?= $cr_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= cr_e(cr_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $cr_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $cr_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= cr_e(cr_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $cr_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= cr_e(cr_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $cr_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= cr_e(cr_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $cr_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= cr_t('EINST.WAS_IST_DAS') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= cr_e(cr_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= cr_e(cr_t('LEGENDE.AKTION')) ?></span>
</div>

<h2><?= cr_e(cr_t('EINST.H_DRUCKER')) ?></h2>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formular" value="drucker">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= cw_fmt() ?>
<div class="sm-feld"><label for="cr_host"><?= cr_e(cr_t('EINST.L_DRUCKER_HOST')) ?></label>
<input data-role="none" type="text" id="cr_host" name="drucker_host" maxlength="253" placeholder="192.0.2.24" value="<?= cr_e(cw_w('drucker_host', $cr_cfg['drucker_host'])) ?>"<?= cw_m('drucker_host') ?>>
<div class="sm-hilfe"><?= cr_e(cr_t('EINST.H_DRUCKER_HOST')) ?></div></div>
<div class="sm-feld"><label for="cr_port"><?= cr_e(cr_t('EINST.L_DRUCKER_PORT')) ?></label>
<input data-role="none" type="number" id="cr_port" name="drucker_port" min="1" max="65535" value="<?= cr_e(cw_w('drucker_port', $cr_cfg['drucker_port'])) ?>"<?= cw_m('drucker_port') ?>>
<div class="sm-hilfe"><?= cr_e(cr_t('EINST.H_DRUCKER_PORT')) ?></div></div>
<div class="sm-feld"><label for="cr_takt"><?= cr_e(cr_t('EINST.L_ABFRAGETAKT')) ?></label>
<select data-role="none" id="cr_takt" name="abfragetakt"<?= cw_m('abfragetakt') ?>>
<?php $cr_tw = cw_w('abfragetakt', $cr_cfg['abfragetakt']); ?>
<option value="60"<?= $cr_tw === '60' ? ' selected' : '' ?>>60 s</option>
<option value="30"<?= $cr_tw === '30' ? ' selected' : '' ?>>30 s</option>
<option value="20"<?= $cr_tw === '20' ? ' selected' : '' ?>>20 s</option>
<option value="15"<?= $cr_tw === '15' ? ' selected' : '' ?>>15 s</option>
<option value="10"<?= $cr_tw === '10' ? ' selected' : '' ?>>10 s</option>
</select>
<div class="sm-hilfe"><?= cr_e(cr_t('EINST.H_ABFRAGETAKT')) ?></div></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= cr_e(cr_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= cr_e(cr_t('EINST.H_BEFEHLE')) ?></h2>
<div class="sm-warnung"><?= cr_t('EINST.BEFEHLE_WARNUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formular" value="befehle">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?= cw_fmt() ?>
<?= cw_haken('befehle_http_ein', !empty($cr_cfg['befehle_http_ein']), cr_t('EINST.HAKEN_HTTP')) ?>
<div class="sm-hilfe"><?= cr_e(cr_t('EINST.HAKEN_HTTP_HILFE')) ?></div>
<h3><?= cr_e(cr_t('EINST.H_STUFEN')) ?></h3>
<?= cw_haken('freigabe_licht', !empty($cr_cfg['freigabe_licht']), cr_t('EINST.HAKEN_LICHT')) ?>
<?= cw_haken('freigabe_pause', !empty($cr_cfg['freigabe_pause']), cr_t('EINST.HAKEN_PAUSE')) ?>
<?= cw_haken('freigabe_abbruch', !empty($cr_cfg['freigabe_abbruch']), cr_t('EINST.HAKEN_ABBRUCH')) ?>
<?= cw_haken('freigabe_notaus', !empty($cr_cfg['freigabe_notaus']), cr_t('EINST.HAKEN_NOTAUS')) ?>
<div class="sm-hilfe"><?= cr_e(cr_t('EINST.STUFEN_HILFE')) ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= cr_e(cr_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= cr_e(cr_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-warnung"><?= cr_e(cr_t('EINST.SICHERUNG_HINWEIS')) ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="sichern">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <?= cw_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= cr_e(cr_t('KNOPF.SICHERN')) ?></button>
</form>
<form action="index.php" method="post" enctype="multipart/form-data">
  <input data-role="none" type="hidden" name="formular" value="zurueck">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <?= cw_fmt() ?>
  <input data-role="none" type="file" name="cr_sicherung" accept=".json,application/json">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= cr_e(cr_t('KNOPF.ZURUECK')) ?></button>
</form>
</div>
<div class="sm-hilfe"><?= cr_e(cr_t('EINST.ZURUECK_HILFE')) ?></div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-seite<?= $cr_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-hinweis"><?= cr_t('MQTT.WAS_IST_DAS') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= cr_e(cr_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= cr_e(cr_t('MQTT.H_GATEWAY')) ?></h2>
<table class="sm-tbl" style="max-width:640px">
<tr><th><?= cr_e(cr_t('WORT.EIGENSCHAFT')) ?></th><th><?= cr_e(cr_t('WORT.WERT')) ?></th></tr>
<tr><td><?= cr_e(cr_t('MQTT.FASSUNG')) ?></td><td><?= $cr_gw === null ? cr_e(cr_t('WORT.NICHT_FESTSTELLBAR')) : (int) $cr_gw['fassung'] ?></td></tr>
<tr><td><?= cr_e(cr_t('MQTT.AUTOSTART')) ?></td><td><?= $cr_gw === null ? cr_e(cr_t('WORT.NICHT_FESTSTELLBAR')) : cr_e(cr_t($cr_gw['autostart'] ? 'WORT.EIN' : 'WORT.AUS')) ?></td></tr>
<tr><td><?= cr_e(cr_t('MQTT.UDPPORT')) ?></td><td><?= $cr_udp > 0 ? (int) $cr_udp : cr_e(cr_t('WORT.NICHT_FESTSTELLBAR')) ?></td></tr>
<tr><td><?= cr_e(cr_t('MQTT.ABO')) ?></td><td><span class="sm-mono"><?= cr_e($cr_praefix . '/#') ?></span>
    <div class="sm-klein"><?= cr_e(cr_t(($cr_gw !== null && $cr_gw['fassung'] >= 2) ? 'MQTT.ABO_V2' : 'MQTT.ABO_V1')) ?></div></td></tr>
</table>
<?php if ($cr_gw !== null && !$cr_gw['autostart']) { ?><div class="sm-warnung"><?= cr_e(cr_t('MQTT.AUTOSTART_AUS')) ?></div><?php } ?>

<h2><?= cr_e(cr_t('MQTT.H_EINSTELLUNGEN')) ?></h2>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formular" value="mqtt">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<?= cw_fmt() ?>
<?= cw_haken('mqtt_enabled', !empty($cr_cfg['mqtt_enabled']), cr_t('MQTT.HAKEN_EIN')) ?>
<div class="sm-feld"><label for="cr_thema"><?= cr_e(cr_t('MQTT.L_THEMA')) ?></label>
<input data-role="none" type="text" id="cr_thema" name="mqtt_topic" maxlength="120" value="<?= cr_e(cw_w('mqtt_topic', $cr_cfg['mqtt_topic'])) ?>"<?= cw_m('mqtt_topic') ?>>
<div class="sm-hilfe"><?= cr_e(cr_t('MQTT.H_THEMA')) ?></div></div>
<h3><?= cr_e(cr_t('MQTT.H_BEFEHLE')) ?></h3>
<?= cw_haken('befehle_mqtt_ein', !empty($cr_cfg['befehle_mqtt_ein']), cr_t('MQTT.HAKEN_BEFEHLE')) ?>
<?= cw_haken('befehle_mqtt_steuern', !empty($cr_cfg['befehle_mqtt_steuern']), cr_t('MQTT.HAKEN_STEUERN')) ?>
<div class="sm-hilfe"><?= cr_e(cr_t('MQTT.BEFEHLE_HILFE')) ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= cr_e(cr_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>
<?php if (!empty($cr_cfg['befehle_mqtt_ein'])) { list($cr_bz, $cr_bt) = cr_bruecke_zustand(); ?>
<div class="<?= $cr_bz === 1 ? 'sm-hinweis' : 'sm-warnung' ?>"><b><?= cr_e(cr_t('MQTT.BRUECKE')) ?></b> <?= cr_e($cr_bt) ?></div>
<?php } ?>

<h2><?= cr_e(cr_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-hilfe"><?= cr_e(cr_t('MQTT.THEMEN_HILFE')) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('WORT.THEMA')) ?></th><th><?= cr_e(cr_t('WORT.BEDEUTUNG')) ?></th><th><?= cr_e(cr_t('WORT.EINHEIT')) ?></th><th>Retain</th></tr>
<?php foreach (cr_mqtt_themen($cr_praefix) as $cr_th => $cr_x) { ?>
<tr><td><span class="sm-mono"><?= cr_e($cr_th) ?></span></td><td><?= cr_e($cr_x['bedeutung']) ?><?= !empty($cr_x['text']) ? ' <i>(' . cr_e(cr_t('WORT.TEXT')) . ')</i>' : '' ?></td><td><?= cr_e($cr_x['einheit']) ?></td><td><?= cr_e(cr_t($cr_x['retain'] ? 'WORT.JA' : 'WORT.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $cr_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-hinweis"><?= cr_t('LOX.WAS_IST_DAS') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= cr_e(cr_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= cr_e(cr_t('LEGENDE.AKTION_TOKEN')) ?></span>
</div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S1_TITEL')) ?></b><br>
<?= cr_e(sprintf(cr_t('LOX.S1_TEXT'), $cr_praefix . '/#')) ?>
<div class="sm-klein"><?= cr_e(cr_t(!empty($cr_cfg['mqtt_enabled']) ? 'LOX.S1_MQTT_EIN' : 'LOX.S1_MQTT_AUS')) ?></div></div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S2_TITEL')) ?></b><br>
<?= cr_e(cr_t('LOX.S2_TEXT')) ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('LOX.T_EINGANG')) ?></th><th><?= cr_e(cr_t('LOX.T_KACHEL')) ?></th><th><?= cr_e(cr_t('WORT.BEDEUTUNG')) ?></th><th><?= cr_e(cr_t('WORT.EINHEIT')) ?></th></tr>
<tr><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/status/ok')) ?></span></td><td><?= cr_e(cr_t('LOX.VORSATZ') . cr_t('KACHEL.OK')) ?></td><td><?= cr_e(cr_t('MQTT.T_OK')) ?></td><td></td></tr>
<tr><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/status/ts')) ?></span></td><td><?= cr_e(cr_t('LOX.VORSATZ') . cr_t('KACHEL.TS')) ?></td><td><?= cr_e(cr_t('MQTT.T_TS')) ?></td><td>s</td></tr>
<tr><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/status/zaehler')) ?></span></td><td><?= cr_e(cr_t('LOX.VORSATZ') . cr_t('KACHEL.ZAEHLER')) ?></td><td><?= cr_e(cr_t('MQTT.T_ZAEHLER')) ?></td><td></td></tr>
<?php foreach (cr_felder() as $cr_n => $cr_f) { if ($cr_f[7] === '' || $cr_f[10] === 'text') { continue; } ?>
<tr><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/' . $cr_f[7])) ?></span></td><td><?= cr_e(cr_t('LOX.VORSATZ') . cr_feld_kachel($cr_n)) ?></td><td><?= cr_e(cr_feld_bedeutung($cr_n)) ?></td><td><?= cr_e($cr_f[3]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-klein"><?= cr_e(cr_t('LOX.S2_TEXTTHEMEN')) ?></div></div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S3_TITEL')) ?></b><br>
<?= cr_e(cr_t('LOX.S3_TEXT')) ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= cr_e(cr_t('BAUSTEIN.T_TYP')) ?></th><th><?= cr_e(cr_t('BAUSTEIN.T_NAME')) ?></th><th><?= cr_e(cr_t('BAUSTEIN.T_PARAM')) ?></th><th><?= cr_e(cr_t('BAUSTEIN.T_VERB')) ?></th></tr>
<tr><td>1</td><td><?= cr_e(cr_t('BAUSTEIN.Z1_TYP')) ?></td><td><span class="sm-mono"><?= cr_e(cr_t('BAUSTEIN.Z1_NAME')) ?></span></td><td><?= cr_e(cr_t('BAUSTEIN.Z1_PARAM')) ?></td><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/status/zaehler')) ?></span></td></tr>
<tr><td>2</td><td><?= cr_e(cr_t('BAUSTEIN.Z2_TYP')) ?></td><td><span class="sm-mono"><?= cr_e(cr_t('BAUSTEIN.Z2_NAME')) ?></span></td><td><?= cr_e(cr_t('BAUSTEIN.Z2_PARAM')) ?></td><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/zustand/fertig')) ?></span></td></tr>
<tr><td>3</td><td><?= cr_e(cr_t('BAUSTEIN.Z3_TYP')) ?></td><td><span class="sm-mono"><?= cr_e(cr_t('BAUSTEIN.Z3_NAME')) ?></span></td><td><?= cr_e(cr_t('BAUSTEIN.Z3_PARAM')) ?></td><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/zustand/fehler')) ?></span></td></tr>
<tr><td>4</td><td><?= cr_e(cr_t('BAUSTEIN.Z4_TYP')) ?></td><td><span class="sm-mono"><?= cr_e(cr_t('BAUSTEIN.Z4_NAME')) ?></span></td><td><?= cr_e(cr_t('BAUSTEIN.Z4_PARAM')) ?></td><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/fortschritt_prozent')) ?></span></td></tr>
<tr><td>5</td><td><?= cr_e(cr_t('BAUSTEIN.Z5_TYP')) ?></td><td><span class="sm-mono"><?= cr_e(cr_t('BAUSTEIN.Z5_NAME')) ?></span></td><td><?= cr_e(cr_t('BAUSTEIN.Z5_PARAM')) ?></td><td><span class="sm-mono"><?= cr_e(cr_gateway_name($cr_praefix . '/cfs/1/feuchte_prozent')) ?></span></td></tr>
</table>
</div>
<figure class="sm-bild">
<img src="einbindung_creality2lox.png" alt="<?= cr_e(cr_t('LOX.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= cr_e(cr_t('LOX.BILD_UNTERSCHRIFT')) ?></figcaption>
</figure>
<div class="sm-klein"><?= cr_e(cr_t('LOX.MUSTERPROJEKT')) ?> <a href="https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt" target="_blank" rel="noopener">LoxBerry-Plugins Musterprojekt</a></div></div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S4_TITEL')) ?></b><br>
<?= cr_e(cr_t('LOX.S4_TEXT')) ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('WORT.THEMA')) ?></th><th><?= cr_e(cr_t('LOX.T_WERT')) ?></th><th><?= cr_e(cr_t('WORT.WIRKUNG')) ?></th><th><?= cr_e(cr_t('LOX.T_FREIGABE')) ?></th></tr>
<?php foreach (cr_befehle() as $cr_bn => $cr_b) { ?>
<tr><td><span class="sm-mono"><?= cr_e($cr_praefix . '/befehl/' . $cr_bn) ?></span></td><td><?= $cr_b['art'] === 'schalter' ? '1 / 0' : '1' ?></td><td><?= cr_e(cr_t('BEFEHL.K_' . strtoupper($cr_bn))) ?> <i>(<?= cr_e(cr_t('WORT.NICHT_ERPROBT')) ?>)</i></td><td><?= cr_e(cr_t(!empty($cr_cfg[$cr_b['freigabe']]) ? 'WORT.EIN' : 'WORT.AUS')) ?><?= $cr_b['eingreifend'] ? ' &middot; ' . cr_e(cr_t('LOX.EINGREIFEND')) : '' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-klein"><?= cr_e(sprintf(cr_t('LOX.S4_QUITTUNG'), $cr_praefix . '/befehl/<name>/quittung')) ?></div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="vorlage_mqtt">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?= cw_fmt() ?>
  <div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= cr_e(cr_t('KNOPF.VORLAGE_MQTT')) ?></button></div>
</form></div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S5_TITEL')) ?></b><br>
<?= cr_e(cr_t('LOX.S5_TEXT')) ?></div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S6_TITEL')) ?></b><br>
<?= cr_e(cr_t('LOX.S6_TEXT')) ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formular" value="endpunkt">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?= cw_fmt() ?>
<?= cw_haken('endpunkt_ein', !empty($cr_cfg['endpunkt_ein']), cr_t('LOX.HAKEN_ENDPUNKT')) ?>
<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= cr_e(cr_t('KNOPF.SPEICHERN')) ?></button></div>
</form>
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('WORT.EIGENSCHAFT')) ?></th><th><?= cr_e(cr_t('WORT.WERT')) ?></th></tr>
<tr><td><?= cr_e(cr_t('LOX.ADRESSE_VI')) ?></td><td><span class="sm-mono">http://<?= cr_e($cr_host . cr_endpunkt_pfad()) ?></span></td></tr>
<tr><td><?= cr_e(cr_t('LOX.ABFRAGEZYKLUS')) ?></td><td><?= cr_e(cr_t('LOX.ABFRAGEZYKLUS_WERT')) ?></td></tr>
</table>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('LOX.BEFEHLSERKENNUNG')) ?></th><th><?= cr_e(cr_t('LOX.T_KACHEL')) ?></th><th><?= cr_e(cr_t('WORT.BEDEUTUNG')) ?></th><th><?= cr_e(cr_t('WORT.EINHEIT')) ?></th></tr>
<?php foreach (cr_felder() as $cr_n => $cr_f) { if ($cr_f[8] !== 1) { continue; } ?>
<tr><td><span class="sm-mono"><?= cr_e(cr_check($cr_n)) ?></span></td><td><?= cr_e(cr_t('LOX.VORSATZ') . cr_feld_kachel($cr_n)) ?></td><td><?= cr_e(cr_feld_bedeutung($cr_n)) ?></td><td><?= cr_e($cr_f[3]) ?></td></tr>
<?php } ?>
</table>
</div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="vorlage_vi">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?= cw_fmt() ?>
  <div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= cr_e(cr_t('KNOPF.VORLAGE_VI')) ?></button></div>
</form></div>

<div class="sm-step"><b><?= cr_e(cr_t('LOX.S7_TITEL')) ?></b><br>
<?= cr_e(cr_t('LOX.S7_TEXT')) ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('LOX.BEFEHL_BEI_EIN')) ?></th><th><?= cr_e(cr_t('WORT.WIRKUNG')) ?></th></tr>
<?php foreach (cr_befehle() as $cr_bn => $cr_b) {
    $cr_wv = array('cmd' => $cr_bn);
    if ($cr_b['art'] === 'schalter') { $cr_wv['wert'] = '1'; }
    $cr_wv['token'] = $cr_tok; ?>
<tr><td><span class="sm-mono">http://<?= cr_e($cr_host . cr_endpunkt_pfad($cr_wv)) ?></span></td><td><?= cr_e(cr_t('BEFEHL.K_' . strtoupper($cr_bn))) ?> <i>(<?= cr_e(cr_t('WORT.NICHT_ERPROBT')) ?>)</i></td></tr>
<?php } ?>
<tr><td><span class="sm-mono">http://<?= cr_e($cr_host . cr_endpunkt_pfad(array('selftest' => 1, 'token' => $cr_tok))) ?></span></td><td><?= cr_e(cr_t('LOX.SELBSTTEST')) ?></td></tr>
</table>
</div>
<table class="sm-tbl" style="max-width:640px">
<tr><th><?= cr_e(cr_t('WORT.EIGENSCHAFT')) ?></th><th><?= cr_e(cr_t('WORT.WERT')) ?></th></tr>
<tr><td><?= cr_e(cr_t('LOX.AKTUELLES_TOKEN')) ?></td><td><span class="sm-mono"><?= cr_e($cr_tok) ?></span></td></tr>
<tr><td><?= cr_e(cr_t('LOX.HTTP_BEFEHLE')) ?></td><td><?= cr_e(cr_t(!empty($cr_cfg['befehle_http_ein']) ? 'WORT.EIN' : 'WORT.AUS')) ?></td></tr>
</table>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="vorlage_vq">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?= cw_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= cr_e(cr_t('KNOPF.VORLAGE_VQ')) ?></button>
</form>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="token">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <?= cw_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= cr_e(cr_t('KNOPF.TOKEN_NEU')) ?></button>
</form>
</div>
<div class="sm-klein"><?= cr_e(cr_t('LOX.TOKEN_HINWEIS')) ?></div></div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $cr_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-hinweis"><?= cr_e(cr_t('TEST.WAS_IST_DAS')) ?></div>
<h2><?= cr_e(cr_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<table class="sm-tbl sm-pruef">
<?php $cr_zahl = array(0, 0, 0);
foreach (cr_selbsttest(array('test_offen' => $cr_tab === 'tab-test')) as $cr_z) {
    $cr_zahl[$cr_z['ok'] === 1 ? 0 : ($cr_z['ok'] === 0 ? 1 : 2)]++;
    $cr_zeichen = $cr_z['ok'] === 1 ? '&#10003;' : ($cr_z['ok'] === 0 ? '&#10007;' : '&ndash;');
    $cr_farbe = $cr_z['ok'] === 1 ? '#4f7d17' : ($cr_z['ok'] === 0 ? '#c62828' : '#888'); ?>
<tr><td style="color:<?= $cr_farbe ?>;"><?= $cr_zeichen ?></td><td><?= cr_e(cr_t($cr_z['bez'])) ?></td><td><?= cr_e($cr_z['text']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-klein"><?= cr_e(sprintf(cr_t('TEST.ZAEHLUNG'), $cr_zahl[0], $cr_zahl[1], $cr_zahl[2])) ?></div>

<h2><?= cr_e(cr_t('TEST.H_WERTE')) ?></h2>
<?php if ($cr_st === null) { ?><div class="sm-klein"><?= cr_e(cr_t('TEST.NOCH_KEINE_WERTE')) ?></div><?php } else { ?>
<div class="sm-klein"><?= cr_e(sprintf(cr_t('TEST.WERTE_STAND'), date('d.m.Y H:i:s', (int) $cr_st['ts']))) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= cr_e(cr_t('LOX.T_FELD')) ?></th><th><?= cr_e(cr_t('WORT.BEDEUTUNG')) ?></th><th><?= cr_e(cr_t('WORT.WERT')) ?></th></tr>
<?php foreach (cr_felder() as $cr_n => $cr_f) { if ($cr_w[$cr_n] === null) { continue; } ?>
<tr><td><span class="sm-mono"><?= cr_e($cr_n) ?></span></td><td><?= cr_e(cr_feld_bedeutung($cr_n)) ?></td><td><?= cw_wert($cr_w[$cr_n], $cr_f[3]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h2><?= cr_e(cr_t('TEST.H_KNOEPFE')) ?></h2>
<?php /* Die Legende nennt genau die Farben, die in diesem Reiter gerade als Knopf stehen. */
$cr_aktion_da = false;
foreach (cr_befehle() as $cr_b) { if (!empty($cr_cfg[$cr_b['freigabe']])) { $cr_aktion_da = true; } } ?>
<div class="sm-legende">
<?php if (!empty($cr_cfg['endpunkt_ein'])) { ?><span><i class="sm-punkt sm-b-lesen"></i> <?= cr_e(cr_t('LEGENDE.LESEN')) ?></span><?php } ?>
<span><i class="sm-punkt sm-b-technik"></i> <?= cr_e(cr_t('LEGENDE.TECHNIK')) ?></span>
<?php if ($cr_aktion_da) { ?><span><i class="sm-punkt sm-b-aktion"></i> <?= cr_e(cr_t('LEGENDE.AKTION')) ?></span><?php } ?>
</div>
<h3><?= cr_e(cr_t('TEST.H_ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
<?php if (!empty($cr_cfg['endpunkt_ein'])) { ?>
<a data-role="none" class="sm-btn sm-b-lesen" href="<?= cr_e(cr_endpunkt_pfad()) ?>" target="_blank"><?= cr_e(cr_t('TEST.K_ZEILE')) ?></a>
<a data-role="none" class="sm-btn sm-b-lesen" href="<?= cr_e(cr_endpunkt_pfad(array('json' => 1))) ?>" target="_blank"><?= cr_e(cr_t('TEST.K_JSON')) ?></a>
<?php } ?>
<a data-role="none" class="sm-btn sm-b-technik" href="<?= cr_e(cr_endpunkt_pfad(array('selftest' => 1, 'token' => $cr_tok))) ?>" target="_blank"><?= cr_e(cr_t('TEST.K_SELFTEST')) ?></a>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="abfragen">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?= cw_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="abruf_jetzt" value="1"><?= cr_e(cr_t('TEST.K_ABFRAGEN')) ?></button>
</form>
</div>
<?php if (empty($cr_cfg['endpunkt_ein'])) { ?><div class="sm-klein"><?= cr_e(cr_t('TEST.LESEZWEIG_AUS')) ?></div><?php } ?>

<h3><?= cr_e(cr_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= cr_e(cr_t('TEST.SCHALTEN_HINWEIS')) ?></div>
<div class="sm-knopfreihe">
<?php $cr_frei = 0;
foreach (cr_befehle() as $cr_bn => $cr_b) {
    if (empty($cr_cfg[$cr_b['freigabe']])) { continue; }
    foreach ($cr_b['art'] === 'schalter' ? array('1', '0') : array('') as $cr_bw) { $cr_frei++; ?>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="befehl">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="cmd" value="<?= cr_e($cr_bn) ?>">
  <input data-role="none" type="hidden" name="wert" value="<?= cr_e($cr_bw) ?>">
  <?= cw_fmt() ?>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= cr_e(cr_t('BEFEHL.K_' . strtoupper($cr_bn)) . ($cr_bw !== '' ? ' ' . cr_t($cr_bw === '1' ? 'WORT.EIN' : 'WORT.AUS') : '') . ' (' . cr_t('WORT.NICHT_ERPROBT') . ')') ?></button>
</form>
<?php } } ?>
</div>
<?php if ($cr_frei === 0) { ?><div class="sm-klein"><?= cr_e(cr_t('TEST.KEINE_FREIGABE')) ?></div><?php } ?>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $cr_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-hinweis"><?= cr_e(cr_t('LOG.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= cr_e(cr_t('LEGENDE.AKTION')) ?></span>
</div>
<?php $cr_zeilen = array_reverse(cr_log_lesen(300)); ?>
<?php if (!$cr_zeilen) { ?><div class="sm-klein"><?= cr_e(sprintf(cr_t('LOG.LEER'), basename($cr_p['log']))) ?></div><?php } else { ?>
<div class="sm-log"><?php foreach ($cr_zeilen as $cr_lz) { echo cr_e($cr_lz) . "\n"; } ?></div>
<?php } ?>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formular" value="log">
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <?= cw_fmt() ?>
  <?= cw_haken('clearlog_ok', false, cr_t('LOG.HAKEN_LEEREN')) ?>
  <div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="clearlog" value="1"><?= cr_e(cr_t('KNOPF.LOG_LEEREN')) ?></button></div>
</form>
</div>

</div>
<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($cr_tab) ?>);
})();
</script>
<?php
if ($cr_frame) { LBWeb::lbfooter(); } else { echo '</body></html>'; }
