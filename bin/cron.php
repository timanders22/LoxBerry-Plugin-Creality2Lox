<?php
/**
 * Creality2Lox - Minutenlauf (aufgerufen von cron/cron.01min, als Benutzer loxberry).
 *
 * Die EINZIGE Stelle, die den Zustand fortschreibt: Drucker abfragen (Moonraker, ein GET je Durchgang), Zustand und
 * Lauf in /tmp/<ordner>/ ablegen, Werte ueber das MQTT-Gateway senden (nur Geaendertes, alle 30 Minuten voll), das
 * Lebenszeichen bei jedem Durchgang. Mit einem Abfragetakt unter 60 s laufen mehrere Durchgaenge in dieser Minute
 * (hoechstens CR_LAUF_FRIST Sekunden). Am Ende ein Waechterlauf der Befehlsbruecke.
 *
 * Kein Dauerlaeufer: Moonraker schickt ueber den Websocket in Ruhe vier Meldungen je Sekunde, fast nur
 * Temperaturen (gemessen 12.11.2026: 298 notify_status_update in 74,4 s, Abstand 0,225-0,277 s). Loxone braucht das
 * nicht; eine dauernde Verbindung zum Drucker (2 Kerne ARMv7) waere eine zweite Fehlerquelle mit eigenem Waechter.
 *
 * Aufrufformen:
 *   php cron.php                   Minutenlauf
 *   php cron.php --einmal          genau ein Durchgang (Knopf "Jetzt abfragen" im Reiter Test)
 *   php cron.php --mqtt-leeren     aus der Deinstallation: zurueckbehaltene Themen leeren
 *   unbekannter Schalter -> Satz auf STDERR, Rueckgabe 2
 */
$cr_htmldir = 'REPLACELBPHTMLDIR';
if (strpos($cr_htmldir, 'REPLACE') === 0 || !is_file($cr_htmldir . '/cr_lib.php')) {
    $cr_htmldir = dirname(__DIR__) . '/webfrontend/html';
}
if (!is_file($cr_htmldir . '/cr_lib.php')) {
    $cr_kandidat = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__);
    if (is_file($cr_kandidat . '/cr_lib.php')) { $cr_htmldir = $cr_kandidat; }
}
if (!is_file($cr_htmldir . '/cr_lib.php')) {
    fwrite(STDERR, "cr_lib.php nicht gefunden (gesucht in $cr_htmldir)\n");
    exit(1);
}
require_once $cr_htmldir . '/cr_lib.php';

$cr_argv = (isset($argv) && is_array($argv)) ? array_slice($argv, 1) : array();
if (count($cr_argv) > 1 || ($cr_argv && !in_array($cr_argv[0], array('--einmal', '--mqtt-leeren'), true))) {
    fwrite(STDERR, 'cron.php: unbekannter Schalter ' . preg_replace('/[^\x20-\x7E]/', '?', substr(implode(' ', $cr_argv), 0, 80))
        . " - erlaubt sind keiner, --einmal oder --mqtt-leeren\n");
    exit(2);
}

cr_keine_wurzel_abbruch('cron.php');

if (in_array('--mqtt-leeren', $cr_argv, true)) {
    /* Deinstallation: jedes zurueckbehaltene Thema mit leerer retain-Nutzlast loeschen (die Form, die das Gateway als
     * Loeschung liest). Nicht nachgelesen am Broker - der UDP-Eingang verwirft unter Last; deshalb zwei Runden. */
    cr_config_erzeugen_erlauben(false);
    $cr_cfg = cr_config(false);
    $cr_udp = cr_mqtt_udpport();
    if (!$cr_udp) { echo "<INFO> Kein UDP-Eingang des MQTT-Gateways bekannt - zurueckbehaltene Themen nicht geleert.\n"; exit(0); }
    $cr_w = cr_mqtt_thema_saeubern($cr_cfg['mqtt_topic']);
    $cr_z = array();
    foreach (cr_felder() as $cr_f) {
        if ($cr_f[7] !== '' && $cr_f[5] === 1) { $cr_z[] = 'retain ' . $cr_w . '/' . $cr_f[7] . ' '; }
    }
    $cr_n = cr_udp_senden($cr_udp, $cr_z) + cr_udp_senden($cr_udp, $cr_z);
    echo '<INFO> ' . count($cr_z) . ' zurueckbehaltene Themen unter ' . $cr_w . "/ zum Leeren gesendet (zwei Runden, "
        . $cr_n . " Datagramme, am Broker nicht nachgelesen).\n";
    exit(0);
}

if (cr_upgrade_marke_frisch()) {
    echo "UPGRADE\n";
    exit(0);
}

$cr_lock = @fopen(cr_tmpdir() . '/cron.lock', 'c');
if ($cr_lock === false) {
    fwrite(STDERR, "Sperrdatei in " . cr_tmpdir() . " nicht anlegbar\n");
    exit(1);
}
if (!flock($cr_lock, LOCK_EX | LOCK_NB)) {
    cr_log_if_changed('cron_lock', 'voriger Durchgang laeuft noch (' . date('H:i') . '), dieser wird uebersprungen.');
    exit(0);
}

cr_config_vervollstaendigen();
$cr_cfg = cr_config();
$cr_einmal = in_array('--einmal', $cr_argv, true);
$cr_takt = (int) $cr_cfg['abfragetakt'];
if (!in_array($cr_takt, array(10, 15, 20, 30, 60), true)) { $cr_takt = 60; }
$cr_runden = $cr_einmal ? 1 : max(1, (int) floor(60 / $cr_takt));
$cr_t0 = microtime(true);

if ((string) $cr_cfg['drucker_host'] === '') {
    // Ohne Adresse gibt es nichts zu messen; das Lebenszeichen sagt OK=0, damit Loxone nichts Altes fuer frisch haelt.
    $cr_lauf = cr_lauf_setzen(false, 'KEINE_ADRESSE', 0);
    cr_mqtt_lebenszeichen($cr_lauf);
    $cr_runden = 0;
}
if (!empty($cr_cfg['mqtt_enabled'])) {
    cr_abo_datei(cr_mqtt_thema_saeubern($cr_cfg['mqtt_topic']), true);
}

for ($cr_i = 0; $cr_i < $cr_runden; $cr_i++) {
    if ($cr_i > 0) {
        $cr_ziel = $cr_t0 + $cr_i * $cr_takt;
        $cr_warte = $cr_ziel - microtime(true);
        if ($cr_ziel - $cr_t0 > CR_LAUF_FRIST) { break; }
        if ($cr_warte > 0) { usleep((int) round($cr_warte * 1000000)); }
    }
    list($cr_lauf, $cr_st) = cr_durchgang();
    if (!empty($cr_cfg['mqtt_enabled'])) {
        if ($cr_st !== null) { cr_mqtt_senden($cr_st); }
        cr_mqtt_lebenszeichen($cr_lauf);
    }
}
if (empty($cr_cfg['mqtt_enabled'])) { @unlink(cr_tmpdir() . '/mqtt_gesendet.json'); }

/* Waechter der Befehlsbruecke (gemeinsames Modul): startet sie, wenn die Haken es sagen und sie nicht laeuft,
 * hoechstens alle 600 s, nie waehrend eines Updates; sendet <praefix>/status/befehle. */
$cr_bb = __DIR__ . '/befehlsbruecke.php';
if (!$cr_einmal && is_file($cr_bb)) {
    if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
    require_once $cr_bb;
    list($cr_bbs, $cr_bbt) = bb_waechter();
    if ($cr_bbs !== null) {
        cr_log_if_changed('befehlsbruecke', 'Befehlsbruecke: ' . ($cr_bbs === -1 ? $cr_bbt : ($cr_bbs === 1 ? 'laeuft' : 'aus (Haken)')));
    }
}

echo "OK\n";
flock($cr_lock, LOCK_UN);
fclose($cr_lock);
exit(0);
