<?php
/**
 * HomePod4Lox - Konfiguration vervollstaendigen, Kommandozeile (seit 0.9.4).
 *
 * Aufruf:  php hp_vervollstaendigen.php <pluginordner>
 * Aufgerufen von postupgrade.sh nach dem Wegraeumen der Marke. Das Plugin hat keinen Dauerdienst und keinen
 * Minutentakt; ohne diesen Aufruf kaemen fehlende Schluessel erst beim naechsten Oeffnen der Oberflaeche in die Datei.
 * Geschrieben wird ausschliesslich ueber hp_config_vervollstaendigen() in hp_lib.php, hier ohne Token zu erzeugen.
 * Bauform BLE-Scanner 1.3.40 (bl_vervollstaendigen.php).
 *
 * Ausgabe genau eine Zeile, nur Schluesselnamen, nie ein Wert:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler|ohne_wurzel>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<NUR_KOMMANDOZEILE|AUFRUF|BIBLIOTHEK>
 * Rueckgabe 0 bei ruht, ohne_datei, ohne_merkwort, vollstaendig, ergaenzt; sonst 1.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=NUR_KOMMANDOZEILE\n";
    exit(1);
}
$hp_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $hp_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
/* Die Bibliothek: installiert unter <home>/webfrontend/html/plugins/<ordner>/, im ausgepackten Archiv unter
 * webfrontend/html/. Welche Lage gilt, entscheidet der eigene Ablageort. */
if (basename(dirname(__DIR__)) === 'plugins') {
    $hp_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/hp_lib.php';
} else {
    $hp_lib = dirname(__DIR__) . '/webfrontend/html/hp_lib.php';
}
if (!is_file($hp_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=BIBLIOTHEK\n";
    exit(1);
}
putenv('LBPPLUGINDIR=' . $hp_o);
require_once $hp_lib;

$hp_e = array('stand' => 'fehler', 'schluessel' => array());
hp_config_vervollstaendigen(hp_config(false), false, $hp_e);
echo 'VERVOLLSTAENDIGEN;STAND=' . $hp_e['stand'] . ';SCHLUESSEL=' . implode(',', $hp_e['schluessel']) . "\n";
exit(in_array($hp_e['stand'], array('ruht', 'ohne_datei', 'ohne_merkwort', 'vollstaendig', 'ergaenzt'), true) ? 0 : 1);
