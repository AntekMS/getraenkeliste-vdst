/**
 * VDSt Getränkeliste - Buchen (eigenes Gerät; Tablet nutzt dieselbe Logik)
 * Konfiguration über data-Attribute am Wurzelelement #buchen-app:
 * data-buchen-url, data-rueckgaengig-url, data-vorgang-id, data-csrf-token.
 * Tablet-Modus (data-fertig-url gesetzt): zusätzlich data-timeout-s; nach so vielen Sekunden ohne Eingabe
 * und nach der Bestätigung (höchstens 10 s) wird das Formular .js-fertig-form (POST tablet/fertig) abgeschickt.
 */
(function () {
    'use strict';

    const root = document.getElementById('buchen-app');
    if (!root) {
        return;
    }

    const buchenUrl = root.dataset.buchenUrl;
    const rueckgaengigUrl = root.dataset.rueckgaengigUrl;
    let vorgangId = root.dataset.vorgangId;
    let csrfToken = root.dataset.csrfToken;
    const fertigUrl = root.dataset.fertigUrl || null; // gesetzt = Tablet-Modus
    const timeoutS = parseInt(root.dataset.timeoutS, 10) || 30;
    const fertigForm = root.querySelector('.js-fertig-form');
    let leerlaufTimer = null;
    let zurueckTimer = null;

    // artikel_id -> {name, preis, menge}
    const warenkorb = new Map();
    let laeuft = false;
    let letzterVorgang = null; // vorgang_id der angezeigten Bestätigung (für Rückgängig)

    const el = function (selektor) {
        return root.querySelector(selektor);
    };
    const buchenButton = el('.js-buchen');
    const spinner = el('.js-spinner');
    const icon = el('.js-buchen-icon');
    const fehlerBox = el('.js-fehler');
    const bestaetigung = el('.js-bestaetigung');
    const rueckgaengigButton = el('.js-rueckgaengig');
    const zurueckInfo = el('.js-zurueck-info');

    // Zurück zur Namensauswahl per Formular-POST (CSRF und Redirect wie bei Abbrechen), nicht per fetch.
    function fertig() {
        if (fertigForm) {
            fertigForm.submit();
        }
    }

    function leerlaufNeu() {
        clearTimeout(leerlaufTimer);
        leerlaufTimer = setTimeout(fertig, timeoutS * 1000);
    }

    function zurueckStoppen() {
        clearInterval(zurueckTimer);
        zurueckTimer = null;
        if (zurueckInfo) {
            zurueckInfo.hidden = true;
        }
    }

    // Nach der Bestätigung: Anzeige timeout_s Sekunden, höchstens 10 s, dann zurück.
    function zurueckStarten() {
        zurueckStoppen();
        let rest = Math.min(timeoutS, 10);
        el('.js-zurueck-sekunden').textContent = String(rest);
        zurueckInfo.hidden = false;
        zurueckTimer = setInterval(function () {
            rest -= 1;
            if (rest <= 0) {
                zurueckStoppen();
                fertig();
                return;
            }
            el('.js-zurueck-sekunden').textContent = String(rest);
        }, 1000);
    }

    if (fertigUrl && fertigForm) {
        fertigForm.action = fertigUrl;
        ['pointerdown', 'keydown', 'touchstart'].forEach(function (name) {
            document.addEventListener(name, leerlaufNeu, { passive: true });
        });
        leerlaufNeu();
    }

    // Ohne buchbare Artikel gibt es keine Bedienelemente (nur der Leerlauf-Rücksprung oben).
    if (!buchenButton) {
        return;
    }

    const euro = function (cent) {
        return (cent / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
    };

    function zeigeFehler(text) {
        fehlerBox.textContent = text;
        fehlerBox.hidden = !text;
    }

    function render() {
        let summe = 0;
        const liste = el('.js-positionen');
        liste.replaceChildren();

        warenkorb.forEach(function (p) {
            summe += p.menge * p.preis;
            const li = document.createElement('li');
            li.className = 'd-flex justify-content-between';
            const links = document.createElement('span');
            links.textContent = p.menge + '× ' + p.name;
            const rechts = document.createElement('span');
            rechts.textContent = euro(p.menge * p.preis);
            li.append(links, rechts);
            liste.append(li);
        });

        liste.hidden = warenkorb.size === 0;
        el('.js-leer').hidden = warenkorb.size > 0;
        el('.js-summe').textContent = euro(summe);
        buchenButton.disabled = laeuft || warenkorb.size === 0;

        root.querySelectorAll('.artikel-kachel').forEach(function (kachel) {
            const p = warenkorb.get(kachel.dataset.artikelId);
            const menge = p ? p.menge : 0;
            kachel.querySelector('.js-menge').textContent = String(menge);
            kachel.querySelector('.js-minus').disabled = laeuft || menge === 0;
            kachel.querySelector('.js-plus').disabled = laeuft;
            kachel.classList.toggle('hat-menge', menge > 0);
        });
        // Während einer Buchung ist der Warenkorb gesperrt, sonst ginge Hinzugefügtes beim Leeren verloren.
        root.querySelectorAll('input[name="konto"]').forEach(function (radio) {
            radio.disabled = laeuft;
        });
    }

    function aendere(kachel, delta) {
        const id = kachel.dataset.artikelId;
        const p = warenkorb.get(id) || { name: kachel.dataset.name, preis: parseInt(kachel.dataset.preisCent, 10), menge: 0 };
        p.menge = Math.min(99, Math.max(0, p.menge + delta));
        if (p.menge === 0) {
            warenkorb.delete(id);
        } else {
            warenkorb.set(id, p);
        }
        bestaetigung.hidden = true;
        zurueckStoppen();
        render();
    }

    function setzeLaeuft(an) {
        laeuft = an;
        spinner.hidden = !an;
        icon.hidden = an;
        rueckgaengigButton.disabled = an;
        render();
    }

    async function sende(url, daten) {
        const antwort = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify(daten)
        });
        let json = null;
        try {
            json = await antwort.json();
        } catch (e) {
            json = null;
        }
        // Eigene 403 des Controllers tragen ok:false; der CSRF-Fehler (Framework-JSON) nicht.
        // Dann sind Token oder Sitzung abgelaufen: neu laden holt beides frisch.
        if (antwort.status === 403 && !(json && json.ok === false)) {
            window.location.reload();
            return null;
        }
        if (json && json.csrf_hash) {
            csrfToken = json.csrf_hash;
        }
        return { status: antwort.status, json: json };
    }

    async function buchen() {
        if (laeuft || warenkorb.size === 0) {
            return;
        }
        zeigeFehler('');
        setzeLaeuft(true);
        // Am Tablet bestimmt die Namenskachel das Konto (keine Auswahl, kein Feld).
        const kontoWahl = root.querySelector('input[name="konto"]:checked');
        const positionen = [];
        warenkorb.forEach(function (p, id) {
            positionen.push({ artikel_id: parseInt(id, 10), menge: p.menge });
        });

        try {
            const daten = { vorgang_id: vorgangId, positionen: positionen };
            if (kontoWahl) {
                daten.konto = kontoWahl.value;
            }
            const r = await sende(buchenUrl, daten);
            if (r === null) {
                return;
            }
            if (r.json && r.json.ok) {
                zeigeBestaetigung(r.json, vorgangId);
                vorgangId = r.json.naechste_vorgang_id || vorgangId;
                warenkorb.clear();
            } else {
                // Warenkorb und vorgang_id bleiben: ein erneuter Versuch ist idempotent
                zeigeFehler((r.json && r.json.meldung) || 'Nicht gebucht – bitte erneut versuchen');
            }
        } catch (e) {
            zeigeFehler('Nicht gebucht – bitte erneut versuchen');
        } finally {
            setzeLaeuft(false);
        }
    }

    function zeigeBestaetigung(json, gebuchterVorgang) {
        const storniert = json.storniert === true;
        el('.js-bestaetigung-text').textContent = storniert ? json.meldung : 'Gebucht: ' + json.zusammenfassung;
        bestaetigung.classList.toggle('alert-success', !storniert);
        bestaetigung.classList.toggle('alert-info', storniert);
        rueckgaengigButton.hidden = storniert;
        letzterVorgang = storniert ? null : gebuchterVorgang;
        bestaetigung.hidden = false;
        if (fertigUrl) {
            zurueckStarten();
        }
    }

    async function rueckgaengig() {
        if (laeuft || !letzterVorgang) {
            return;
        }
        zeigeFehler('');
        setzeLaeuft(true);
        try {
            const r = await sende(rueckgaengigUrl, { vorgang_id: letzterVorgang });
            if (r === null) {
                return;
            }
            if (r.json && r.json.ok) {
                el('.js-bestaetigung-text').textContent = r.json.meldung;
                bestaetigung.classList.remove('alert-success');
                bestaetigung.classList.add('alert-info');
                rueckgaengigButton.hidden = true;
                letzterVorgang = null;
                zurueckStoppen();
            } else {
                zeigeFehler((r.json && r.json.meldung) || 'Rückgängig nicht möglich – bitte erneut versuchen');
            }
        } catch (e) {
            zeigeFehler('Rückgängig nicht möglich – bitte erneut versuchen');
        } finally {
            setzeLaeuft(false);
        }
    }

    root.addEventListener('click', function (ereignis) {
        if (laeuft) {
            return;
        }
        const kachel = ereignis.target.closest('.artikel-kachel');
        if (kachel && ereignis.target.closest('.js-plus')) {
            aendere(kachel, 1);
        } else if (kachel && ereignis.target.closest('.js-minus')) {
            aendere(kachel, -1);
        }
    });
    buchenButton.addEventListener('click', buchen);
    rueckgaengigButton.addEventListener('click', rueckgaengig);
    render();
})();
