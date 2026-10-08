// Auszählung: Kisten/einzeln zu Stück, Abweichung je Artikel, Fortschritt und Geldsumme in der Aktionsleiste live berechnen;
// „Abschließen“ erst, wenn alle Artikel gezählt sind; Rückfrage beim Stichtag-Wechsel mit ungespeicherten Zählwerten.
// Darstellung wie die serverseitige Vorabanzeige in app/Views/wart/auszaehlung_formular.php.
(function () {
    'use strict';

    var formular = document.getElementById('auszaehlung-form');

    if (!formular) {
        return;
    }

    var DANACH = 'Danach sind alle Buchungen bis zum Stichtag abgerechnet und können nicht mehr geändert werden.';
    var zeilen = Array.prototype.slice.call(formular.querySelectorAll('.js-zeile'));
    var fortschritt = document.getElementById('auszaehlung-fortschritt');
    var summeAnzeige = document.getElementById('auszaehlung-summe');
    var abschliessen = document.getElementById('abschliessen');
    var hinweis = document.getElementById('abschliessen-hinweis');
    var ungespeichert = false;

    // Leer → null, keine ganze Zahl ≥ 0 → NaN.
    function zahl(feld) {
        var wert = feld ? feld.value.trim() : '';

        if (wert === '') {
            return null;
        }

        return /^\d+$/.test(wert) ? parseInt(wert, 10) : NaN;
    }

    // Gezählte Stückzahl der Zeile oder null (nichts oder Ungültiges eingetragen).
    function gezaehlt(zeile) {
        if (!zeile.dataset.gebinde) {
            var ist = zahl(zeile.querySelector('.js-ist'));

            return ist === null || isNaN(ist) ? null : ist;
        }

        var kisten = zahl(zeile.querySelector('.js-kisten'));
        var einzeln = zahl(zeile.querySelector('.js-einzeln'));

        if ((kisten === null && einzeln === null) || isNaN(kisten) || isNaN(einzeln)) {
            return null;
        }

        return (kisten || 0) * parseInt(zeile.dataset.gebinde, 10) + (einzeln || 0);
    }

    function euro(cent) {
        var vorzeichen = cent < 0 ? '−' : (cent > 0 ? '+' : '');
        var betrag = Math.abs(cent);
        var ganz = String(Math.floor(betrag / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        var rest = String(betrag % 100);

        return vorzeichen + ganz + ',' + (rest.length < 2 ? '0' + rest : rest) + ' €';
    }

    function abweichungAnzeige(ziel, differenz) {
        ziel.textContent = '';

        if (differenz === null) {
            var strich = document.createElement('span');
            strich.className = 'text-muted';
            strich.textContent = '–';
            ziel.appendChild(strich);
            return;
        }

        var art;

        if (differenz === 0) {
            art = ['badge-status-gruen', 'bi-check-circle', 'stimmt'];
        } else if (differenz < 0) {
            art = ['badge-status-rot', 'bi-dash-circle', differenz === -1 ? '1 fehlt' : String(-differenz) + ' fehlen'];
        } else {
            art = ['badge-status-amber', 'bi-plus-circle', String(differenz) + ' zu viel'];
        }

        var badge = document.createElement('span');
        var icon = document.createElement('i');
        badge.className = 'badge-status ' + art[0];
        icon.className = 'bi ' + art[1] + ' me-1';
        icon.setAttribute('aria-hidden', 'true');
        badge.appendChild(icon);
        badge.appendChild(document.createTextNode(art[2]));
        ziel.appendChild(badge);
    }

    function aktualisiereZeile(zeile) {
        var stueck = gezaehlt(zeile);
        var differenz = stueck === null ? null : stueck - parseInt(zeile.dataset.soll, 10);
        var stueckAnzeige = zeile.querySelector('.js-stueck');

        if (stueckAnzeige) {
            stueckAnzeige.textContent = stueck === null ? '' : '= ' + stueck + ' Stück';
        }

        abweichungAnzeige(zeile.querySelector('.js-abweichung'), differenz);

        return differenz;
    }

    function aktualisiereLeiste() {
        var anzahl = 0;
        var abweichend = 0;
        var cent = 0;

        zeilen.forEach(function (zeile) {
            var differenz = aktualisiereZeile(zeile);

            if (differenz === null) {
                return;
            }

            anzahl++;
            abweichend += differenz === 0 ? 0 : 1;
            cent += differenz * parseInt(zeile.dataset.preis, 10);
        });

        var alle = anzahl === zeilen.length;

        fortschritt.textContent = anzahl + ' von ' + zeilen.length + ' gezählt';
        summeAnzeige.textContent = abweichend === 0 ? 'keine Abweichung' : 'Abweichung: ' + euro(cent);

        abschliessen.disabled = !alle;
        hinweis.classList.toggle('d-none', alle);
        abschliessen.dataset.confirm = 'Auszählung jetzt abschließen? ' + (abweichend === 0
            ? 'Alle Artikel stimmen. '
            : abweichend + ' Artikel ' + (abweichend === 1 ? 'weicht' : 'weichen') + ' ab (zusammen ' + euro(cent) + '). ') + DANACH;
    }

    formular.addEventListener('input', function (ereignis) {
        if (ereignis.target.matches('.js-ist, .js-kisten, .js-einzeln, #bemerkung')) {
            ungespeichert = true;
        }

        if (ereignis.target.matches('.js-ist, .js-kisten, .js-einzeln')) {
            aktualisiereLeiste();
        }
    });

    formular.addEventListener('submit', function () {
        ungespeichert = false;
    });

    aktualisiereLeiste();

    // Der geladene Stichtag (verstecktes Feld) gilt; bei Abweichung erscheint ein Hinweis.
    var anzeige = document.getElementById('stichtag');
    var stichtagHinweis = document.getElementById('stichtag-hinweis');
    var stichtagFormular = document.getElementById('stichtag-form');

    if (anzeige && stichtagHinweis) {
        anzeige.addEventListener('input', function () {
            stichtagHinweis.classList.toggle('d-none', anzeige.value === anzeige.dataset.geladen);
        });
    }

    // Stichtag ändern lädt die Seite neu: ungespeicherte Zählwerte gingen verloren.
    if (stichtagFormular) {
        stichtagFormular.addEventListener('submit', function (ereignis) {
            if (ungespeichert && !window.confirm('Stichtag ändern? Deine noch nicht gespeicherten Zählwerte gehen dabei verloren. Speichere vorher den Entwurf, wenn du sie behalten willst.')) {
                ereignis.preventDefault();
            }
        });
    }
}());
