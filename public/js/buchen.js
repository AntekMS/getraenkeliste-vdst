/**
 * VDSt Getränkeliste - Buchen (eigenes Gerät; Tablet nutzt dieselbe Logik)
 * Konfiguration über data-Attribute am Wurzelelement #buchen-app:
 * data-buchen-url, data-rueckgaengig-url, data-vorgang-id, data-csrf-token.
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
            kachel.querySelector('.js-minus').disabled = menge === 0;
            kachel.classList.toggle('hat-menge', menge > 0);
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
        if (antwort.status === 403 && !(antwort.headers.get('Content-Type') || '').includes('application/json')) {
            // CSRF-Token oder Sitzung abgelaufen: neu laden holt beides frisch
            window.location.reload();
            return null;
        }
        let json = null;
        try {
            json = await antwort.json();
        } catch (e) {
            json = null;
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
        const konto = root.querySelector('input[name="konto"]:checked').value;
        const positionen = [];
        warenkorb.forEach(function (p, id) {
            positionen.push({ artikel_id: parseInt(id, 10), menge: p.menge });
        });

        try {
            const r = await sende(buchenUrl, { vorgang_id: vorgangId, konto: konto, positionen: positionen });
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
