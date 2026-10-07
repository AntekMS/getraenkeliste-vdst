// Auszählung: Differenz (Ist - Soll) live berechnen und den Stichtag ins Speichern-Formular übernehmen.
(function () {
    'use strict';

    var formular = document.getElementById('auszaehlung-form');

    if (!formular) {
        return;
    }

    function aktualisiere(feld) {
        var zeile = feld.closest('tr');
        var ziel = zeile ? zeile.querySelector('.js-differenz') : null;

        if (!ziel) {
            return;
        }

        var wert = feld.value.trim();

        if (!/^\d+$/.test(wert)) {
            ziel.textContent = '';
            return;
        }

        var differenz = parseInt(wert, 10) - parseInt(zeile.dataset.soll, 10);

        ziel.textContent = (differenz > 0 ? '+' : '') + String(differenz);
    }

    formular.addEventListener('input', function (ereignis) {
        if (ereignis.target.classList.contains('auszaehlung-ist')) {
            aktualisiere(ereignis.target);
        }
    });

    var anzeige = document.getElementById('stichtag');
    var senden = document.getElementById('stichtag-senden');

    if (anzeige && senden) {
        formular.addEventListener('submit', function () {
            senden.value = anzeige.value;
        });
    }
}());
