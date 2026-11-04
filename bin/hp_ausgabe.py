#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""HomePod4Lox - Ausgabe ueber AirPlay (pyatv, RAOP). Kein Dauerdienst: laeuft nur, solange es etwas zu tun gibt.

  --selbsttest   reine Funktionen, ohne pyatv, ohne Netz, schreibt nichts
  --pruefen      laedt pyatv und miniaudio und nennt die Fassungen
  --suchen [--frist S] [--neu]   Geraete per mDNS suchen, data/geraete.json schreiben
  --lauf         Warteschlange abarbeiten; ein zweiter Lauf endet sofort (Sperre), leer heisst: Ende

Der Endpunkt legt Auftraege unter data/warteschlange/ ab und startet --lauf. Ansagetexte stehen nie im Protokoll, nur ihre
Laenge; Adressen und Kennungen stehen nur in data/geraete.json (0600) und nie auf der Seite.

Ohne HomePod gebaut: gemessen ist alles gegen die Attrappe aus den Tests von pyatv, nicht am Geraet.
"""

import asyncio
import glob
import hashlib
import json
import logging
import logging.handlers
import os
import re
import signal
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
import wave

LAUF_MAX_S = 1200           # harte Obergrenze eines Laufs (SIGALRM beendet ihn)
SUCHE_FRIST_S = 6           # Vorgabe der Geraetesuche
SUCHE_FRIST_MAX_S = 15
EINZEL_SCAN_S = 3           # gezielte Anfrage an die gemerkte Adresse
EINZEL_SCAN2_S = 4          # danach einmal im ganzen Netz (Adresse kann sich geaendert haben)
SPIEL_ZUSCHLAG_S = 20       # Frist je Geraet: Laenge der Ansage + Zuschlag
TTS_MAX = 200               # Google Translate nimmt hoechstens 200 Zeichen je Anfrage
QUELLE_FRIST_S = 10         # je Abruf
ESPEAK_FRIST_S = 30
AUDIO_MAX_BYTE = 32 * 1024 * 1024
RATE = 44100
KANAELE = 2
NACHLAUF_MS = 300

# Nur diese Merkmale aus der mDNS-Antwort werden gemerkt und gezeigt - Kennungen wie pk, pi, gid, psi, deviceid bleiben draussen.
MERKMAL_LISTE = ("am", "model", "ov", "osvers", "fv", "acl", "act", "pw", "flags", "sf", "ft", "features", "cn", "et", "vs", "tp", "igl", "gcgl")

HOMEPOD_MODELLE = ("AudioAccessory",)
KOPPLUNG_GUT = ("NotNeeded", "Optional")

LOG = logging.getLogger("homepod4lox")


# ---------------------------------------------------------------------------
# Pfade
# ---------------------------------------------------------------------------

def wurzel_suchen(start):
    d = os.path.abspath(start)
    for _ in range(8):
        if (os.path.isdir(os.path.join(d, "config", "plugins")) and os.path.isdir(os.path.join(d, "data", "plugins"))
                and os.path.isfile(os.path.join(d, "config", "system", "general.json"))):
            return d
        hoch = os.path.dirname(d)
        if hoch == d:
            break
        d = hoch
    return ""


def pfade():
    home = os.environ.get("LBHOMEDIR", "").rstrip("/")
    if not (home and os.path.isdir(os.path.join(home, "data", "plugins"))):
        home = wurzel_suchen(os.path.dirname(os.path.abspath(__file__)))
    if not home:
        return None
    ordner = os.path.basename(os.environ.get("LBPPLUGINDIR", "").rstrip("/")) or os.path.basename(
        os.path.dirname(os.path.abspath(__file__)))
    if ordner in ("", ".", "..", "bin", "plugins"):
        ordner = "homepod4lox"
    data = os.path.join(home, "data", "plugins", ordner)
    logd = os.path.join(home, "log", "plugins", ordner)
    return {"data": data, "logd": logd, "log": os.path.join(logd, "homepod4lox.log"),
            "geraete": os.path.join(data, "geraete.json"), "letzte": os.path.join(data, "letzte.json"),
            "schlange": os.path.join(data, "warteschlange"), "audio": os.path.join(data, "audio"),
            "sperre": os.path.join(data, "lauf.sperre")}


def protokoll_einrichten(p):
    LOG.setLevel(logging.INFO)
    logging.getLogger("pyatv").setLevel(logging.ERROR)
    try:
        os.makedirs(p["logd"], exist_ok=True)
        h = logging.handlers.WatchedFileHandler(p["log"], encoding="utf-8")
    except OSError:
        h = logging.StreamHandler(sys.stderr)
    h.setFormatter(logging.Formatter("%(asctime)s %(levelname)-7s %(message)s", "%Y-%m-%d %H:%M:%S"))
    LOG.addHandler(h)


def json_lesen(pfad, vorgabe):
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            d = json.load(fh)
        return d if isinstance(d, type(vorgabe)) else vorgabe
    except (OSError, ValueError):
        return vorgabe


def json_schreiben(pfad, daten):
    """Atomar, 0600."""
    fd, tmp = tempfile.mkstemp(prefix=".neu_", dir=os.path.dirname(pfad))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(daten, fh, ensure_ascii=False)
        os.chmod(tmp, 0o600)
        os.replace(tmp, pfad)
    except OSError:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


# ---------------------------------------------------------------------------
# Reine Funktionen (Selbsttest)
# ---------------------------------------------------------------------------

def tts_teile(text, laenge=TTS_MAX):
    """Langen Text an Satzgrenzen teilen; ohne Teilung schnitte die Sprachquelle die Ansage still ab."""
    text = " ".join(str(text).split())
    if len(text) <= laenge:
        return [text] if text else []
    teile = []
    rest = text
    while rest:
        if len(rest) <= laenge:
            teile.append(rest)
            break
        schnitt = -1
        for zeichen in (". ", "! ", "? ", "; ", ", ", " "):
            k = rest.rfind(zeichen, 0, laenge)
            if k >= 0 and k + len(zeichen) - 1 > schnitt:
                schnitt = k + len(zeichen) - 1
        if schnitt <= 0:
            schnitt = laenge
        teile.append(rest[:schnitt].strip())
        rest = rest[schnitt:].strip()
    return [t for t in teile if t]


def ist_homepod(raw_modell):
    return any(str(raw_modell or "").startswith(m) for m in HOMEPOD_MODELLE)


def modell_name(raw_modell):
    r = str(raw_modell or "")
    if r.startswith("AudioAccessory5") or r.startswith("AudioAccessorySingle5"):
        return "HomePod mini"
    if r.startswith("AudioAccessory1"):
        return "HomePod"
    if r.startswith("AudioAccessory6"):
        return "HomePod (2. Generation)"
    if r.startswith("AudioAccessory"):
        return "HomePod (" + r + ")"
    return r if r else "unbekannt"


def merkmale_filtern(eigenschaften):
    aus = {}
    for k in MERKMAL_LISTE:
        if k in eigenschaften:
            aus[k] = str(eigenschaften[k])[:40]
    return aus


def geraete_zusammenfuehren(alt, neu, jetzt, ersetzen=False):
    """Bekannte Geraete bleiben mit da=0 stehen, wenn sie in dieser Suche fehlen: ein ausgeschalteter HomePod soll
    GERAETE_OFFLINE melden, nicht GERAET_UNBEKANNT. ersetzen=True baut die Liste neu auf."""
    aus = {}
    if not ersetzen:
        for g in alt:
            if isinstance(g, dict) and g.get("id"):
                h = dict(g)
                h["da"] = 0
                aus[g["id"]] = h
    for g in neu:
        h = dict(g)
        h["da"] = 1
        h["gesehen"] = jetzt
        aus[g["id"]] = h
    return sorted(aus.values(), key=lambda g: str(g.get("name", "")).lower())


def fehler_kennung(name):
    """Name der Ausnahme aus pyatv -> Kennung fuer Protokoll und Status. Unbekanntes bleibt erkennbar unbekannt."""
    tafel = {"AuthenticationError": "KOPPLUNG_NOETIG", "NoCredentialsError": "KOPPLUNG_NOETIG",
             "InvalidCredentialsError": "KOPPLUNG_NOETIG", "PairingError": "KOPPLUNG_NOETIG",
             "NotSupportedError": "NICHT_UNTERSTUETZT", "NoServiceError": "NICHT_UNTERSTUETZT",
             "ConnectionFailedError": "VERBINDUNG", "ConnectionLostError": "VERBINDUNG", "ConnectionRefusedError": "VERBINDUNG",
             "ConnectionResetError": "VERBINDUNG", "OSError": "VERBINDUNG", "RuntimeError": "VERBINDUNG",
             "TimeoutError": "ZEIT", "OperationTimeoutError": "ZEIT",
             "HttpError": "ABGELEHNT", "ProtocolError": "PROTOKOLL", "InvalidStateError": "BESCHAEFTIGT"}
    return tafel.get(name, "FEHLER_" + "".join(c for c in str(name) if c.isalnum())[:30])


def auftrag_pruefen(a, jetzt):
    """'' = ausfuehrbar, sonst die Kennung. Ein alter Auftrag verfaellt: eine Ansage von vorhin ist jetzt falsch."""
    if not isinstance(a, dict) or not isinstance(a.get("text"), str) or not a["text"].strip():
        return "AUFTRAG_KAPUTT"
    z = a.get("ziele")
    if not isinstance(z, list) or not z:
        return "AUFTRAG_KAPUTT"
    try:
        alter = jetzt - int(a.get("zeit", 0))
        frist = int(a.get("verfall_s", 120))
    except (TypeError, ValueError):
        return "AUFTRAG_KAPUTT"
    if alter > frist:
        return "VERFALLEN"
    return ""


def gesamt(ergebnisse):
    """(ok, grund) ueber alle Ziele: ok, wenn eines gespielt hat; grund '-' nur, wenn alle gespielt haben."""
    if not ergebnisse:
        return 0, "AUFTRAG_KAPUTT"
    gut = [e for e in ergebnisse if e.get("grund") == "-"]
    schlecht = [e for e in ergebnisse if e.get("grund") != "-"]
    return (1 if gut else 0), (schlecht[0]["grund"] if schlecht else "-")


def ohne_adressen(text):
    """Fehlertexte der Bibliothek helfen bei einer Rueckmeldung; Adressen und Kennungen gehoeren nicht hinein."""
    t = re.sub(r"\b\d{1,3}(\.\d{1,3}){3}\b", "<Adresse>", str(text))
    t = re.sub(r"\b[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}\b", "<Kennung>", t)
    return t[:200]


def selbsttest():
    n = [0, 0]

    def pruef(name, ist, soll):
        n[0] += 1
        if ist != soll:
            n[1] += 1
            print("FEHL %s: %r statt %r" % (name, ist, soll))

    pruef("teile kurz", tts_teile("Hallo  Welt"), ["Hallo Welt"])
    pruef("teile leer", tts_teile("   "), [])
    lang = "Das ist ein Satz. " * 30
    t = tts_teile(lang)
    pruef("teile grenze", all(len(x) <= TTS_MAX for x in t), True)
    pruef("teile vollstaendig", " ".join(t).split(), lang.split())
    pruef("teile am Satz", t[0].endswith("."), True)
    pruef("teile ohne Luecke", tts_teile("x" * 450), ["x" * 200, "x" * 200, "x" * 50])
    pruef("homepod", ist_homepod("AudioAccessory5,1"), True)
    pruef("homepod single", ist_homepod("AudioAccessorySingle5,1"), True)
    pruef("kein homepod", ist_homepod("AppleTV11,1"), False)
    pruef("kein homepod leer", ist_homepod(""), False)
    pruef("modell mini", modell_name("AudioAccessory5,1"), "HomePod mini")
    pruef("modell 1", modell_name("AudioAccessory1,1"), "HomePod")
    pruef("modell 2", modell_name("AudioAccessory6,1"), "HomePod (2. Generation)")
    pruef("modell fremd", modell_name("Sonos"), "Sonos")
    pruef("modell leer", modell_name(None), "unbekannt")
    m = merkmale_filtern({"am": "AudioAccessory5,1", "pk": "geheim", "deviceid": "AA:BB", "acl": "0", "gid": "x", "psi": "y"})
    pruef("merkmale ohne Kennung", sorted(m), ["acl", "am"])
    alt = [{"id": "a", "name": "Bad", "adresse": "x"}, {"id": "b", "name": "Kueche"}, "kaputt"]
    neu = [{"id": "b", "name": "Kueche neu"}]
    z = geraete_zusammenfuehren(alt, neu, 7)
    pruef("zusammen bleibt", [(g["id"], g["da"]) for g in z], [("a", 0), ("b", 1)])
    pruef("zusammen neu name", z[1]["name"], "Kueche neu")
    pruef("zusammen ersetzen", [g["id"] for g in geraete_zusammenfuehren(alt, neu, 7, True)], ["b"])
    pruef("kennung auth", fehler_kennung("AuthenticationError"), "KOPPLUNG_NOETIG")
    pruef("kennung http", fehler_kennung("HttpError"), "ABGELEHNT")
    pruef("kennung zeit", fehler_kennung("TimeoutError"), "ZEIT")
    pruef("kennung abbruch", fehler_kennung("RuntimeError"), "VERBINDUNG")
    pruef("kennung fremd", fehler_kennung("Etwas Neues!"), "FEHLER_EtwasNeues")
    ok = {"text": "x", "ziele": [{"id": "a"}], "zeit": 100, "verfall_s": 120}
    pruef("auftrag gut", auftrag_pruefen(ok, 150), "")
    pruef("auftrag alt", auftrag_pruefen(ok, 221), "VERFALLEN")
    pruef("auftrag grenze", auftrag_pruefen(ok, 220), "")
    pruef("auftrag ohne text", auftrag_pruefen({"ziele": [1]}, 1), "AUFTRAG_KAPUTT")
    pruef("auftrag leerer text", auftrag_pruefen({"text": " ", "ziele": [1]}, 1), "AUFTRAG_KAPUTT")
    pruef("auftrag ohne ziel", auftrag_pruefen({"text": "x", "ziele": []}, 1), "AUFTRAG_KAPUTT")
    pruef("auftrag zeit kaputt", auftrag_pruefen({"text": "x", "ziele": [1], "zeit": "a"}, 1), "AUFTRAG_KAPUTT")
    pruef("auftrag liste", auftrag_pruefen([1], 1), "AUFTRAG_KAPUTT")
    pruef("ohne adresse", ohne_adressen("an 192.0.2.7:7000 von AA:BB:CC:DD:EE:FF nicht"), "an <Adresse>:7000 von <Kennung> nicht")
    pruef("ohne adresse lang", len(ohne_adressen("x" * 500)), 200)
    pruef("gesamt gut", gesamt([{"grund": "-"}, {"grund": "-"}]), (1, "-"))
    pruef("gesamt halb", gesamt([{"grund": "-"}, {"grund": "ZEIT"}]), (1, "ZEIT"))
    pruef("gesamt schlecht", gesamt([{"grund": "ZEIT"}]), (0, "ZEIT"))
    pruef("gesamt leer", gesamt([]), (0, "AUFTRAG_KAPUTT"))
    print("%d Faelle geprueft, %d Fehlschlaege" % (n[0], n[1]))
    return 1 if n[1] else 0


# ---------------------------------------------------------------------------
# Sprachquelle -> WAV
# ---------------------------------------------------------------------------

def sprache_lokal(text, sprache, ordner):
    """espeak-ng (Rohdaten WAV); braucht kein Netz."""
    fd, tmp = tempfile.mkstemp(prefix="e_", suffix=".wav", dir=ordner)
    os.close(fd)
    try:
        try:
            lauf = subprocess.run(["espeak-ng", "-v", sprache or "de", "-w", tmp, "--stdin"], input=text.encode("utf-8"),
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=ESPEAK_FRIST_S)
        except FileNotFoundError:
            raise RuntimeError("ESPEAK_FEHLT")
        except subprocess.TimeoutExpired:
            raise RuntimeError("SPRACHQUELLE_ZEIT")
        except (OSError, subprocess.SubprocessError):
            raise RuntimeError("SPRACHQUELLE")
        if lauf.returncode != 0 or os.path.getsize(tmp) <= 44:
            raise RuntimeError("SPRACHQUELLE")
        with open(tmp, "rb") as fh:
            return [fh.read(AUDIO_MAX_BYTE)]
    finally:
        try:
            os.remove(tmp)
        except OSError:
            pass


def sprache_google(text, sprache):
    """Google Translate (MP3): keine zugesicherte Schnittstelle, der Text geht ins Internet. Nur auf ausdruecklichen Wunsch."""
    stuecke = []
    for teil in tts_teile(text):
        adresse = ("https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&tl=" + urllib.parse.quote(sprache or "de")
                   + "&q=" + urllib.parse.quote(teil))
        anfrage = urllib.request.Request(adresse, headers={"User-Agent": "Mozilla/5.0"})
        try:
            with urllib.request.urlopen(anfrage, timeout=QUELLE_FRIST_S) as antwort:
                roh = antwort.read(AUDIO_MAX_BYTE + 1)
        except (OSError, ValueError):
            raise RuntimeError("SPRACHQUELLE_NETZ")
        if not roh or len(roh) > AUDIO_MAX_BYTE:
            raise RuntimeError("SPRACHQUELLE")
        stuecke.append(roh)
    if not stuecke:
        raise RuntimeError("SPRACHQUELLE")
    return stuecke


def audio_bauen(auftrag, p):
    """Text -> eine WAV-Datei (44,1 kHz, 16 Bit, Stereo) mit stillem Vorlauf. Rueckgabe (Pfad, Dauer in s).

    miniaudio bringt pyatv ohnehin mit; es wandelt jedes Quellformat, deshalb haengen Vorlauf und Aneinanderreihen nicht an
    der Sprachquelle. Der Vorlauf ist Vorsorge: am iPad wurde der Anfang einer Ansage abgeschnitten; am HomePod ist das
    nicht gemessen.
    """
    import miniaudio
    quelle = auftrag.get("quelle", "lokal")
    sprache = auftrag.get("sprache", "de")
    os.makedirs(p["audio"], exist_ok=True)
    stuecke = sprache_google(auftrag["text"], sprache) if quelle == "google" else sprache_lokal(auftrag["text"], sprache, p["audio"])
    pcm = bytearray()
    for roh in stuecke:
        try:
            d = miniaudio.decode(bytes(roh), output_format=miniaudio.SampleFormat.SIGNED16, nchannels=KANAELE, sample_rate=RATE)
        except miniaudio.MiniaudioError:
            raise RuntimeError("SPRACHQUELLE_FORMAT")
        pcm += d.samples.tobytes()
    if not pcm:
        raise RuntimeError("SPRACHQUELLE_FORMAT")
    bild = 2 * KANAELE
    vorlauf = max(0, min(3000, int(auftrag.get("vorlauf_ms", 500))))
    still_vor = bytes(int(RATE * vorlauf / 1000) * bild)
    still_nach = bytes(int(RATE * NACHLAUF_MS / 1000) * bild)
    kennung = hashlib.sha256(str(auftrag.get("id", time.time())).encode("utf-8")).hexdigest()[:16]
    pfad = os.path.join(p["audio"], "a_" + kennung + ".wav")
    with wave.open(pfad, "wb") as w:
        w.setnchannels(KANAELE)
        w.setsampwidth(2)
        w.setframerate(RATE)
        w.writeframes(still_vor)
        w.writeframes(bytes(pcm))
        w.writeframes(still_nach)
    os.chmod(pfad, 0o600)
    return pfad, (len(still_vor) + len(pcm) + len(still_nach)) / float(RATE * bild)


# ---------------------------------------------------------------------------
# pyatv
# ---------------------------------------------------------------------------

async def geraete_suchen(frist):
    import pyatv
    from pyatv.const import Protocol
    loop = asyncio.get_running_loop()
    # AirPlay mit abfragen: erst dessen Merkmale (acl, act) sagen, ob der Zugriff beschraenkt ist.
    gefunden = await pyatv.scan(loop, timeout=frist, protocol={Protocol.RAOP, Protocol.AirPlay})
    aus = []
    for conf in gefunden:
        svc = conf.get_service(Protocol.RAOP)
        # Die Kennung des RAOP-Dienstes, nicht conf.identifier: das nimmt irgendeine aus einer Menge, und die Reihenfolge wechselt von Lauf
        # zu Lauf. Dasselbe Geraet stuende sonst nach der naechsten Suche zweimal in der Liste (am Pruefstand gemessen).
        if svc is None or not svc.identifier:
            continue
        roh = ""
        try:
            roh = conf.device_info.raw_model or ""
        except Exception:      # pyatv haelt hier keine Zusage; ein fehlendes Modell ist kein Abbruch
            roh = ""
        roh = roh or str(svc.properties.get("am", ""))
        lpt = conf.get_service(Protocol.AirPlay)
        eig = dict(svc.properties)
        if lpt is not None:
            eig.update(lpt.properties)
        aus.append({"id": str(svc.identifier), "name": str(conf.name), "modell": modell_name(roh),
                    "homepod": 1 if ist_homepod(roh) else 0, "adresse": str(conf.address), "port": int(svc.port),
                    "kopplung": getattr(svc.pairing, "name", str(svc.pairing)), "kennwort": 1 if svc.requires_password else 0,
                    "merkmale": merkmale_filtern(eig)})
    return aus


async def einmal_spielen(ziel, pfad, laut):
    """Eine Datei an ein Geraet. Rueckgabe: '-' oder die Kennung. Verbindung immer zu."""
    import pyatv
    from pyatv.const import Protocol
    from pyatv.interface import MediaMetadata
    loop = asyncio.get_running_loop()
    protos = {Protocol.RAOP, Protocol.AirPlay}
    gef = []
    if ziel.get("adresse"):
        gef = await pyatv.scan(loop, identifier=ziel["id"], hosts=[ziel["adresse"]], timeout=EINZEL_SCAN_S, protocol=protos)
    if not gef:
        gef = await pyatv.scan(loop, identifier=ziel["id"], timeout=EINZEL_SCAN2_S, protocol=protos)
    if not gef:
        return "GERAETE_OFFLINE"
    conf = gef[0]
    svc = conf.get_service(Protocol.RAOP)
    if svc is None:
        return "NICHT_UNTERSTUETZT"
    if getattr(svc.pairing, "name", "") not in KOPPLUNG_GUT:
        return "KOPPLUNG_NOETIG"
    if svc.requires_password:
        return "KENNWORT_NOETIG"
    atv = await pyatv.connect(conf, loop, protocol=Protocol.RAOP)
    try:
        if isinstance(laut, int) and 0 <= laut <= 100:
            await atv.audio.set_volume(float(laut))
        await atv.stream.stream_file(pfad, metadata=MediaMetadata(title="HomePod4Lox"))
    finally:
        offen = atv.close()
        if offen:
            await asyncio.wait(offen, timeout=3)
    return "-"


async def ziel_abspielen(ziel, pfad, laut, dauer):
    beginn = time.time()
    try:
        grund = await asyncio.wait_for(einmal_spielen(ziel, pfad, laut), dauer + SPIEL_ZUSCHLAG_S)
    except asyncio.TimeoutError:
        grund = "ZEIT"
    except Exception as fehler:      # jede Ausnahme von pyatv ist eine ehrliche Fehlmeldung, kein Absturz des Laufs
        grund = fehler_kennung(type(fehler).__name__)
        LOG.warning("%s: %s: %s", ziel.get("name", "?"), type(fehler).__name__, ohne_adressen(str(fehler)))
    return {"name": ziel.get("name", ""), "grund": grund, "sek": round(time.time() - beginn, 1)}


async def auftrag_abspielen(auftrag, pfad, dauer):
    laut = auftrag.get("laut", -1)
    return await asyncio.gather(*[ziel_abspielen(z, pfad, laut, dauer) for z in auftrag["ziele"]])


# ---------------------------------------------------------------------------
# Laufarten
# ---------------------------------------------------------------------------

def lauf_suchen(p, frist, ersetzen):
    try:
        neu = asyncio.run(asyncio.wait_for(geraete_suchen(frist), frist + 10))
    except Exception as fehler:      # Netzfehler und Fehler der Bibliothek ehrlich melden
        LOG.warning("Geraetesuche fehlgeschlagen: %s", type(fehler).__name__)
        print("SUCHE;OK=0;GRUND=%s" % fehler_kennung(type(fehler).__name__))
        return 1
    alt = json_lesen(p["geraete"], {}).get("geraete", [])
    jetzt = int(time.time())
    if not neu and alt:
        ersetzen = False      # nichts gefunden loescht nie die bekannte Liste
    liste = geraete_zusammenfuehren(alt if isinstance(alt, list) else [], neu, jetzt, ersetzen)
    os.makedirs(p["data"], exist_ok=True)
    json_schreiben(p["geraete"], {"stand": jetzt, "geraete": liste})
    hp = sum(1 for g in neu if g.get("homepod"))
    LOG.info("Geraetesuche: %d AirPlay-Geraet(e) gefunden, davon %d HomePod; in der Liste %d", len(neu), hp, len(liste))
    print("SUCHE;OK=1;GEFUNDEN=%d;HOMEPOD=%d;LISTE=%d;GRUND=-" % (len(neu), hp, len(liste)))
    return 0


def letzte_merken(p, ok, grund, ziele, laenge):
    try:
        json_schreiben(p["letzte"], {"zeit": int(time.time()), "ok": ok, "grund": grund, "ziele": ziele, "laenge": laenge})
    except OSError as fehler:
        LOG.warning("letzte.json nicht schreibbar: %s", type(fehler).__name__)


def auftrag_ausfuehren(p, datei):
    """Der Auftrag wird zuerst entfernt: ein Absturz darf nicht zur Dauerschleife werden."""
    a = json_lesen(datei, {})
    try:
        os.remove(datei)
    except OSError:
        LOG.warning("Auftrag liess sich nicht entfernen - Lauf endet")
        return False
    fehler = auftrag_pruefen(a, int(time.time()))
    laenge = len(a.get("text", "")) if isinstance(a.get("text"), str) else 0
    if fehler:
        LOG.warning("Auftrag verworfen: %s (%d Zeichen)", fehler, laenge)
        letzte_merken(p, 0, fehler, [], laenge)
        return True
    pfad = ""
    try:
        try:
            pfad, dauer = audio_bauen(a, p)
        except RuntimeError as f:
            LOG.warning("Sprachquelle: %s", f)
            letzte_merken(p, 0, str(f), [], laenge)
            return True
        except Exception as f:      # etwa eine fehlende Bibliothek: gesagt, nicht verschluckt
            LOG.error("Sprachquelle: %s", type(f).__name__)
            letzte_merken(p, 0, "SPRACHQUELLE", [], laenge)
            return True
        erg = asyncio.run(auftrag_abspielen(a, pfad, dauer))
        ok, grund = gesamt(erg)
        for e in erg:
            if e["grund"] == "-":
                LOG.info("Ansage an %s gesendet (%d Zeichen, %.1f s Audio, %.1f s gebraucht)", e["name"], laenge, dauer, e["sek"])
            else:
                LOG.warning("Ansage an %s nicht gesendet: %s (%d Zeichen, %.1f s)", e["name"], e["grund"], laenge, e["sek"])
        letzte_merken(p, ok, grund, erg, laenge)
    finally:
        if pfad:
            try:
                os.remove(pfad)
            except OSError:
                pass
    return True


def lauf_warteschlange(p):
    import fcntl
    os.makedirs(p["data"], exist_ok=True)
    signal.alarm(LAUF_MAX_S)
    with open(p["sperre"], "a") as sperre:
        for runde in range(2):
            try:
                fcntl.flock(sperre, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                return 0       # es laeuft schon einer; der nimmt den neuen Auftrag mit
            try:
                # Reste eines abgebrochenen Laufs
                for rest in glob.glob(os.path.join(p["audio"], "*.wav")):
                    try:
                        os.remove(rest)
                    except OSError:
                        pass
                while True:
                    liste = sorted(glob.glob(os.path.join(p["schlange"], "*.json")))
                    if not liste:
                        break
                    if not auftrag_ausfuehren(p, liste[0]):
                        return 1
            finally:
                fcntl.flock(sperre, fcntl.LOCK_UN)
            # Zwischen dem letzten Blick und dem Freigeben kann ein Auftrag gekommen sein, dessen Lauf an der Sperre scheiterte.
            if not glob.glob(os.path.join(p["schlange"], "*.json")):
                break
    return 0


def lauf_pruefen():
    try:
        import miniaudio
        import pyatv
        from importlib.metadata import version
    except Exception as fehler:      # die Ursache gehoert auf die Seite, auch wenn sie unerwartet ist
        print("BIBLIOTHEK;OK=0;GRUND=%s;TEXT=%s" % (type(fehler).__name__, str(fehler)[:160]))
        return 1
    print("BIBLIOTHEK;OK=1;PYATV=%s;MINIAUDIO=%s;PYTHON=%s;GRUND=-" % (version("pyatv"), miniaudio.__version__, sys.version.split()[0]))
    return 0 if pyatv else 1


def main(argv):
    if "--selbsttest" in argv:
        return selbsttest()
    if "--pruefen" in argv:
        return lauf_pruefen()
    p = pfade()
    if p is None:
        print("Es wurde kein LoxBerry-Wurzelverzeichnis gefunden; es wurde nichts getan.", file=sys.stderr)
        return 3
    os.umask(0o077)
    protokoll_einrichten(p)
    if "--suchen" in argv:
        frist = SUCHE_FRIST_S
        if "--frist" in argv and argv.index("--frist") + 1 < len(argv):
            try:
                frist = max(1, min(SUCHE_FRIST_MAX_S, int(argv[argv.index("--frist") + 1])))
            except ValueError:
                frist = SUCHE_FRIST_S
        return lauf_suchen(p, frist, "--neu" in argv)
    if "--lauf" in argv:
        return lauf_warteschlange(p)
    print("Aufruf: hp_ausgabe.py --selbsttest | --pruefen | --suchen [--frist S] [--neu] | --lauf", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
