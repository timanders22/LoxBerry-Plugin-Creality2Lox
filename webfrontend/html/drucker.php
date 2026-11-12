<?php
/**
 * Creality2Lox - Endpunkt fuer den Miniserver (unangemeldeter Bereich, /plugins/<ordner>/drucker.php).
 *
 *   ?selftest=1&token=T          Selbsttest: 200 SELFTEST;OK=1;TOKEN=OK | 403 ERR=TOKEN | 403 ERR=KEIN_TOKEN_EINGERICHTET
 *   ?cmd=NAME[&wert=0|1]&token=T Befehl (pause, fortsetzen, abbrechen, notaus, licht) - nur mit Haken
 *                                "Befehle ueber HTTP annehmen" und Freigabe der Stufe; NICHT am Geraet erprobt
 *   (ohne Parameter)             Antwortzeile CREALITY;OK=..;... - nur mit Haken "HTTP-Lesezweig"
 *   ?json=1                      dieselben Werte als JSON, mit den Textfeldern
 *
 * Abfragende Aufrufe bleiben ohne Token (Regeln/05); der Lesezweig ist ab Werk aus. Der Endpunkt fragt den Drucker
 * NICHT selbst ab, sondern liest den Stand des Minutenlaufs. Gibt es keinen gemessenen Stand oder antwortet der
 * Drucker im letzten Lauf nicht, antwortet er mit HTTP 503 und ohne Werte (Regeln/07 Abschnitt 6).
 * Dieser Endpunkt legt nichts an (cr_config_erzeugen_erlauben(false)); Parameter nur aus $_GET.
 */
if (!is_file(__DIR__ . '/cr_lib.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CREALITY;OK=0;ERR=BIBLIOTHEK_FEHLT\n";
    exit;
}
require_once __DIR__ . '/cr_lib.php';
cr_config_erzeugen_erlauben(false);

header('Content-Type: text/plain; charset=utf-8');

/** Parameter zentral einsammeln: erst is_string, dann Laengengrenze. Ein Feld (?x[]=) zaehlt als ungueltig. */
function cr_ep_param($name, $max = 64)
{
    if (!isset($_GET[$name])) { return null; }
    if (!is_string($_GET[$name]) || strlen($_GET[$name]) > $max) { return false; }
    return $_GET[$name];
}

/** -1 kein Token eingerichtet, 0 falsch, 1 richtig. hash_equals('', '') faengt der erste Zweig ab. */
function cr_ep_token_lage()
{
    $cfg = cr_config(false);
    $soll = isset($cfg['aktionstoken']) && is_string($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    if ($soll === '') { return -1; }
    $ist = cr_ep_param('token', 200);
    return (is_string($ist) && hash_equals($soll, $ist)) ? 1 : 0;
}

function cr_ep_protokoll($was)
{
    $wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:\.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '?';
    cr_log('Endpunkt (' . $wer . '): ' . $was);
}

foreach (array('selftest', 'cmd', 'wert', 'token', 'json') as $cr_p) {
    if (cr_ep_param($cr_p, $cr_p === 'token' ? 200 : 64) === false) {
        http_response_code(400);
        cr_ep_protokoll('abgewiesen, Parameter ' . $cr_p . ' ungueltig');
        echo "CREALITY;OK=0;ERR=PARAMETER\n";
        exit;
    }
}

if (cr_ep_param('selftest') !== null) {
    $cr_lage = cr_ep_token_lage();
    if ($cr_lage === -1) { http_response_code(403); echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n"; exit; }
    if ($cr_lage !== 1) { http_response_code(403); cr_ep_protokoll('Selbsttest mit falschem Token'); echo "SELFTEST;OK=0;ERR=TOKEN\n"; exit; }
    echo 'SELFTEST;OK=1;TOKEN=OK;FASSUNG=' . preg_replace('/[^0-9.]/', '', cr_pluginversion()) . "\n";
    exit;
}

if (cr_ep_param('cmd') !== null) {
    $cr_lage = cr_ep_token_lage();
    if ($cr_lage !== 1) {
        http_response_code(403);
        cr_ep_protokoll('Befehl abgewiesen (' . ($cr_lage === -1 ? 'kein Token eingerichtet' : 'Token falsch') . ')');
        echo 'CMD;OK=0;ERR=' . ($cr_lage === -1 ? 'KEIN_TOKEN_EINGERICHTET' : 'TOKEN') . "\n";
        exit;
    }
    $cr_wert = cr_ep_param('wert');
    $cr_e = cr_befehl_ausfuehren((string) cr_ep_param('cmd'), $cr_wert === null ? '' : (string) $cr_wert, 'http');
    cr_ep_protokoll('Befehl ' . preg_replace('/[^a-z]/', '', (string) cr_ep_param('cmd')) . ' -> HTTP ' . $cr_e['http']);
    if ($cr_e['http'] !== 200) { http_response_code($cr_e['http']); }
    echo $cr_e['zeile'];
    exit;
}

$cr_cfg = cr_config(false);
if (empty($cr_cfg['endpunkt_ein'])) {
    cr_log_if_changed('endpunkt_aus', 'Lesezweig abgewiesen (endpunkt_ein = 0) - einschalten im Reiter Einbindung in Loxone');
    http_response_code(403);
    echo "CREALITY;OK=0;GRUND=LESEZWEIG_AUS\n";
    exit;
}

$cr_lauf = cr_lauf_lesen();
$cr_st = cr_zustand_lesen();
if ($cr_st === null || !$cr_lauf['ok'] || cr_lauf_alter() < 0 || cr_lauf_alter() > cr_lauf_grenze()) {
    $cr_grund = $cr_st === null ? 'NOCH_NICHT_GEMESSEN' : (!$cr_lauf['ok'] ? preg_replace('/[^A-Z0-9_]/', '', $cr_lauf['grund']) : 'LAUF_STEHT');
    http_response_code(503);
    echo 'CREALITY;OK=0;ALTER=' . cr_lauf_alter() . ';ZAEHLER=' . (int) $cr_lauf['zaehler'] . ';GRUND=' . $cr_grund . "\n";
    exit;
}

if (cr_ep_param('json') !== null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(cr_werte($cr_st, $cr_lauf), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit;
}
echo cr_zeile($cr_st, $cr_lauf) . "\n";
