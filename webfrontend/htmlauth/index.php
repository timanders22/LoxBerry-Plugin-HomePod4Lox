<?php
/**
 * HomePod4Lox - Bedienoberflaeche (Regeln/04; Kopf wie Dashboard, Entscheidung Nr. 43).
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Sicherung | Logdateien. Netz nur auf Knopfdruck und bei offenem Reiter Test.
 * Jedes Formular traegt das Merkmal (fmt) und formular=<name>; EINE Pruefung vor allen Handlern; jeder POST endet mit 303.
 * Adressen stehen nie auf der Seite. Praefix 'hp_', weil LBWeb::lbheader() SDK-Globale setzt. PHP 7.4, 8.4, 8.5.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$hp_lib_da = false;
if (basename(dirname(__DIR__)) === 'plugins') {
    $hp_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/hp_lib.php');
} else {
    $hp_kandidaten = array(dirname(__DIR__) . '/html/hp_lib.php');
}
foreach ($hp_kandidaten as $hp_kandidat) {
    if (is_file($hp_kandidat)) {
        require_once $hp_kandidat;
        $hp_lib_da = true;
        break;
    }
}
if (!$hp_lib_da) {
    echo '<p><b>Fehler:</b> hp_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}

$hp_p = hp_paths();
if ($hp_p['home'] !== '' && is_file($hp_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $hp_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $hp_p['home'] . '/libs/phplib/loxberry_web.php';
}
if ($hp_p['home'] !== '' && !is_dir($hp_p['datadir'])) {
    @mkdir($hp_p['datadir'], 0700, true);
}

$hp_muster = '/^tab-(settings|loxone|test|sicherung|log)$/';
$hp_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab']) && preg_match($hp_muster, $_POST['activetab'])) {
    $hp_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form']) && preg_match($hp_muster, 'tab-' . $_GET['form'])) {
    $hp_tab = 'tab-' . $_GET['form'];
}

$hp_meldungen = array();
$hp_fehler = array();       // Beanstandungen: es wurde nichts gespeichert
$hp_misslungen = array();   // ein Vorgang ist nicht gelungen
$hp_hinweise = array();
$hp_eingaben = array();
$hp_cfg = hp_config_vervollstaendigen(hp_config());
$hp_fmt = hp_formtoken($hp_cfg);
$hp_post_roh = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$hp_post = $hp_post_roh;
if ($hp_post) {
    $hp_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($hp_fmt === '' || !hash_equals($hp_fmt, $hp_mit)) {
        $hp_post = false;
        $hp_misslungen[] = hp_t('ALLG.FORMULAR_FREMD');
    }
}
$hp_formular = ($hp_post && isset($_POST['formular']) && is_string($_POST['formular'])) ? $_POST['formular'] : '';
$hp_wert = function ($k, $vorgabe = '') {
    return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : $vorgabe;
};

if ($hp_post) {
    switch ($hp_formular) {
        case 'einstellungen':
            $hp_tab = 'tab-settings';
            $hp_neu = $hp_cfg;
            $hp_neu['sprechen_ein'] = isset($_POST['sprechen_ein']) ? '1' : '0';
            $hp_neu['alle_airplay'] = isset($_POST['alle_airplay']) ? '1' : '0';
            $hp_eingaben['sprechen_ein'] = $hp_neu['sprechen_ein'];
            $hp_eingaben['alle_airplay'] = $hp_neu['alle_airplay'];
            foreach (array('sprechen_geraet', 'sprechen_stunde', 'laut', 'laut_max', 'quelle', 'sprache', 'vorlauf_ms', 'verfall_s') as $hp_k) {
                $hp_neu[$hp_k] = $hp_wert($hp_k, $hp_k === 'laut' ? '-1' : '');
                if ($hp_k === 'laut' && $hp_neu[$hp_k] === '') {
                    $hp_neu[$hp_k] = '-1';
                }
                $hp_m = hp_wert_pruefen($hp_k, $hp_neu[$hp_k]);
                if ($hp_m !== '') {
                    $hp_fehler[] = hp_t($hp_m);
                    $hp_eingaben['_mangel'][$hp_k] = 1;
                }
                $hp_eingaben[$hp_k] = substr($hp_neu[$hp_k], 0, 200);
            }
            $hp_tokneu = isset($_POST['sprechtoken']) && is_string($_POST['sprechtoken']) ? trim($_POST['sprechtoken']) : '';
            unset($_POST['sprechtoken']);
            $hp_loeschen = isset($_POST['sprechtoken_loeschen']);
            $hp_wuerfeln = isset($_POST['sprechtoken_neu']);
            if ($hp_tokneu !== '' && ($hp_loeschen || $hp_wuerfeln)) {
                $hp_fehler[] = hp_t('EINST.F_TOKEN_UND_HAKEN');
            } elseif ($hp_loeschen && $hp_wuerfeln) {
                $hp_fehler[] = hp_t('EINST.F_TOKEN_UND_HAKEN');
            } elseif ($hp_loeschen) {
                $hp_neu['sprechtoken'] = '';
            } elseif ($hp_wuerfeln) {
                $hp_neu['sprechtoken'] = hp_token_erzeugen();
            } elseif ($hp_tokneu !== '') {
                if (!hp_token_taugt($hp_tokneu)) {
                    $hp_fehler[] = hp_t('EINST.F_TOKEN');
                } else {
                    $hp_neu['sprechtoken'] = $hp_tokneu;
                }
            }
            if (!$hp_fehler && $hp_neu['sprechen_ein'] === '1' && $hp_neu['sprechtoken'] === '') {
                $hp_fehler[] = hp_t('EINST.F_EIN_OHNE_TOKEN');
            }
            if ($hp_fehler) {
                break;     // eine Beanstandung: NICHTS wird gespeichert
            }
            if (hp_config_speichern($hp_neu)) {
                $hp_meldungen[] = hp_t('EINST.M_GESPEICHERT');
            } else {
                $hp_misslungen[] = hp_t('EINST.F_SPEICHERN');
            }
            break;

        case 'suchen':
            $hp_tab = isset($_POST['zurueck']) && $_POST['zurueck'] === 'test' ? 'tab-test' : 'tab-settings';
            if (!hp_bibliothek_da()) {
                $hp_misslungen[] = hp_t('TEST.F_BIBLIOTHEK');
                break;
            }
            $hp_arg = '--suchen --frist ' . (int) HP_SUCHE_FRIST_S . (isset($_POST['neu']) ? ' --neu' : '');
            list($hp_rc, $hp_z) = hp_ausgabe_warten($hp_arg, HP_SUCHE_FRIST_S + 15);
            $hp_e = hp_felder($hp_z);
            if ($hp_rc === 0 && isset($hp_e['OK']) && $hp_e['OK'] === '1') {
                $hp_meldungen[] = sprintf(hp_t('TEST.M_GESUCHT'), (int) $hp_e['GEFUNDEN'], (int) $hp_e['HOMEPOD'], (int) $hp_e['LISTE']);
                if ((int) $hp_e['GEFUNDEN'] === 0) {
                    $hp_hinweise[] = hp_t('TEST.H_NICHTS_GEFUNDEN');
                }
            } else {
                $hp_misslungen[] = sprintf(hp_t('TEST.F_SUCHE'), isset($hp_e['GRUND']) ? $hp_e['GRUND'] : ($hp_rc === 124 ? 'ZEIT' : 'rc ' . $hp_rc));
            }
            break;

        case 'testansage':
            $hp_tab = 'tab-test';
            $hp_tpar = array('text' => hp_t('TEST.SATZ'), 'geraet' => $hp_wert('geraet'), 'laut' => $hp_wert('laut'));
            list($hp_h, $hp_f) = hp_sprechen($hp_tpar, $hp_cfg);
            $hp_antwort = 'HTTP ' . $hp_h . ' ' . hp_zeile('SPRECHEN', $hp_f);
            if ($hp_h === 200 && !empty($hp_f['OK'])) {
                $hp_meldungen[] = sprintf(hp_t('TEST.M_ANSAGE'), $hp_antwort);
            } else {
                $hp_misslungen[] = sprintf(hp_t('TEST.F_ANSAGE'), $hp_antwort, hp_t('GRUND.' . $hp_f['GRUND']));
            }
            break;

        case 'sicherung':
            $hp_tab = 'tab-sicherung';
            if ($hp_wert('aktion') === 'holen') {
                $hp_js = hp_sicherung_datei($hp_cfg);
                if ($hp_js === false) {
                    $hp_misslungen[] = hp_t('SICH.F_DATEI');
                    break;
                }
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="homepod4lox-' . date('Ymd-Hi') . '.json"');
                echo $hp_js;
                exit;
            }
            if (!isset($_POST['zurueck_ok'])) {
                $hp_misslungen[] = hp_t('SICH.F_HAKEN');
                break;
            }
            if (!isset($_FILES['sicherung']['tmp_name']) || !is_string($_FILES['sicherung']['tmp_name'])
                || !@is_uploaded_file($_FILES['sicherung']['tmp_name'])) {
                $hp_misslungen[] = hp_t('SICH.F_KEINE_DATEI');
                break;
            }
            if ((int) $_FILES['sicherung']['size'] > 65536) {
                $hp_misslungen[] = hp_t('SICH.F_GROSS');
                break;
            }
            list($hp_neu, $hp_mangel) = hp_sicherung_lesen((string) @file_get_contents($_FILES['sicherung']['tmp_name']));
            if ($hp_neu === null) {
                $hp_fehler[] = hp_t('SICH.F_ABGELEHNT');
                $hp_fehler = array_merge($hp_fehler, $hp_mangel);
                break;
            }
            if ($hp_neu['aktionstoken'] === '') {
                $hp_neu['aktionstoken'] = $hp_cfg['aktionstoken'];
            }
            if (hp_config_speichern($hp_neu)) {
                $hp_meldungen[] = hp_t('SICH.M_ZURUECK');
            } else {
                $hp_misslungen[] = hp_t('EINST.F_SPEICHERN');
            }
            break;

        case 'log':
            $hp_tab = 'tab-log';
            if (!isset($_POST['leeren_ok'])) {
                $hp_misslungen[] = hp_t('LOG.F_HAKEN');
                break;
            }
            $hp_err_datei = $hp_p['logdir'] . '/lauf.err';
            if ((is_file($hp_p['log']) && @file_put_contents($hp_p['log'], '') === false)
                || (is_file($hp_err_datei) && @file_put_contents($hp_err_datei, '') === false)) {
                $hp_misslungen[] = hp_t('LOG.F_LEEREN');
            } else {
                hp_log('Protokoll geleert (Oberflaeche).');
                $hp_meldungen[] = hp_t('LOG.M_GELEERT');
            }
            break;

        default:
            $hp_misslungen[] = hp_t('ALLG.FORMULAR_UNBEKANNT');
    }
}
if ($hp_post_roh) {
    hp_einmal_schreiben(array('meldungen' => $hp_meldungen, 'fehler' => $hp_fehler, 'misslungen' => $hp_misslungen,
        'hinweise' => $hp_hinweise, 'eingaben' => $hp_fehler ? $hp_eingaben : array()), hp_config(false));
    header('Location: index.php?form=' . substr($hp_tab, 4), true, 303);
    exit;
}
$hp_einmal = hp_einmal_lesen();
if ($hp_einmal) {
    foreach (array('meldungen' => 'hp_meldungen', 'fehler' => 'hp_fehler', 'misslungen' => 'hp_misslungen', 'hinweise' => 'hp_hinweise', 'eingaben' => 'hp_eingaben') as $hp_k => $hp_v) {
        $$hp_v = (isset($hp_einmal[$hp_k]) && is_array($hp_einmal[$hp_k])) ? $hp_einmal[$hp_k] : array();
    }
}

/* ---------------- Seitenaufbau ---------------- */
$hp_cfg = hp_config();
$hp_geraete = hp_geraete($hp_cfg);
$hp_alle = hp_geraete_alle();
$hp_stand = hp_geraete_stand();
$hp_da = 0;
foreach ($hp_geraete as $hp_g) {
    if (!empty($hp_g['da'])) { $hp_da++; }
}
$hp_bib = hp_bibliothek_da();
$hp_aktiv = hp_lauf_aktiv();
$hp_letzte = hp_letzte();
$hp_feld = function ($k) use ($hp_cfg, $hp_eingaben) {
    return hp_e(isset($hp_eingaben[$k]) ? $hp_eingaben[$k] : $hp_cfg[$k]);
};
/* Nach einer Beanstandung gilt, was eingegeben war, auch bei Haken und Auswahl. */
$hp_jetzt = function ($k) use ($hp_cfg, $hp_eingaben) {
    return (string) (isset($hp_eingaben[$k]) ? $hp_eingaben[$k] : $hp_cfg[$k]);
};
$hp_mangel = function ($k) use ($hp_eingaben) {
    return isset($hp_eingaben['_mangel'][$k]) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
};
$hp_zeichen = function ($ok) {
    return $ok === true ? '<span class="sm-an">&#10004;</span>' : ($ok === false ? '<span class="sm-aus">&#10008;</span>'
        : '<span class="sm-hinweiszeichen">&#9432;</span>');
};
$hp_grund = function ($g) {
    $t = hp_t('GRUND.' . $g);
    return $t === 'GRUND.' . $g ? (string) $g : $t . ' (' . $g . ')';
};
$hp_webport = 80;
$hp_gj = $hp_p['home'] !== '' ? json_decode((string) @file_get_contents($hp_p['home'] . '/config/system/general.json'), true) : null;
if (is_array($hp_gj)) {
    foreach (array('Webserver', 'WEBSERVER') as $hp_ab) {
        if (isset($hp_gj[$hp_ab]) && is_array($hp_gj[$hp_ab]) && isset($hp_gj[$hp_ab]['Port']) && (int) $hp_gj[$hp_ab]['Port'] > 0) {
            $hp_webport = (int) $hp_gj[$hp_ab]['Port'];
            break;
        }
    }
}
$hp_adresse = 'http://127.0.0.1:' . $hp_webport . '/plugins/' . $hp_p['plugin'] . '/index.php';

/* Pruefzeilen des Reiters Test: Netz und Bibliothek nur, wenn der Reiter serverseitig offen ist. */
$hp_test_offen = ($hp_tab === 'tab-test');
$hp_pruef = array();
if ($hp_test_offen) {
    $hp_pruef[] = array(is_file(hp_python()), hp_t('TEST.P_VENV'), is_file(hp_python()) ? hp_t('ALLG.JA') : hp_t('TEST.P_VENV_NEIN'));
    list($hp_rc, $hp_z) = hp_ausgabe_warten('--pruefen', 30);
    $hp_e = hp_felder($hp_z);
    $hp_gut = $hp_rc === 0 && isset($hp_e['OK']) && $hp_e['OK'] === '1';
    $hp_pruef[] = array($hp_gut, hp_t('TEST.P_BIB'), $hp_gut ? sprintf(hp_t('TEST.P_BIB_JA'), $hp_e['PYATV'], $hp_e['MINIAUDIO'], $hp_e['PYTHON'])
        : sprintf(hp_t('TEST.P_BIB_NEIN'), isset($hp_e['GRUND']) ? $hp_e['GRUND'] : 'rc ' . $hp_rc));
    $hp_espeak = array();
    $hp_rc = 1;
    @exec('command -v espeak-ng 2>/dev/null', $hp_espeak, $hp_rc);
    $hp_lokal = (string) $hp_cfg['quelle'] === 'lokal';
    $hp_pruef[] = array($hp_rc === 0 ? true : ($hp_lokal ? false : null), hp_t('TEST.P_ESPEAK'), $hp_rc === 0 ? hp_t('ALLG.JA') : hp_t('TEST.P_ESPEAK_NEIN'));
    $hp_lage = hp_config_lage();
    $hp_pruef[] = array($hp_lage === 'ok' ? true : ($hp_lage === 'zweitschrift' ? null : false), hp_t('TEST.P_CONFIG'), hp_t('TEST.P_LAGE_' . strtoupper($hp_lage)));
    $hp_pruef[] = array(hp_token_taugt((string) $hp_cfg['sprechtoken']), hp_t('TEST.P_TOKEN'), hp_t(hp_token_taugt((string) $hp_cfg['sprechtoken']) ? 'ALLG.JA' : 'ALLG.NEIN'));
    $hp_pruef[] = array((string) $hp_cfg['sprechen_ein'] === '1', hp_t('TEST.P_EIN'), hp_t((string) $hp_cfg['sprechen_ein'] === '1' ? 'ALLG.EIN' : 'ALLG.AUS'));
    $hp_pruef[] = array($hp_geraete ? true : false, hp_t('TEST.P_GERAETE'), sprintf(hp_t('KOPF.GERAETE_WERT'), $hp_da, count($hp_geraete)));
    $hp_std = hp_ziele_finden((string) $hp_cfg['sprechen_geraet'], $hp_geraete);
    $hp_stdok = (string) $hp_cfg['sprechen_geraet'] !== '' && $hp_std['fehlt'] === '' && $hp_std['ziele'];
    $hp_pruef[] = array($hp_stdok ? true : null, hp_t('TEST.P_STANDARD'), (string) $hp_cfg['sprechen_geraet'] !== '' ? (string) $hp_cfg['sprechen_geraet'] : hp_t('TEST.P_KEIN_STANDARD'));
    // Die Reiterleiste gegen Bereiche und Positivliste (Regeln/04)
    $hp_quelle = (string) @file_get_contents(__FILE__);
    $hp_a = preg_match_all('/data-ziel="(tab-[a-z]+)"/', $hp_quelle, $hp_ta) ? array_values(array_unique($hp_ta[1])) : array();
    $hp_b = preg_match_all('/ id="(tab-[a-z]+)"/', $hp_quelle, $hp_tb) ? array_values(array_unique($hp_tb[1])) : array();
    sort($hp_a);
    sort($hp_b);
    $hp_reiter_ok = count($hp_a) === 5 && $hp_a === $hp_b;
    foreach ($hp_a as $hp_x) {
        $hp_reiter_ok = $hp_reiter_ok && preg_match($hp_muster, $hp_x) === 1;
    }
    $hp_pruef[] = array($hp_reiter_ok, hp_t('TEST.P_REITER'), sprintf(hp_t('TEST.P_REITER_WERT'), count($hp_a), count($hp_b)));
}
$hp_logzeilen = hp_log_ende(300);
$hp_errzeilen = hp_lauf_err_ende(100);

$hp_rahmen = class_exists('LBWeb', false);
if ($hp_rahmen) {
    LBWeb::lbheader('HomePod4Lox', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard (VORLAGE_hausstandard.css.html, wortgleich wie Dashboard 0.9.38). */
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
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-log { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.82em;
    overflow: auto; margin: 8px 0; max-height: 420px; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-wrap .sm-tbl button.sm-btn { min-width: 0; padding: 5px 10px !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-hinweiszeichen { color: #546e7a; font-weight: 700; }
.sm-status { margin: 10px 0 0; }
.sm-status td:first-child { width: 28%; font-weight: 600; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-tbl input[type=text], .sm-tbl input[type=password] { width: 100%; box-sizing: border-box; }
.sm-wrap details { margin: 12px 0; }
.sm-wrap summary { cursor: pointer; font-weight: 600; color: #4f7d17; }
</style>
<style>
.sm-wrap .sm-beanstandet { border: 2px solid #b00000 !important; background: #fff4f4; }
</style>

<div class="sm-wrap">

<?php if ($hp_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('hp_e', $hp_meldungen)) ?></div>
<?php } ?>
<?php if ($hp_fehler) { ?>
<div class="sm-warnung"><b><?= hp_e(hp_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('hp_e', $hp_fehler)) ?></div>
<?php } ?>
<?php if ($hp_misslungen) { ?>
<div class="sm-warnung"><b><?= hp_e(hp_t('ALLG.MISSLUNGEN')) ?></b><br><?= implode('<br>', array_map('hp_e', $hp_misslungen)) ?></div>
<?php } ?>
<?php if ($hp_hinweise) { ?>
<div class="sm-warnung"><?= implode('<br>', array_map('hp_e', $hp_hinweise)) ?></div>
<?php } ?>

<!-- Statusuebersicht ueber der Reiterleiste (Kopf wie Dashboard, Entscheidung Nr. 43). -->
<table class="sm-tbl sm-status">
<tr><th><?= hp_e(hp_t('KOPF.EIGENSCHAFT')) ?></th><th><?= hp_e(hp_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= hp_e(hp_t('KOPF.SPRECHEN')) ?></td>
<td><b class="<?= (string) $hp_cfg['sprechen_ein'] === '1' ? 'sm-an' : 'sm-aus' ?>"><?= hp_e(hp_t((string) $hp_cfg['sprechen_ein'] === '1' ? 'ALLG.EIN' : 'ALLG.AUS')) ?></b></td></tr>
<tr><td><?= hp_e(hp_t('KOPF.BIBLIOTHEK')) ?></td>
<td><b class="<?= $hp_bib ? 'sm-an' : 'sm-aus' ?>"><?= hp_e(hp_t($hp_bib ? 'KOPF.BIB_JA' : 'KOPF.BIB_NEIN')) ?></b></td></tr>
<tr><td><?= hp_e(hp_t('KOPF.GERAETE')) ?></td>
<td><?= hp_e(sprintf(hp_t('KOPF.GERAETE_WERT'), $hp_da, count($hp_geraete))) ?><?= $hp_stand > 0 ? ' &middot; ' . hp_e(sprintf(hp_t('KOPF.GESUCHT'), date('d.m.Y H:i', $hp_stand))) : '' ?></td></tr>
<tr><td><?= hp_e(hp_t('KOPF.BETRIEB')) ?></td>
<td><?= hp_e(hp_t($hp_aktiv ? 'KOPF.LAUF_AKTIV' : 'KOPF.LAUF_RUHT')) ?></td></tr>
<tr><td><?= hp_e(hp_t('KOPF.LETZTE')) ?></td>
<td><?= isset($hp_letzte['zeit']) ? hp_e(date('d.m.Y H:i:s', (int) $hp_letzte['zeit']) . ' · ' . ($hp_letzte['grund'] === '-' ? hp_t('GRUND.GESENDET') : $hp_grund($hp_letzte['grund']))) : '-' ?></td></tr>
</table>

<div class="sm-tabs">
	<a class="sm-tab<?= $hp_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= hp_e(hp_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $hp_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= hp_e(hp_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $hp_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= hp_e(hp_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $hp_tab === 'tab-sicherung' ? ' sm-active' : '' ?>" data-ziel="tab-sicherung"
	   href="index.php?form=sicherung"><?= hp_e(hp_t('REITER.SICHERUNG')) ?></a>
	<a class="sm-tab<?= $hp_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= hp_e(hp_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $hp_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= hp_e(hp_zusammenfassung($hp_cfg, $hp_geraete)) ?></div>
<div class="sm-warnung"><?= hp_e(hp_t('EINST.UNGEMESSEN')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= hp_e(hp_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= hp_e(hp_t('LEGENDE.AKTION')) ?></span>
</div>

<h2><?= hp_e(hp_t('EINST.H_GERAETE')) ?></h2>
<?php if ($hp_geraete) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= hp_e(hp_t('TEST.SP_NAME')) ?></th><th><?= hp_e(hp_t('TEST.SP_MODELL')) ?></th><th><?= hp_e(hp_t('TEST.SP_ZUGRIFF')) ?></th><th><?= hp_e(hp_t('TEST.SP_GESEHEN')) ?></th></tr>
<?php foreach ($hp_geraete as $hp_g) {
    $hp_zug = isset($hp_g['kopplung']) ? (string) $hp_g['kopplung'] : '-'; ?>
<tr><td><?= hp_e($hp_g['name']) ?></td><td><?= hp_e(isset($hp_g['modell']) ? $hp_g['modell'] : '-') ?></td>
<td><?= !empty($hp_g['kennwort']) ? hp_e(hp_t('TEST.ZUGRIFF_KENNWORT')) : (in_array($hp_zug, array('NotNeeded', 'Optional'), true) ? hp_e(hp_t('TEST.ZUGRIFF_OK')) : hp_e(hp_t('TEST.ZUGRIFF_NEIN'))) ?></td>
<td><?= !empty($hp_g['da']) ? hp_e(hp_t('TEST.DA_JETZT')) : (isset($hp_g['gesehen']) ? hp_e(date('d.m.Y H:i', (int) $hp_g['gesehen'])) : '-') ?></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<p class="sm-hilfe"><?= hp_e(hp_t('EINST.KEINE_GERAETE')) ?></p>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="suchen">
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= hp_e(hp_t('TEST.K_SUCHEN')) ?></button>
</div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="neu" value="1"> <?= hp_e(hp_t('EINST.SUCHE_NEU')) ?></label></div>
</form>

<h2><?= hp_e(hp_t('EINST.H_SPRECHEN')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="einstellungen">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="sprechen_ein" value="1"<?= $hp_jetzt('sprechen_ein') === '1' ? ' checked' : '' ?>> <?= hp_e(hp_t('EINST.L_EIN')) ?></label>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_EIN')) ?></div></div>
<div class="sm-feld"><label for="hp_std"><?= hp_e(hp_t('EINST.L_STANDARD')) ?></label>
<?php if ($hp_geraete) { ?>
<select data-role="none" id="hp_std" name="sprechen_geraet">
<option value=""><?= hp_e(hp_t('EINST.KEIN_STANDARD')) ?></option>
<?php $hp_std_jetzt = isset($hp_eingaben['sprechen_geraet']) ? $hp_eingaben['sprechen_geraet'] : (string) $hp_cfg['sprechen_geraet'];
foreach ($hp_geraete as $hp_g) { ?>
<option value="<?= hp_e($hp_g['name']) ?>"<?= $hp_g['name'] === $hp_std_jetzt ? ' selected' : '' ?>><?= hp_e($hp_g['name']) ?></option>
<?php } ?>
</select>
<?php } else { ?>
<input data-role="none" id="hp_std" type="text" name="sprechen_geraet" value="<?= $hp_feld('sprechen_geraet') ?>" maxlength="200"<?= $hp_mangel('sprechen_geraet') ?>>
<?php } ?>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_STANDARD')) ?></div></div>
<div class="sm-feld"><label for="hp_laut"><?= hp_e(hp_t('EINST.L_LAUT')) ?></label>
<input data-role="none" id="hp_laut" type="text" name="laut" value="<?= $hp_feld('laut') ?>" maxlength="3" size="4"<?= $hp_mangel('laut') ?>>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_LAUT')) ?></div></div>
<div class="sm-feld"><label for="hp_max"><?= hp_e(hp_t('EINST.L_LAUT_MAX')) ?></label>
<input data-role="none" id="hp_max" type="text" name="laut_max" value="<?= $hp_feld('laut_max') ?>" maxlength="3" size="4"<?= $hp_mangel('laut_max') ?>></div>
<div class="sm-feld"><label for="hp_quelle"><?= hp_e(hp_t('EINST.L_QUELLE')) ?></label>
<select data-role="none" id="hp_quelle" name="quelle"<?= $hp_mangel('quelle') ?>>
<option value="lokal"<?= $hp_jetzt('quelle') === 'lokal' ? ' selected' : '' ?>><?= hp_e(hp_t('EINST.QUELLE_LOKAL')) ?></option>
<option value="google"<?= $hp_jetzt('quelle') === 'google' ? ' selected' : '' ?>><?= hp_e(hp_t('EINST.QUELLE_GOOGLE')) ?></option>
</select>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_QUELLE')) ?></div></div>
<div class="sm-feld"><label for="hp_sprache"><?= hp_e(hp_t('EINST.L_SPRACHE')) ?></label>
<input data-role="none" id="hp_sprache" type="text" name="sprache" value="<?= $hp_feld('sprache') ?>" maxlength="12" size="6"<?= $hp_mangel('sprache') ?>></div>
<div class="sm-feld"><label for="hp_vor"><?= hp_e(hp_t('EINST.L_VORLAUF')) ?></label>
<input data-role="none" id="hp_vor" type="text" name="vorlauf_ms" value="<?= $hp_feld('vorlauf_ms') ?>" maxlength="4" size="5"<?= $hp_mangel('vorlauf_ms') ?>>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_VORLAUF')) ?></div></div>
<div class="sm-feld"><label for="hp_verf"><?= hp_e(hp_t('EINST.L_VERFALL')) ?></label>
<input data-role="none" id="hp_verf" type="text" name="verfall_s" value="<?= $hp_feld('verfall_s') ?>" maxlength="3" size="4"<?= $hp_mangel('verfall_s') ?>>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_VERFALL')) ?></div></div>
<div class="sm-feld"><label for="hp_stunde"><?= hp_e(hp_t('EINST.L_STUNDE')) ?></label>
<input data-role="none" id="hp_stunde" type="text" name="sprechen_stunde" value="<?= $hp_feld('sprechen_stunde') ?>" maxlength="3" size="4"<?= $hp_mangel('sprechen_stunde') ?>></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="alle_airplay" value="1"<?= $hp_jetzt('alle_airplay') === '1' ? ' checked' : '' ?>> <?= hp_e(hp_t('EINST.L_ALLE')) ?></label>
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_ALLE')) ?></div></div>
<div class="sm-feld"><label for="hp_tok"><?= hp_e(hp_t('EINST.L_TOKEN')) ?></label>
<input data-role="none" id="hp_tok" type="password" name="sprechtoken" value="" size="40" autocomplete="new-password" placeholder="<?= hp_e(sprintf(hp_t('EINST.TOKEN_PLATZ'), strlen((string) $hp_cfg['sprechtoken']))) ?>">
<div class="sm-hilfe"><?= hp_e(hp_t('EINST.H_TOKEN')) ?></div>
<label><input data-role="none" type="checkbox" name="sprechtoken_neu" value="1"> <?= hp_e(hp_t('EINST.TOKEN_NEU')) ?></label><br>
<label><input data-role="none" type="checkbox" name="sprechtoken_loeschen" value="1"> <?= hp_e(hp_t('EINST.TOKEN_LOESCHEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= hp_e(hp_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $hp_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-hinweis"><?= hp_e(hp_t('LOX.WAS_IST_DAS')) ?></div>
<div class="sm-step"><b><?= hp_e(hp_t('LOX.S1_TITEL')) ?></b><br><?= hp_e(hp_t('LOX.S1_TEXT')) ?></div>
<div class="sm-step"><b><?= hp_e(hp_t('LOX.S2_TITEL')) ?></b><br><?= hp_e(hp_t('LOX.S2_TEXT')) ?></div>
<div class="sm-step"><b><?= hp_e(hp_t('LOX.S3_TITEL')) ?></b><br><?= hp_e(hp_t('LOX.S3_TEXT')) ?>
<div class="sm-mono"><?= hp_e('curl -s --data-urlencode "aktion=sprechen" --data-urlencode "token=<SPRECHTOKEN>" --data-urlencode "geraet=' . ((string) $hp_cfg['sprechen_geraet'] !== '' ? (string) $hp_cfg['sprechen_geraet'] : 'alle') . '" --data-urlencode "text=' . hp_t('TEST.SATZ') . '" ' . $hp_adresse) ?></div></div>
<div class="sm-step"><b><?= hp_e(hp_t('LOX.S4_TITEL')) ?></b><br><?= hp_e(hp_t('LOX.S4_TEXT')) ?>
<div class="sm-mono"><?= hp_e('http://<LoxBerry>:' . $hp_webport . '/plugins/' . $hp_p['plugin'] . '/index.php?aktion=status') ?></div></div>
<div class="sm-step"><b><?= hp_e(hp_t('LOX.S5_TITEL')) ?></b><br><?= hp_e(hp_t('LOX.S5_TEXT')) ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $hp_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-hinweis"><?= hp_e(hp_t('TEST.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= hp_e(hp_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= hp_e(hp_t('LEGENDE.AKTION')) ?></span>
</div>
<?php if ($hp_test_offen) { ?>
<table class="sm-tbl">
<tr><th></th><th><?= hp_e(hp_t('TEST.SP_FRAGE')) ?></th><th><?= hp_e(hp_t('TEST.SP_ANTWORT')) ?></th></tr>
<?php foreach ($hp_pruef as $hp_z) { ?>
<tr><td><?= $hp_zeichen($hp_z[0]) ?></td><td><?= hp_e($hp_z[1]) ?></td><td><?= hp_e($hp_z[2]) ?></td></tr>
<?php } ?>
</table>
<?php } else { ?>
<p class="sm-hilfe"><?= hp_e(hp_t('TEST.NUR_OFFEN')) ?></p>
<?php } ?>

<h2><?= hp_e(hp_t('TEST.H_GEFUNDEN')) ?></h2>
<?php if ($hp_alle) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= hp_e(hp_t('TEST.SP_NAME')) ?></th><th><?= hp_e(hp_t('TEST.SP_MODELL')) ?></th><th><?= hp_e(hp_t('TEST.SP_ANGEBOT')) ?></th><th><?= hp_e(hp_t('TEST.SP_ZUGRIFF')) ?></th><th><?= hp_e(hp_t('TEST.SP_GESEHEN')) ?></th><th><?= hp_e(hp_t('TEST.SP_MERKMALE')) ?></th></tr>
<?php foreach ($hp_alle as $hp_g) {
    if (!is_array($hp_g) || !isset($hp_g['name'])) { continue; }
    $hp_zug = isset($hp_g['kopplung']) ? (string) $hp_g['kopplung'] : '-';
    $hp_mm = array('kopplung=' . $hp_zug);
    foreach ((isset($hp_g['merkmale']) && is_array($hp_g['merkmale']) ? $hp_g['merkmale'] : array()) as $hp_mk => $hp_mw) {
        $hp_mm[] = $hp_mk . '=' . $hp_mw;
    } ?>
<tr><td><?= hp_e($hp_g['name']) ?></td><td><?= hp_e(isset($hp_g['modell']) ? $hp_g['modell'] : '-') ?></td>
<td><?= hp_e(hp_t(!empty($hp_g['homepod']) || (string) $hp_cfg['alle_airplay'] === '1' ? 'ALLG.JA' : 'ALLG.NEIN')) ?></td>
<td><?= hp_e((!empty($hp_g['kennwort']) ? hp_t('TEST.ZUGRIFF_KENNWORT') : (in_array($hp_zug, array('NotNeeded', 'Optional'), true) ? hp_t('TEST.ZUGRIFF_OK') : hp_t('TEST.ZUGRIFF_NEIN')))) ?></td>
<td><?= !empty($hp_g['da']) ? hp_e(hp_t('TEST.DA_JETZT')) : (isset($hp_g['gesehen']) ? hp_e(date('d.m.Y H:i', (int) $hp_g['gesehen'])) : '-') ?></td>
<td><span class="sm-mono"><?= hp_e(implode(' ', $hp_mm)) ?></span></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= hp_e(hp_t('TEST.H_MERKMALE')) ?></p>
<?php } else { ?>
<p class="sm-hilfe"><?= hp_e(hp_t('EINST.KEINE_GERAETE')) ?></p>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="suchen">
<input data-role="none" type="hidden" name="zurueck" value="test">
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= hp_e(hp_t('TEST.K_SUCHEN')) ?></button>
</div>
</form>

<h2><?= hp_e(hp_t('TEST.H_ANSAGE')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="testansage">
<div class="sm-feld"><label for="hp_tg"><?= hp_e(hp_t('TEST.L_ZIEL')) ?></label>
<select data-role="none" id="hp_tg" name="geraet">
<option value=""><?= hp_e(hp_t('TEST.ZIEL_STANDARD')) ?></option>
<option value="alle"><?= hp_e(hp_t('TEST.ZIEL_ALLE')) ?></option>
<?php foreach ($hp_geraete as $hp_g) { ?>
<option value="<?= hp_e($hp_g['name']) ?>"><?= hp_e($hp_g['name']) ?></option>
<?php } ?>
</select></div>
<div class="sm-feld"><label for="hp_tl"><?= hp_e(hp_t('TEST.L_LAUT')) ?></label>
<input data-role="none" id="hp_tl" type="text" name="laut" value="" maxlength="3" size="4">
<div class="sm-hilfe"><?= hp_e(hp_t('TEST.H_LAUT')) ?></div></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= hp_e(hp_t('TEST.K_ANSAGE')) ?></button>
</div>
<p class="sm-hilfe"><?= hp_e(hp_t('TEST.H_ANSAGE_TEXT')) ?></p>
</form>
<h2><?= hp_e(hp_t('TEST.H_LETZTE')) ?></h2>
<?php if (isset($hp_letzte['zeit'])) { ?>
<table class="sm-tbl">
<tr><th><?= hp_e(hp_t('TEST.SP_NAME')) ?></th><th><?= hp_e(hp_t('TEST.SP_ANTWORT')) ?></th><th><?= hp_e(hp_t('TEST.SP_DAUER')) ?></th></tr>
<?php foreach ((isset($hp_letzte['ziele']) && is_array($hp_letzte['ziele']) ? $hp_letzte['ziele'] : array()) as $hp_lz) { ?>
<tr><td><?= hp_e($hp_lz['name']) ?></td><td><?= hp_e($hp_lz['grund'] === '-' ? hp_t('GRUND.GESENDET') : $hp_grund($hp_lz['grund'])) ?></td><td><?= hp_e($hp_lz['sek']) ?> s</td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= hp_e(sprintf(hp_t('TEST.LETZTE_ZEIT'), date('d.m.Y H:i:s', (int) $hp_letzte['zeit']), $hp_letzte['grund'] === '-' ? hp_t('GRUND.GESENDET') : $hp_grund($hp_letzte['grund']))) ?></p>
<?php } else { ?>
<p class="sm-hilfe"><?= hp_e(hp_t('TEST.KEINE_LETZTE')) ?></p>
<?php } ?>
</div>

<!-- ================= Reiter: Sicherung ================= -->
<div class="sm-seite<?= $hp_tab === 'tab-sicherung' ? ' sm-active' : '' ?>" id="tab-sicherung">
<div class="sm-hinweis"><?= hp_e(hp_t('SICH.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= hp_e(hp_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= hp_e(hp_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-warnung"><?= hp_e(hp_t('SICH.WARNUNG')) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-sicherung">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="sicherung">
<input data-role="none" type="hidden" name="aktion" value="holen">
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= hp_e(hp_t('SICH.K_SICHERN')) ?></button>
</div>
</form>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-sicherung">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="sicherung">
<input data-role="none" type="hidden" name="aktion" value="zurueck">
<div class="sm-feld"><input data-role="none" type="file" name="sicherung" accept=".json,application/json"></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="zurueck_ok" value="1"> <?= hp_e(hp_t('SICH.HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= hp_e(hp_t('SICH.K_ZURUECK')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $hp_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-hinweis"><?= hp_e(hp_t('LOG.ERKLAERUNG')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= hp_e(hp_t('LEGENDE.AKTION')) ?></span>
</div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<?php if ($hp_logzeilen) { ?>
<div class="sm-log"><?= hp_e(implode("\n", $hp_logzeilen)) ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= hp_e(hp_t('LOG.LEER')) ?></p>
<?php } ?>
<?php if ($hp_errzeilen) { ?>
<p class="sm-hilfe"><?= hp_e(hp_t('LOG.ERR_TITEL')) ?></p>
<div class="sm-log"><?= hp_e(implode("\n", $hp_errzeilen)) ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-log">
<input data-role="none" type="hidden" name="fmt" value="<?= hp_e($hp_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="log">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="leeren_ok" value="1"> <?= hp_e(hp_t('LOG.HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="leeren" value="1"><?= hp_e(hp_t('LOG.K_LEEREN')) ?></button>
</div>
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
		if (r.dataset.ziel === 'tab-test') { return; }   // der Reiter Test laedt die Seite neu (Pruefungen nur dort)
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($hp_tab) ?>);
})();
</script>
<?php
if ($hp_rahmen) {
    LBWeb::lbfooter();
}
