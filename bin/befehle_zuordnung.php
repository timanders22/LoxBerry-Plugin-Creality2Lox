<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Creality2Lox.
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/)
 * abonniert <praefix>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE Funktion
 * wie der HTTP-Endpunkt drucker.php - cr_befehl_ausfuehren() in cr_lib.php, mit Quelle 'mqtt' - und gibt array(ok,
 * grund) fuer die Quittung zurueck. Ein Token gibt es auf diesem Weg nicht; an seiner Stelle stehen die Haken
 * "Befehle ueber MQTT annehmen" und, fuer die eingreifenden Befehle, "Eingreifende Befehle ueber MQTT annehmen"
 * (befehle_mqtt_steuern). Beide ab Werk aus. Dazu gilt in cr_befehl_ausfuehren() die Freigabe der Stufe
 * (freigabe_licht, freigabe_pause, freigabe_abbruch, freigabe_notaus), ebenfalls ab Werk aus.
 *
 * Die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts. Wie der unangemeldete Endpunkt legt
 * die Bruecke nichts an: einstellungen() liest mit cr_config(false), tun() schaltet die Selbstheilung ab.
 *
 * Die fuenf Befehle (NICHT am Geraet erprobt):
 *   puls      pause, fortsetzen, abbrechen, notaus (nur "1")   - eingreifend
 *   schalter  licht (1 ein, 0 aus)                              - nicht eingreifend
 * Sperrzeiten der Bruecke: pause/fortsetzen/abbrechen/notaus 5 s (der Doppeldruck), licht 0 s (die 5-s-Bremse des
 * Plugins fuer gleichen Befehl und gleichen Wert bleibt die eine Pruefung).
 */
if (!function_exists('cr_befehl_ausfuehren')) {
    $cr_kand = array();
    $cr_kand[] = 'REPLACELBPHTMLDIR/cr_lib.php';
    $cr_kand[] = dirname(__DIR__) . '/webfrontend/html/cr_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $cr_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/cr_lib.php';
    }
    foreach ($cr_kand as $cr_k) {
        if (strpos($cr_k, 'REPLACE') !== 0 && is_file($cr_k)) {
            require_once $cr_k;
            break;
        }
    }
}
$cr_grund_puls = 'Ein zweiter Druck auf denselben Befehl binnen 5 s ist der Doppeldruck; danach soll er wirken.';
$cr_grund_licht = 'Die 5-s-Bremse des Plugins fuer gleichen Befehl und gleichen Wert bleibt die eine Pruefung.';
$cr_tun = function ($cmd, $art) {
    return function ($w, $t) use ($cmd, $art) {
        cr_config_erzeugen_erlauben(false);
        return cr_befehl_quittung(cr_befehl_ausfuehren($cmd, $art === 'puls' ? '' : (string) $w, 'mqtt'));
    };
};
$cr_befehle = array(
    'pause'      => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 5, 'begruendung' => $cr_grund_puls,
                          'titel' => '3D-Drucker Pause (MQTT)', 'kachel' => 'BEFEHL.K_PAUSE',
                          'quittung' => 'ausgefuehrt', 'tun' => $cr_tun('pause', 'puls')),
    'fortsetzen' => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 5, 'begruendung' => $cr_grund_puls,
                          'titel' => '3D-Drucker fortsetzen (MQTT)', 'kachel' => 'BEFEHL.K_FORTSETZEN',
                          'quittung' => 'ausgefuehrt', 'tun' => $cr_tun('fortsetzen', 'puls')),
    'abbrechen'  => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 5, 'begruendung' => $cr_grund_puls,
                          'titel' => '3D-Drucker Druck abbrechen (MQTT)', 'kachel' => 'BEFEHL.K_ABBRECHEN',
                          'quittung' => 'ausgefuehrt', 'tun' => $cr_tun('abbrechen', 'puls')),
    'notaus'     => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 5, 'begruendung' => $cr_grund_puls,
                          'titel' => '3D-Drucker Notaus (MQTT)', 'kachel' => 'BEFEHL.K_NOTAUS',
                          'quittung' => 'ausgefuehrt', 'tun' => $cr_tun('notaus', 'puls')),
    'licht'      => array('art' => 'schalter', 'eingreifend' => false, 'sperre_s' => 0, 'begruendung' => $cr_grund_licht,
                          'titel' => '3D-Drucker Licht (MQTT)', 'kachel' => 'BEFEHL.K_LICHT',
                          'quittung' => 'ausgefuehrt', 'tun' => $cr_tun('licht', 'schalter')),
);
return array(
    'fassung' => 1,
    'linie'   => 'creality2lox',
    'plugin'  => '3D-Drucker',
    'geladen' => function_exists('cr_befehl_ausfuehren') && function_exists('cr_befehl_quittung')
                 && function_exists('cr_config'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        $c = cr_config(false);
        return array('praefix'     => cr_mqtt_thema_saeubern($c['mqtt_topic']),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_enabled']) && (string) $c['drucker_host'] !== '',
                     'eingreifend' => !empty($c['befehle_mqtt_steuern']),
                     'mqtt_ein'    => !empty($c['mqtt_enabled']));
    },
    'protokoll' => function ($stufe, $text) {
        cr_log(($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'befehle' => $cr_befehle,
);
