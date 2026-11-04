<?php
/**
 * HomePod4Lox - Bibliothek (Oberflaeche und Endpunkt). Kein Dauerdienst: der Endpunkt legt einen Auftrag ab und startet
 * bin/hp_ausgabe.py --lauf; ohne Auftrag laeuft nichts. Der unangemeldete Endpunkt legt keine Konfiguration an.
 * Ansagetexte stehen nur im Auftrag (data/warteschlange, 0600), nie im Protokoll. Adressen nur in data/geraete.json (0600).
 * Praefix 'hp_', weil LBWeb::lbheader() SDK-Globale setzt ($cfg, $p). PHP 7.4, 8.4, 8.5.
 */

if (!function_exists('hp_e')) {
    function hp_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Feste Groessen an EINER Stelle. */
define('HP_TEXT_MAX', 1000);
define('HP_GLEICH_S', 30);            // gleicher Text an dasselbe Ziel: nicht wiederholen
define('HP_SCHLANGE_MAX', 20);        // Auftraege in der Warteschlange
define('HP_PROBE_S', 1.5);            // Erreichbarkeit je Geraet
define('HP_PROBE_GESAMT_S', 4.0);     // und insgesamt; danach entscheidet der Lauf
define('HP_SUCHE_FRIST_S', 6);        // Geraetesuche vom Knopf
define('HP_SUCHE_ANSTOSS_S', 300);    // Geraetesuche im Hintergrund hoechstens alle 5 min
define('HP_LOG_MAX', 262144);
define('HP_LOG_REST', 1500);
define('HP_MARKE_MAX_S', 3600);       // Upgrade-Marke: aelter zaehlt nicht

/* Der Installer ersetzt nur in bestimmten Dateien; die Wurzel wird gemessen, nicht verdrahtet. */
function hp_wurzel_ermitteln()
{
    $d = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend') && is_file($d . '/config/system/general.json')) {
            return $d;
        }
        $eltern = dirname($d);
        if ($eltern === $d) { break; }
        $d = $eltern;
    }
    return '';
}

function hp_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins') && is_file($h . '/config/system/general.json')) {
        return rtrim($h, '/');
    }
    return hp_wurzel_ermitteln();
}

/**
 * Pfade der Anlage nur, wenn diese Bibliothek dort installiert liegt oder LBHOMEDIR UND LBPPLUGINDIR ausdruecklich gesetzt
 * sind (Pruefwerkzeuge). Sonst Archivmodus: alles im eigenen Ordner.
 */
function hp_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = hp_lbhome();
    $dir = basename(dirname(__FILE__));
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true));
    if ($lbp_gilt) {
        $dir = $lbp;
    } elseif (in_array($dir, array('', '.', '/', 'html', 'bin', 'plugins'), true)) {
        $dir = 'homepod4lox';
    }
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home !== '') {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/homepod4lox.cfg',
            'zweit'     => $home . '/config/plugins/' . $dir . '.backup.cfg',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'marke'     => $home . '/data/plugins/' . $dir . '.upgrade_laeuft',
            'venv'      => $home . '/data/plugins/' . $dir . '.venv',
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/homepod4lox.log',
            'lang'      => $home . '/templates/plugins/' . $dir . '/lang',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/homepod4lox.cfg',
            'zweit'     => $basis . '/config/homepod4lox.backup.cfg',
            'datadir'   => $basis . '/data',
            'marke'     => $basis . '/data.upgrade_laeuft',
            'venv'      => $basis . '/data.venv',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/homepod4lox.log',
            'lang'      => $basis . '/templates/lang',
        );
    }
    return $p;
}

/* ==================================================================
 * Sprache
 * ================================================================== */

function hp_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function hp_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $pfad = hp_paths()['lang'];
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . hp_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', (string) $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : (string) $schluessel;
}

/* ==================================================================
 * Protokoll
 * ================================================================== */

function hp_log($text)
{
    $p = hp_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > HP_LOG_MAX) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -HP_LOG_REST);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], date('Y-m-d H:i:s') . ' INFO    ' . $text . "\n", FILE_APPEND);
}

/** Fehlerausgabe der Hintergrundlaeufe: liegt auf der Ramdisk, deshalb gekappt. */
function hp_lauf_err_kappen()
{
    $f = hp_paths()['logdir'] . '/lauf.err';
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > HP_LOG_MAX) {
        $rest = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -HP_LOG_REST);
        @file_put_contents($f, implode("\n", $rest) . "\n");
    }
}

function hp_lauf_err_ende($anzahl)
{
    $f = hp_paths()['logdir'] . '/lauf.err';
    if (!is_file($f) || !is_readable($f)) {
        return array();
    }
    $z = file($f, FILE_IGNORE_NEW_LINES);
    return is_array($z) ? array_slice($z, -$anzahl) : array();
}

function hp_log_ende($anzahl)
{
    $f = hp_paths()['log'];
    if (!is_file($f) || !is_readable($f)) {
        return array();
    }
    $z = file($f, FILE_IGNORE_NEW_LINES);
    return is_array($z) ? array_slice($z, -$anzahl) : array();
}

/* ==================================================================
 * Konfiguration: Zeilen schluessel="wert" wie Chromecast 4 Lox NG, damit das Sprachmodul das Sprechtoken lesen kann
 * ================================================================== */

function hp_vorgaben()
{
    return array(
        'sprechen_ein'    => '1',       // ab Werk ein (Entscheidung des Hausherrn 01.11.2026); ohne Geraet geschieht nichts
        'sprechtoken'     => '',
        'sprechen_geraet' => '',
        'sprechen_stunde' => '60',
        'laut'            => '-1',      // -1: Lautstaerke des HomePod nicht verstellen
        'laut_max'        => '100',
        'quelle'          => 'lokal',   // lokal (espeak-ng) oder google
        'sprache'         => 'de',
        'vorlauf_ms'      => '500',
        'verfall_s'       => '120',
        'alle_airplay'    => '0',       // andere AirPlay-Lautsprecher anbieten
        'aktionstoken'    => '',
    );
}

/** Formular- und Sicherungspruefung: '' oder der Sprachschluessel der Beanstandung. Nie zurechtbiegen. */
function hp_wert_pruefen($k, $w)
{
    if (!is_string($w)) {
        return 'EINST.F_WERT';
    }
    switch ($k) {
        case 'sprechen_ein':
        case 'alle_airplay':
            return ($w === '0' || $w === '1') ? '' : 'EINST.F_WERT';
        case 'sprechen_stunde':
            return (preg_match('/^[0-9]{2,3}\z/', $w) && (int) $w >= 10 && (int) $w <= 240) ? '' : 'EINST.F_STUNDE';
        case 'laut':
            // Wie im Sprachmodul: 0 waere stumm und meldete trotzdem OK=1.
            return ($w === '-1' || (preg_match('/^[0-9]{1,3}\z/', $w) && (int) $w >= 1 && (int) $w <= 100)) ? '' : 'EINST.F_LAUT';
        case 'laut_max':
            return (preg_match('/^[0-9]{1,3}\z/', $w) && (int) $w >= 1 && (int) $w <= 100) ? '' : 'EINST.F_LAUT_MAX';
        case 'quelle':
            return in_array($w, array('lokal', 'google'), true) ? '' : 'EINST.F_QUELLE';
        case 'sprache':
            return preg_match('/^[a-z]{2,3}(-[a-z0-9]{1,8})?\z/', $w) ? '' : 'EINST.F_SPRACHE';
        case 'vorlauf_ms':
            return (preg_match('/^[0-9]{1,4}\z/', $w) && (int) $w <= 3000) ? '' : 'EINST.F_VORLAUF';
        case 'verfall_s':
            return (preg_match('/^[0-9]{2,3}\z/', $w) && (int) $w >= 30 && (int) $w <= 600) ? '' : 'EINST.F_VERFALL';
        case 'sprechen_geraet':
            return ($w === '' || hp_geraetename_ok($w)) ? '' : 'EINST.F_GERAET';
        case 'sprechtoken':
            return ($w === '' || hp_token_taugt($w)) ? '' : 'EINST.F_TOKEN';
        case 'aktionstoken':
            return ($w === '' || preg_match('/^[a-f0-9]{32,128}\z/', $w)) ? '' : 'EINST.F_WERT';
    }
    return 'EINST.F_WERT';
}

function hp_geraetename_ok($w)
{
    return is_string($w) && $w !== '' && strlen($w) <= 200 && preg_match('//u', $w) === 1 && !preg_match('/[\x00-\x1F\x7F"]/', $w)
        && trim($w) === $w;
}

function hp_token_taugt($t)
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_-]{16,128}\z/', $t) === 1;
}

/** Rein: Zeilen zu Feld. Fremdes wird ignoriert; was fehlt, ergaenzt der Aufrufer aus den Vorgaben. */
function hp_cfg_aus_text($roh)
{
    $aus = array();
    if (!is_string($roh)) {
        return $aus;
    }
    foreach (preg_split('/\r?\n/', $roh) as $z) {
        if (preg_match('/^\s*([a-z_]+)\s*=\s*"?(.*?)"?\s*\z/', $z, $t)) {
            $aus[$t[1]] = $t[2];
        }
    }
    return $aus;
}

function hp_cfg_als_text(array $cfg)
{
    $z = array('# HomePod4Lox - Einstellungen. Bitte ueber die Oberflaeche aendern. Enthaelt Token: Rechte 0600.');
    foreach (array_keys(hp_vorgaben()) as $k) {
        $z[] = $k . '="' . str_replace(array('"', "\r", "\n"), '', (string) (isset($cfg[$k]) ? $cfg[$k] : '')) . '"';
    }
    return implode("\n", $z) . "\n";
}

/** ok, leer (Datei fehlt oder traegt nichts), kaputt (Zeichen, aber kein Schluessel), zweitschrift (eben wiederhergestellt). */
function hp_config_lage($setzen = null)
{
    static $lage = 'ok';
    if ($setzen !== null) {
        $lage = $setzen;
    }
    return $lage;
}

/**
 * Lesen und vervollstaendigen; ein fehlerhafter Wert wird durch die Vorgabe ersetzt und das gemeldet (hp_config_lage).
 * $heilen=false schreibt nichts (unangemeldeter Endpunkt).
 */
function hp_config($heilen = true)
{
    $p = hp_paths();
    $vorg = hp_vorgaben();
    $roh = is_file($p['config']) ? @file_get_contents($p['config']) : '';
    $roh = $roh === false ? '' : $roh;
    $w = hp_cfg_aus_text($roh);
    $traegt = array_intersect_key($w, $vorg);
    hp_config_lage($traegt ? 'ok' : (trim($roh) === '' ? 'leer' : 'kaputt'));
    if (!isset($traegt['aktionstoken']) && !isset($traegt['sprechtoken']) && $heilen && is_file($p['zweit'])) {
        // Selbstheilung nach Inhalt: nur wenn die Zweitschrift wirklich Einstellungen traegt
        $z = hp_cfg_aus_text((string) @file_get_contents($p['zweit']));
        if (array_intersect_key($z, $vorg) && (!empty($z['aktionstoken']) || !empty($z['sprechtoken']))) {
            $traegt = array_intersect_key($z, $vorg);
            hp_config_lage('zweitschrift');
            hp_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
            hp_config_speichern($traegt + $vorg);
        }
    }
    $cfg = $vorg;
    foreach ($vorg as $k => $v) {
        if (isset($traegt[$k]) && hp_wert_pruefen($k, $traegt[$k]) === '') {
            $cfg[$k] = $traegt[$k];
        }
    }
    return $cfg;
}

function hp_datei_schreiben($pfad, $inhalt, $rechte = 0600)
{
    $tmp = $pfad . '.neu' . getmypid();
    // Rechte VOR dem Inhalt: die Zweitschrift liegt in einem Ordner, den alle lesen duerfen.
    if (@file_put_contents($tmp, '') === false) {
        return false;
    }
    if (!@chmod($tmp, $rechte) || @file_put_contents($tmp, $inhalt, LOCK_EX) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function hp_config_speichern(array $cfg)
{
    $p = hp_paths();
    if (!is_dir($p['configdir']) && !@mkdir($p['configdir'], 0700, true)) {
        return false;
    }
    $text = hp_cfg_als_text($cfg);
    if (!hp_datei_schreiben($p['config'], $text)) {
        return false;
    }
    hp_datei_schreiben($p['zweit'], $text);
    return true;
}

function hp_token_erzeugen()
{
    return bin2hex(random_bytes(16));
}

/** Beide Token entstehen einmal, nur in der angemeldeten Oberflaeche und in postinstall.sh, nie am Endpunkt. */
function hp_config_vervollstaendigen(array $cfg)
{
    $p = hp_paths();
    if ($p['home'] === '') {
        return $cfg;
    }
    $roh = is_file($p['config']) ? hp_cfg_aus_text((string) @file_get_contents($p['config'])) : array();
    $neu = false;
    if ((string) $cfg['aktionstoken'] === '') {
        $cfg['aktionstoken'] = hp_token_erzeugen();
        $neu = true;
    }
    if (!array_key_exists('sprechtoken', $roh)) {
        $cfg['sprechtoken'] = hp_token_erzeugen();
        $neu = true;
    }
    if ($neu) {
        hp_config_speichern($cfg);
    }
    return $cfg;
}

function hp_formtoken(array $cfg)
{
    $t = trim((string) $cfg['aktionstoken']);
    return $t === '' ? '' : hash_hmac('sha256', 'formular-v1', $t);
}

function hp_fassung()
{
    $p = hp_paths();
    foreach (array($p['home'] !== '' ? $p['home'] . '/config/plugins/' . $p['plugin'] . '/plugin.cfg' : '',
                   dirname(dirname(__DIR__)) . '/plugin.cfg') as $f) {
        if ($f !== '' && is_file($f) && preg_match('/^VERSION=(.+)$/m', (string) @file_get_contents($f), $t)) {
            return trim($t[1]);
        }
    }
    if ($p['home'] !== '') {
        $db = $p['home'] . '/data/system/plugindatabase.json';
        $d = is_file($db) ? json_decode((string) @file_get_contents($db), true) : null;
        if (is_array($d) && isset($d['plugins']) && is_array($d['plugins'])) {
            foreach ($d['plugins'] as $e) {
                if (is_array($e) && isset($e['folder'], $e['version']) && $e['folder'] === $p['plugin']) {
                    return (string) $e['version'];
                }
            }
        }
    }
    return '';
}

/* ==================================================================
 * Geraete und Bibliothek
 * ================================================================== */

function hp_geraete_alle()
{
    $f = hp_paths()['datadir'] . '/geraete.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return (is_array($d) && isset($d['geraete']) && is_array($d['geraete'])) ? $d['geraete'] : array();
}

function hp_geraete_stand()
{
    $f = hp_paths()['datadir'] . '/geraete.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return (is_array($d) && isset($d['stand'])) ? (int) $d['stand'] : 0;
}

/** Die Geraete, die das Plugin anbietet: HomePods, mit dem Haken auch andere AirPlay-Lautsprecher. */
function hp_geraete(array $cfg)
{
    $aus = array();
    foreach (hp_geraete_alle() as $g) {
        if (!is_array($g) || empty($g['id']) || !isset($g['name']) || !is_string($g['name'])) {
            continue;
        }
        if (!empty($g['homepod']) || (string) $cfg['alle_airplay'] === '1') {
            $aus[] = $g;
        }
    }
    return $aus;
}

function hp_name_klein($s)
{
    $s = trim((string) $s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Rein: Ziel(e) zu einem Namen. Rueckgabe array('ziele'=>Liste, 'fehlt'=>Name|'') Ein Name mit Komma wird zuerst als Ganzes
 * gesucht. Unbekannt: nie ein Rueckfall auf "alle".
 */
function hp_ziele_finden($wunsch, array $geraete)
{
    $wunsch = trim((string) $wunsch);
    if (hp_name_klein($wunsch) === 'alle') {
        return array('ziele' => $geraete, 'fehlt' => '');
    }
    $index = array();
    foreach ($geraete as $g) {
        $index[hp_name_klein($g['name'])] = $g;
    }
    $k = hp_name_klein($wunsch);
    if (isset($index[$k])) {
        return array('ziele' => array($index[$k]), 'fehlt' => '');
    }
    $ziele = array();
    foreach (explode(',', $wunsch) as $teil) {
        $k = hp_name_klein($teil);
        if ($k === '') {
            continue;
        }
        if (!isset($index[$k])) {
            return array('ziele' => array(), 'fehlt' => trim($teil));
        }
        $ziele[$index[$k]['id']] = $index[$k];
    }
    return array('ziele' => array_values($ziele), 'fehlt' => $ziele ? '' : $wunsch);
}

/** Eigene Quotierung: escapeshellarg streicht unter PHP 7.4 mit LC_CTYPE=C alles ausser ASCII. */
function hp_shell_arg($s)
{
    return "'" . str_replace("'", "'\\''", (string) $s) . "'";
}

function hp_python()
{
    return hp_paths()['venv'] . '/bin/python3';
}

/** Liegt die Bibliothek da? Nur der Blick auf die Platte; geladen wird sie im Reiter Test. */
function hp_bibliothek_da()
{
    if (!is_file(hp_python())) {
        return false;
    }
    return (bool) glob(hp_paths()['venv'] . '/lib/python*/site-packages/pyatv/__init__.py');
}

function hp_upgrade_laeuft()
{
    $m = hp_paths()['marke'];
    if (!is_file($m)) {
        return false;
    }
    $t = trim((string) @file_get_contents($m));
    $jetzt = time();
    return ctype_digit($t) && $jetzt - (int) $t >= -300 && $jetzt - (int) $t <= HP_MARKE_MAX_S;
}

function hp_umgebung()
{
    $p = hp_paths();
    return 'env LBHOMEDIR=' . hp_shell_arg($p['home']) . ' LBPPLUGINDIR=' . hp_shell_arg($p['plugin']) . ' PYTHONIOENCODING=utf-8 ';
}

/** Lauf im Hintergrund starten: eigene Sitzung, nichts haengt am Webserver. true: gestartet. */
function hp_hintergrund($argumente)
{
    $p = hp_paths();
    if ($p['home'] === '' || !function_exists('exec') || !is_file(hp_python())) {
        return false;
    }
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    hp_lauf_err_kappen();
    $befehl = 'nohup setsid nice -n 5 ' . hp_umgebung() . hp_shell_arg(hp_python()) . ' -B '
        . hp_shell_arg($p['bindir'] . '/hp_ausgabe.py') . ' ' . $argumente
        . ' >/dev/null 2>>' . hp_shell_arg($p['logdir'] . '/lauf.err') . ' </dev/null &';
    $aus = array();
    $rc = 1;
    @exec($befehl, $aus, $rc);
    return $rc === 0;
}

/** Geraete im Hintergrund neu suchen, hoechstens alle 5 Minuten: etwa, wenn der HomePod eine neue Adresse bekam. */
function hp_suche_anstossen()
{
    $f = hp_paths()['datadir'] . '/suche_anstoss';
    $t = is_file($f) ? (int) @filemtime($f) : 0;
    if (time() - $t < HP_SUCHE_ANSTOSS_S) {
        return false;
    }
    @touch($f);
    return hp_hintergrund('--suchen --frist ' . (int) HP_SUCHE_FRIST_S);
}

/** Blockierend mit Frist (Knoepfe im Reiter Test). Rueckgabe array(Rueckgabewert, erste Zeile). */
function hp_ausgabe_warten($argumente, $frist)
{
    $p = hp_paths();
    if ($p['home'] === '' || !function_exists('exec') || !is_file(hp_python())) {
        return array(127, 'BIBLIOTHEK;OK=0;GRUND=BIBLIOTHEK_FEHLT');
    }
    $aus = array();
    $rc = 1;
    @exec('timeout -k 3 ' . (int) $frist . ' ' . hp_umgebung() . hp_shell_arg(hp_python()) . ' -B '
        . hp_shell_arg($p['bindir'] . '/hp_ausgabe.py') . ' ' . $argumente . ' 2>/dev/null', $aus, $rc);
    return array($rc, isset($aus[0]) ? (string) $aus[0] : '');
}

/** Rein: Feldzeile KOPF;A=1;B=2 zu Feld. */
function hp_felder($zeile)
{
    $aus = array();
    foreach (explode(';', (string) $zeile) as $t) {
        $g = explode('=', $t, 2);
        if (count($g) === 2) {
            $aus[$g[0]] = $g[1];
        }
    }
    return $aus;
}

function hp_lauf_aktiv()
{
    $f = hp_paths()['datadir'] . '/lauf.sperre';
    if (!is_file($f)) {
        return false;
    }
    $h = @fopen($f, 'a');
    if (!$h) {
        return false;
    }
    $frei = @flock($h, LOCK_EX | LOCK_NB);
    if ($frei) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return !$frei;
}

function hp_letzte()
{
    $f = hp_paths()['datadir'] . '/letzte.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($d) ? $d : array();
}

/* ==================================================================
 * Endpunkt
 * ================================================================== */

function hp_ist_lokal($wer)
{
    return in_array($wer, array('127.0.0.1', '::1'), true);
}

function hp_zeile($kopf, array $f)
{
    $t = array($kopf);
    foreach ($f as $k => $v) {
        $t[] = $k . '=' . str_replace(array(';', "\r", "\n"), ' ', (string) $v);
    }
    return implode(';', $t);
}

/** Rein: darf in dieser Stunde noch gesprochen werden, ist der Text gleich? $stand wird fortgeschrieben. Rueckgabe GRUND|''. */
function hp_bremse(array &$stand, $schluessel, $grenze, $jetzt)
{
    $st = (isset($stand['stunde']) && is_array($stand['stunde'])) ? $stand['stunde'] : array();
    $gl = (isset($stand['gleich']) && is_array($stand['gleich'])) ? $stand['gleich'] : array();
    $st = array_values(array_filter($st, function ($t) use ($jetzt) { return is_int($t) && $jetzt - $t < 3600; }));
    $gl = array_filter($gl, function ($t) use ($jetzt) { return is_int($t) && $jetzt - $t < HP_GLEICH_S; });
    $stand['stunde'] = $st;
    $stand['gleich'] = $gl;
    if (isset($gl[$schluessel])) {
        return 'UNVERAENDERT';
    }
    if (count($st) >= $grenze) {
        return 'STUNDENGRENZE';
    }
    $stand['stunde'][] = $jetzt;
    $stand['gleich'][$schluessel] = $jetzt;
    return '';
}

/** Eine nicht eingereihte Ansage zaehlt nicht: sonst antwortete die Wiederholung OK=1 UNVERAENDERT, und nichts wuerde gesprochen. */
function hp_bremse_vergessen($datei, $schluessel, $zeit)
{
    $h = @fopen($datei, 'c+');
    if (!$h) {
        return;
    }
    flock($h, LOCK_EX);
    $stand = json_decode((string) stream_get_contents($h), true);
    if (is_array($stand)) {
        unset($stand['gleich'][$schluessel]);
        if (isset($stand['stunde']) && is_array($stand['stunde'])) {
            $i = array_search($zeit, $stand['stunde'], true);
            if ($i !== false) {
                array_splice($stand['stunde'], $i, 1);
            }
        }
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, (string) json_encode($stand));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
}

/** Die Erreichbarkeit der Ziele. Rein bis auf $probe (Adresse, Port, Frist -> bool). Rueckgabe array(erreichbar, Zahl offline). */
function hp_ziele_proben(array $ziele, $probe, $gesamt = HP_PROBE_GESAMT_S)
{
    $da = array();
    $weg = 0;
    $beginn = microtime(true);
    foreach ($ziele as $g) {
        if (microtime(true) - $beginn >= $gesamt) {
            $da[] = $g;     // Budget verbraucht: nicht als ausgefallen melden, was niemand gemessen hat - der Lauf entscheidet
            continue;
        }
        $a = isset($g['adresse']) ? (string) $g['adresse'] : '';
        $port = isset($g['port']) ? (int) $g['port'] : 0;
        if ($a !== '' && $port > 0 && call_user_func($probe, $a, $port, HP_PROBE_S)) {
            $da[] = $g;
        } else {
            $weg++;
        }
    }
    return array($da, $weg);
}

function hp_tcp_probe($adresse, $port, $frist)
{
    if (filter_var($adresse, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $h = @fsockopen(strpos($adresse, ':') !== false ? '[' . $adresse . ']' : $adresse, (int) $port, $en, $es, (float) $frist);
    if (!$h) {
        return false;
    }
    fclose($h);
    return true;
}

/** Auftrag ablegen (0600, atomar). Rueckgabe GRUND|''. */
function hp_auftrag_ablegen(array $auftrag, &$datei = null)
{
    $d = hp_paths()['datadir'] . '/warteschlange';
    if (!is_dir($d) && !@mkdir($d, 0700, true)) {
        return 'ORDNER';
    }
    $liste = glob($d . '/*.json');
    if (is_array($liste) && count($liste) >= HP_SCHLANGE_MAX) {
        return 'WARTESCHLANGE_VOLL';
    }
    $js = json_encode($auftrag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return 'SCHREIBFEHLER';
    }
    $name = sprintf('%012d_%s.json', (int) (microtime(true) * 10), bin2hex(random_bytes(4)));
    $datei = $d . '/' . $name;
    return hp_datei_schreiben($datei, $js) ? '' : 'SCHREIBFEHLER';
}

/**
 * Sprechen: dieselben Pruefungen und Antworten fuer Endpunkt und Testansage. Rueckgabe array(HTTP, Felder).
 * Der Text steht in keiner Antwort und in keinem Protokoll.
 */
function hp_sprechen(array $par, array $cfg, $probe = 'hp_tcp_probe')
{
    if (hp_upgrade_laeuft()) {
        return array(503, array('OK' => 0, 'GRUND' => 'AKTUALISIERUNG'));
    }
    if (hp_config_lage() === 'kaputt') {
        return array(503, array('OK' => 0, 'GRUND' => 'KONFIGURATION'));
    }
    if ((string) $cfg['sprechen_ein'] !== '1') {
        return array(409, array('OK' => 0, 'GRUND' => 'SPRECHEN_AUS'));
    }
    if (!isset($par['text'])) {
        return array(400, array('OK' => 0, 'GRUND' => 'TEXT_FEHLT'));
    }
    if (isset($par['ssml']) && $par['ssml'] !== '0') {
        return array(400, array('OK' => 0, 'GRUND' => 'SSML'));
    }
    $text = (string) $par['text'];
    if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $text)) {
        return array(400, array('OK' => 0, 'GRUND' => 'TEXT'));
    }
    $text = trim($text);
    if ($text === '' || $text === '0') {
        return array(200, array('OK' => 1, 'UEBERSPRUNGEN' => 1, 'GRUND' => 'TEXT_NULL'));
    }
    $laenge = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($laenge > HP_TEXT_MAX) {
        return array(400, array('OK' => 0, 'GRUND' => 'TEXT'));
    }
    if (strpbrk($text, '<>') !== false) {
        return array(400, array('OK' => 0, 'GRUND' => 'SSML_OHNE_SCHALTER'));
    }
    $laut = (int) $cfg['laut'];
    if (isset($par['laut']) && $par['laut'] !== '') {
        if (!preg_match('/^[0-9]{1,3}\z/', $par['laut']) || (int) $par['laut'] < 1 || (int) $par['laut'] > 100) {
            return array(400, array('OK' => 0, 'GRUND' => 'LAUT'));
        }
        $laut = (int) $par['laut'];
    }
    if ($laut >= 0) {
        $laut = min($laut, (int) $cfg['laut_max']);
    }
    if (isset($par['dringend']) && !in_array($par['dringend'], array('0', '1'), true)) {
        return array(400, array('OK' => 0, 'GRUND' => 'DRINGEND'));
    }
    $wunsch = isset($par['geraet']) ? (string) $par['geraet'] : '';
    if ($wunsch !== '' && (strlen($wunsch) > 2000 || preg_match('//u', $wunsch) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $wunsch))) {
        return array(400, array('OK' => 0, 'GRUND' => 'GERAET'));
    }
    $wunsch = trim($wunsch);
    if ($wunsch === '') {
        $wunsch = (string) $cfg['sprechen_geraet'];
    }
    $geraete = hp_geraete($cfg);
    if ($wunsch === '') {
        return array(400, array('OK' => 0, 'GRUND' => 'KEIN_GERAET'));
    }
    if (!$geraete) {
        return array(404, array('OK' => 0, 'GRUND' => 'KEINE_GERAETE'));
    }
    $f = hp_ziele_finden($wunsch, $geraete);
    if ($f['fehlt'] !== '' || !$f['ziele']) {
        return array(404, array('OK' => 0, 'GRUND' => 'GERAET_UNBEKANNT', 'NAME' => $f['fehlt']));
    }
    if (!hp_bibliothek_da()) {
        return array(503, array('OK' => 0, 'GRUND' => 'BIBLIOTHEK_FEHLT'));
    }
    // Was die letzte Suche ueber den Zugriff weiss: gesperrte Ziele fallen heraus, und das wird gesagt.
    $frei = array();
    $gesperrt = '';
    foreach ($f['ziele'] as $g) {
        if (isset($g['kopplung']) && !in_array($g['kopplung'], array('NotNeeded', 'Optional'), true)) {
            $gesperrt = (string) $g['name'];
            continue;
        }
        if (!empty($g['kennwort'])) {
            $gesperrt = (string) $g['name'];
            continue;
        }
        $frei[] = $g;
    }
    if (!$frei) {
        hp_suche_anstossen();     // die Einstellung am HomePod kann sich geaendert haben
        return array(409, array('OK' => 0, 'GRUND' => 'KOPPLUNG_NOETIG', 'NAME' => $gesperrt));
    }
    list($da, $weg) = hp_ziele_proben($frei, $probe);
    if (!$da) {
        hp_suche_anstossen();     // vielleicht nur eine neue Adresse
        return array(503, array('OK' => 0, 'OFFLINE' => $weg, 'GRUND' => 'GERAETE_OFFLINE'));
    }
    if ($weg > 0) {
        hp_suche_anstossen();     // ein Teil fehlt: auch der kann nur umgezogen sein, sonst bliebe er bis zur naechsten Suche draussen
    }
    $ids = array();
    foreach ($da as $g) {
        $ids[] = $g['id'];
    }
    sort($ids);
    $schluessel = hash('sha256', implode(',', $ids) . '|' . $laut . '|' . $text);
    $sf = hp_paths()['datadir'] . '/bremse.json';
    $d = hp_paths()['datadir'];
    if (!is_dir($d) && !@mkdir($d, 0700, true)) {
        return array(503, array('OK' => 0, 'GRUND' => 'ORDNER'));
    }
    $h = @fopen($sf, 'c+');
    if (!$h) {
        return array(503, array('OK' => 0, 'GRUND' => 'SCHREIBFEHLER'));
    }
    flock($h, LOCK_EX);
    $stand = json_decode((string) stream_get_contents($h), true);
    $stand = is_array($stand) ? $stand : array();
    $bremse_zeit = time();
    $bremse = hp_bremse($stand, $schluessel, (int) $cfg['sprechen_stunde'], $bremse_zeit);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, (string) json_encode($stand));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($sf, 0600);
    if ($bremse === 'UNVERAENDERT') {
        return array(200, array('OK' => 1, 'GERAETE' => 0, 'UNVERAENDERT' => 1, 'GRUND' => 'UNVERAENDERT'));
    }
    if ($bremse === 'STUNDENGRENZE') {
        return array(429, array('OK' => 0, 'GRUND' => 'STUNDENGRENZE'));
    }
    $ziele = array();
    foreach ($da as $g) {
        $ziele[] = array('id' => (string) $g['id'], 'name' => (string) $g['name'], 'adresse' => (string) (isset($g['adresse']) ? $g['adresse'] : ''));
    }
    $fehler = hp_auftrag_ablegen(array('id' => bin2hex(random_bytes(8)), 'zeit' => time(), 'verfall_s' => (int) $cfg['verfall_s'],
        'text' => $text, 'laut' => $laut, 'quelle' => (string) $cfg['quelle'], 'sprache' => (string) $cfg['sprache'],
        'vorlauf_ms' => (int) $cfg['vorlauf_ms'], 'ziele' => $ziele), $auftrag_datei);
    if ($fehler !== '') {
        hp_bremse_vergessen($sf, $schluessel, $bremse_zeit);
        return array(503, array('OK' => 0, 'GRUND' => $fehler));
    }
    if (!hp_hintergrund('--lauf')) {
        // Nicht liegen lassen: sonst spraeche der naechste Lauf diese Ansage zusaetzlich zur Wiederholung.
        @unlink($auftrag_datei);
        hp_bremse_vergessen($sf, $schluessel, $bremse_zeit);
        return array(503, array('OK' => 0, 'GRUND' => 'BIBLIOTHEK_FEHLT'));
    }
    return array(200, array('OK' => 1, 'GERAETE' => count($da), 'UNVERAENDERT' => 0, 'OFFLINE' => $weg + (count($f['ziele']) - count($frei)), 'GRUND' => 'EINGEREIHT'));
}

/**
 * Fuer bin/healthcheck: array(Status, Text). 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis. Nur aus Dateien, ohne Selbstheilung.
 * Die Texte nennen keine Geraetenamen, Adressen oder Token.
 */
function hp_gesundheit()
{
    if (hp_upgrade_laeuft()) {
        return array(6, hp_t('GESUND.UPGRADE'));
    }
    $cfg = hp_config(false);
    if (hp_config_lage() === 'kaputt') {
        return array(3, hp_t('GESUND.KONFIG'));
    }
    if (!hp_bibliothek_da()) {
        return array(3, hp_t('GESUND.BIBLIOTHEK'));
    }
    if ((string) $cfg['sprechen_ein'] !== '1') {
        return array(6, hp_t('GESUND.AUS'));
    }
    if (!hp_token_taugt((string) $cfg['sprechtoken'])) {
        return array(4, hp_t('GESUND.TOKEN'));
    }
    $n = count(hp_geraete($cfg));
    if ($n === 0) {
        return array(6, hp_t('GESUND.KEINE_GERAETE'));
    }
    $l = hp_letzte();
    if (isset($l['zeit']) && empty($l['ok'])) {
        $g = isset($l['grund']) ? (string) $l['grund'] : '';
        return array(4, sprintf(hp_t('GESUND.LETZTE_FEHL'), preg_match('/^[A-Z_]{1,40}\z/', $g) ? $g : '-'));
    }
    return array(5, sprintf(hp_t(isset($l['zeit']) ? 'GESUND.OK' : 'GESUND.OK_NEU'), $n));
}

/** ?aktion=status: lesend, ohne Netz. '-' heisst: nicht bekannt. */
function hp_status(array $cfg)
{
    $l = hp_letzte();
    $hat = isset($l['zeit']);
    return array('OK' => 1, 'SPRECHEN' => (string) $cfg['sprechen_ein'] === '1' ? 1 : 0, 'DIENST' => hp_bibliothek_da() ? 1 : 0,
        'GERAETE' => count(hp_geraete($cfg)), 'ERREICHBAR' => '-', 'LETZTE_OK' => $hat ? (int) $l['ok'] : '-',
        'LETZTE_ALTER' => $hat ? max(0, time() - (int) $l['zeit']) : '-',
        'LETZTE_GRUND' => $hat ? (string) $l['grund'] : '-', 'GRUND' => '-');
}

/** Geraeteliste fuer andere Plugins: nur Namen, nie Adressen. */
function hp_geraete_zeilen(array $cfg)
{
    $g = hp_geraete($cfg);
    $z = array(hp_zeile('GERAET', array('OK' => 1, 'ANZAHL' => count($g), 'GRUND' => '-')));
    foreach ($g as $e) {
        $z[] = hp_zeile('GERAET', array('NAME' => $e['name'], 'MODELL' => isset($e['modell']) ? $e['modell'] : '-',
            'KOPPLUNG' => isset($e['kopplung']) ? $e['kopplung'] : '-'));
    }
    return $z;
}

/* ==================================================================
 * Sicherung: die ganze Konfiguration samt beiden Token; die Geraeteliste entsteht durch Suchen neu
 * ================================================================== */

function hp_sicherung_datei(array $cfg)
{
    $d = array('_plugin' => 'HomePod4Lox', '_stand' => (hp_fassung() !== '' ? hp_fassung() : 'unbekannt') . ', ' . date('Y-m-d H:i:s'),
               '_hinweis' => hp_t('SICH.KOPF_HINWEIS'));
    foreach (array_keys(hp_vorgaben()) as $k) {
        $d[$k] = (string) $cfg[$k];
    }
    return json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Eine halb gueltige Datei aendert nichts. Rueckgabe array(Konfiguration|null, Beanstandungen). */
function hp_sicherung_lesen($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['_plugin']) || $d['_plugin'] !== 'HomePod4Lox') {
        return array(null, array(hp_t('SICH.F_FREMD')));
    }
    $vorg = hp_vorgaben();
    $neu = $vorg;
    $mangel = array();
    foreach ($d as $k => $w) {
        $k = (string) $k;
        if (substr($k, 0, 1) === '_') {
            continue;
        }
        if (!array_key_exists($k, $vorg)) {
            $mangel[] = sprintf(hp_t('SICH.F_SCHLUESSEL'), $k);
            continue;
        }
        $f = hp_wert_pruefen($k, $w);
        if ($f !== '') {
            $mangel[] = $k . ': ' . hp_t($f);
            continue;
        }
        $neu[$k] = $w;
    }
    foreach (array_keys($vorg) as $k) {
        if (!array_key_exists($k, $d)) {
            $mangel[] = sprintf(hp_t('SICH.F_FEHLT'), $k);
        }
    }
    return array($mangel ? null : $neu, $mangel);
}

/* Einmalmeldung nach dem POST: Datei 0600, beim folgenden GET gelesen UND geloescht. */
function hp_einmal_schreiben(array $inhalt, array $cfg)
{
    $js = json_encode(array('zeit' => time()) + $inhalt, JSON_UNESCAPED_UNICODE);
    if ($js === false) {
        return;
    }
    foreach (array((string) $cfg['aktionstoken'], (string) $cfg['sprechtoken']) as $g) {
        if (strlen($g) >= 4) {
            $js = str_replace($g, '***', $js);
        }
    }
    $d = hp_paths()['datadir'];
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    hp_datei_schreiben($d . '/einmalmeldung.json', $js);
}

function hp_einmal_lesen()
{
    $f = hp_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    return (is_array($d) && isset($d['zeit']) && time() - (int) $d['zeit'] <= 120) ? $d : null;
}

/** Zusammenfassung fuer den Kopf des ersten Reiters. */
function hp_zusammenfassung(array $cfg, array $geraete)
{
    if ((string) $cfg['sprechen_ein'] !== '1') {
        return hp_t('EINST.Z_AUS');
    }
    if (!hp_bibliothek_da()) {
        return hp_t('EINST.Z_OHNE_BIBLIOTHEK');
    }
    if (!$geraete) {
        return hp_t('EINST.Z_OHNE_GERAET');
    }
    if ((string) $cfg['sprechen_geraet'] === '') {
        return sprintf(hp_t('EINST.Z_OHNE_STANDARD'), count($geraete));
    }
    return sprintf(hp_t('EINST.Z_BEREIT'), count($geraete), (string) $cfg['sprechen_geraet']);
}
