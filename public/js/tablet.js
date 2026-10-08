/**
 * VDSt Getränkeliste - Tablet: Namensauswahl (#tablet-namen) und PIN-Eingabe (#tablet-pin).
 * Konfiguration über data-Attribute; keine Inline-Handler.
 */
(function () {
    'use strict';

    // Nach so vielen Sekunden ohne Eingabe: tun() (Namensauswahl: neu laden, PIN: zurück zu den Namen)
    function beiLeerlauf(sekunden, tun) {
        let timer = null;
        const neu = function () {
            clearTimeout(timer);
            timer = setTimeout(tun, sekunden * 1000);
        };
        ['pointerdown', 'keydown', 'touchstart', 'input'].forEach(function (name) {
            document.addEventListener(name, neu, { passive: true });
        });
        neu();
    }

    const namen = document.getElementById('tablet-namen');
    if (namen) {
        const suche = namen.querySelector('.js-suche');
        const zuletzt = namen.querySelector('.js-zuletzt');
        const alle = namen.querySelector('.js-alle');
        const keine = namen.querySelector('.js-keine-treffer');
        const filterRadios = namen.querySelectorAll('.js-filter');

        const aktuellerFilter = function () {
            return namen.querySelector('.js-filter:checked').value;
        };

        function anwenden() {
            const text = suche.value.trim().toLowerCase();
            const filter = aktuellerFilter();
            // Ohne Suche zeigt „Zuletzt“ die zuletzt Aktiven; mit Suche wird in allen durchsucht
            // (sonstige erscheinen nur unter „Alle“).
            const listeAn = filter !== 'zuletzt' || text !== '';
            zuletzt.hidden = listeAn;
            alle.hidden = !listeAn;

            let sichtbar = 0;
            alle.querySelectorAll('.namen-kachel-form').forEach(function (kachel) {
                const gruppe = kachel.dataset.gruppe;
                const gruppeOk = filter === 'alle' || (filter === 'aktiv' ? gruppe === 'aktiv'
                    : filter === 'ah' ? gruppe === 'ah' : gruppe !== 'sonstige');
                const treffer = gruppeOk && (text === '' || kachel.dataset.name.indexOf(text) !== -1);
                kachel.hidden = !treffer;
                if (treffer) {
                    sichtbar += 1;
                }
            });
            keine.hidden = !listeAn || sichtbar > 0;
        }

        suche.addEventListener('input', anwenden);
        filterRadios.forEach(function (radio) {
            radio.addEventListener('change', anwenden);
        });
        anwenden();

        // Review Focus 2: Tablet über Nacht - Seite (und damit Session/CSRF-Token) alle 10 min im Leerlauf neu laden.
        beiLeerlauf(parseInt(namen.dataset.reloadS, 10) || 600, function () {
            window.location.reload();
        });
    }

    const pin = document.getElementById('tablet-pin');
    if (pin) {
        const feld = pin.querySelector('.js-pin');
        const weiter = pin.querySelector('.js-weiter');

        const aktualisieren = function () {
            weiter.disabled = feld.value.length < 4;
        };

        pin.addEventListener('click', function (ereignis) {
            const ziffer = ereignis.target.closest('.js-ziffer');
            if (ziffer && feld.value.length < 6) {
                feld.value += ziffer.dataset.ziffer;
            } else if (ereignis.target.closest('.js-loeschen')) {
                feld.value = feld.value.slice(0, -1);
            }
            aktualisieren();
        });
        aktualisieren();

        beiLeerlauf(parseInt(pin.dataset.idleS, 10) || 30, function () {
            window.location.href = pin.dataset.zurueckUrl;
        });
    }
})();
