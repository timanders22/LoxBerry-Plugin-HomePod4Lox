<?php
/**
 * HomePod4Lox - Sprachausgabe fuer andere Plugins (unangemeldeter Bereich), Bauart Chromecast 4 Lox NG:
 *   ?aktion=status                       frei, lesend, ohne Netz
 *   ?selftest=1&token=T                  nur lokal: prueft das Token, spricht nichts
 *   aktion=sprechen&token=T&text=..[&geraet=..][&laut=0-100][&dringend=0|1]   nur lokal, POST empfohlen
 *   aktion=geraete&token=T               nur lokal: Namen, keine Adressen
 * Antwort: eine Zeile text/plain, gesendet = HTTP 200 und Zeilenanfang SPRECHEN;OK=1. Nur GET und POST, nie $_REQUEST.
 * Dieser Endpunkt legt nie eine Konfiguration an und schreibt erst nach der Tokenpruefung.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

function hp_ende_roh($http, $zeile)
{
    if (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code((int) $http);
    }
    echo $zeile . "\n";
    exit;
}

ob_start();
try {
    if (!is_file(__DIR__ . '/hp_lib.php')) {
        hp_ende_roh(500, 'HOMEPOD4LOX;OK=0;GRUND=BIBLIOTHEK_FEHLT');
    }
    require_once __DIR__ . '/hp_lib.php';

    $hp_par = array();
    $hp_falsch = array();
    foreach (array('aktion', 'token', 'geraet', 'text', 'laut', 'ssml', 'dringend', 'selftest') as $hp_k) {
        $hp_w = null;
        if (isset($_POST[$hp_k])) { $hp_w = $_POST[$hp_k]; } elseif (isset($_GET[$hp_k])) { $hp_w = $_GET[$hp_k]; }
        if ($hp_w === null) { continue; }
        if (!is_string($hp_w) || strlen($hp_w) > 8000) { $hp_falsch[] = $hp_k; continue; }
        $hp_par[$hp_k] = $hp_w;
    }
    $hp_wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    $hp_aktion = isset($hp_par['aktion']) ? $hp_par['aktion'] : (!empty($hp_par['selftest']) ? 'selftest' : 'status');
    $hp_kopf = array('status' => 'HOMEPOD4LOX', 'selftest' => 'SELFTEST', 'sprechen' => 'SPRECHEN', 'geraete' => 'GERAET');
    if (!isset($hp_kopf[$hp_aktion])) {
        hp_ende_roh(400, 'HOMEPOD4LOX;OK=0;GRUND=AKTION');
    }
    $hp_K = $hp_kopf[$hp_aktion];
    if ($hp_falsch) {
        // token[] oder text[] wird abgewiesen, nie umgewandelt
        $hp_tf = in_array('token', $hp_falsch, true);
        hp_ende_roh($hp_tf ? 403 : 400, hp_zeile($hp_K, array('OK' => 0, 'GRUND' => $hp_tf ? 'TOKEN' : 'PARAMETER')));
    }
    $hp_cfg = hp_config(false);

    if ($hp_aktion === 'status') {
        hp_ende_roh(200, hp_zeile($hp_K, hp_status($hp_cfg)));
    }
    if (!hp_ist_lokal($hp_wer)) {
        hp_ende_roh(403, hp_zeile($hp_K, array('OK' => 0, 'GRUND' => 'NUR_LOKAL')));
    }
    $hp_soll = (string) $hp_cfg['sprechtoken'];
    if ($hp_soll === '') {
        hp_ende_roh(403, hp_zeile($hp_K, array('OK' => 0, 'GRUND' => 'KEIN_TOKEN_EINGERICHTET')));
    }
    $hp_ist = isset($hp_par['token']) ? $hp_par['token'] : '';
    if ($hp_ist === '' || !hash_equals($hp_soll, $hp_ist)) {
        hp_ende_roh(403, hp_zeile($hp_K, array('OK' => 0, 'GRUND' => 'TOKEN')));
    }
    if ($hp_aktion === 'selftest') {
        hp_ende_roh(200, hp_zeile('SELFTEST', array('OK' => 1, 'TOKEN' => 'OK', 'SPRECHEN' => (string) $hp_cfg['sprechen_ein'] === '1' ? 1 : 0,
            'DIENST' => hp_bibliothek_da() ? 1 : 0, 'GRUND' => '-')));
    }
    if ($hp_aktion === 'geraete') {
        hp_ende_roh(200, implode("\n", hp_geraete_zeilen($hp_cfg)));
    }
    list($hp_h, $hp_f) = hp_sprechen($hp_par, $hp_cfg);
    hp_ende_roh($hp_h, hp_zeile($hp_K, $hp_f));
} catch (Throwable $hp_fehler) {
    error_log('HomePod4Lox, Endpunkt: ' . $hp_fehler->getMessage() . ' (' . basename($hp_fehler->getFile()) . ':' . $hp_fehler->getLine() . ')');
    hp_ende_roh(500, 'HOMEPOD4LOX;OK=0;GRUND=FEHLER');
}
