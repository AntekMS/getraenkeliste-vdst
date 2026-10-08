// Auszählung: Kisten/einzeln zu Stück, Abweichung je Artikel, Fortschritt und Geldsumme in der Aktionsleiste live berechnen;
// „Abschließen“ erst, wenn alle Artikel gezählt sind; Rückfrage beim Stichtag-Wechsel mit ungespeicherten Zählwerten.
// Darstellung wie die serverseitige Vorabanzeige in app/Views/wart/auszaehlung_formular.php (die Startanzeige bleibt unangetastet).
// Bei einer Eingabe wird nur die geänderte Zeile neu geschrieben (aria-live-Zellen sagen sonst unveränderte Zeilen erneut an).
(function () {
    'use strict';

    var formular = document.getElementById('auszaehlung-form');

    if (!formular) {
        return;
    }

    var MAX_STUECK = 999999;
    var DANACH = 'Danach sind alle Buchungen bis zum Stichtag abgerechnet und können nicht mehr geändert werden.';
    var zeilen = Array.prototype.slice.call(formular.querySelectorAll('.js-zeile'));
    var fortschritt = document.getElementById('auszaehlung-fortschritt');
    var summeAnzeige = document.getElementById('auszaehlung-summe');
    var abschliessen = document.getElementById('abschliessen');
    var hinweis = document.getElementById('abschliessen-hinweis');
    // Nach einem Validierungsfehler stehen getippte, noch nicht gespeicherte Werte im Formular.
    var ungespeichert = formular.dataset.ungespeichert === '1';
    // Abweichung je Zeile (null = nicht gezählt), Grundlage für Fortschritt und Summe.
    var differenzen = [];

    // Leer → null; keine ganze Zahl ≥ 0 oder mehr als 6 Stellen → NaN.
    function zahl(feld) {
        var wert = feld ? feld.value.trim() : '';

        if (wert === '') {
            return null;
        }

        return /^\d{1,6}$/.test(wert) ? parseInt(wert, 10) : NaN;
    }

    // Gezählte Stückzahl der Zeile: null = nichts eingetragen, NaN = ungültig (inkl. > 999 999).
    function gezaehlt(zeile) {
        var stueck;

        if (!zeile.dataset.gebinde) {
            stueck = zahl(zeile.querySelector('.js-ist'));
        } else {
            var kisten = zahl(zeile.querySelector('.js-kisten'));
            var einzeln = zahl(zeile.querySelector('.js-einzeln'));

            if (kisten === null && einzeln === null) {
                return null;
            }

            stueck = (kisten || 0) * parseInt(zeile.dataset.gebinde, 10) + (einzeln || 0);
        }

        return stueck !== null && stueck > MAX_STUECK ? NaN : stueck;
    }

    function differenz(stueck, zeile) {
        return stueck === null || isNaN(stueck) ? null : stueck - parseInt(zeile.dataset.soll, 10);
    }

    function euro(cent) {
        var vorzeichen = cent < 0 ? '−' : (cent > 0 ? '+' : '');
        var betrag = Math.abs(cent);
        var ganz = String(Math.floor(betrag / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        var rest = String(betrag % 100);

        return vorzeichen + ganz + ',' + (rest.length < 2 ? '0' + rest : rest) + ' €';
    }

    function abweichungAnzeige(ziel, wert) {
        ziel.textContent = '';

        if (wert === null) {
            var strich = document.createElement('span');
            strich.className = 'text-muted';
            strich.textContent = '–';
            ziel.appendChild(strich);
            return;
        }

        var art;

        if (wert === 0) {
            art = ['badge-status-gruen', 'bi-check-circle', 'stimmt'];
        } else if (wert < 0) {
            art = ['badge-status-rot', 'bi-dash-circle', wert === -1 ? '1 fehlt' : String(-wert) + ' fehlen'];
        } else {
            art = ['badge-status-amber', 'bi-plus-circle', String(wert) + ' zu viel'];
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

    function setzeText(element, text) {
        if (element && element.textContent !== text) {
            element.textContent = text;
        }
    }

    // Nur die bearbeitete Zeile: Felder markieren, „= N Stück“ und Abweichung schreiben, wenn sie sich ändern.
    function aktualisiereZeile(index) {
        var zeile = zeilen[index];
        var stueck = gezaehlt(zeile);
        var neu = differenz(stueck, zeile);

        zeile.querySelectorAll('.js-ist, .js-kisten, .js-einzeln').forEach(function (feld) {
            feld.classList.toggle('is-invalid', isNaN(stueck));
        });

        setzeText(zeile.querySelector('.js-stueck'), stueck === null || isNaN(stueck) ? '' : '= ' + stueck + ' Stück');

        if (neu !== differenzen[index]) {
            differenzen[index] = neu;
            abweichungAnzeige(zeile.querySelector('.js-abweichung'), neu);
        }
    }

    function aktualisiereLeiste() {
        var anzahl = 0;
        var abweichend = 0;
        var cent = 0;

        differenzen.forEach(function (wert, index) {
            if (wert === null) {
                return;
            }

            anzahl++;

            // „Neu“-Artikel: Abweichung zählt nicht als Schwund – weder in Anzahl noch Summe (Zeile zeigt sie trotzdem).
            if (wert !== 0 && zeilen[index].dataset.neu !== '1') {
                abweichend++;
                cent += wert * parseInt(zeilen[index].dataset.preis, 10);
            }
        });

        var alle = anzahl === zeilen.length;

        setzeText(fortschritt, anzahl + ' von ' + zeilen.length + ' gezählt');
        setzeText(summeAnzeige, abweichend === 0 ? 'keine Abweichung' : 'Abweichung: ' + euro(cent));

        abschliessen.disabled = !alle;
        hinweis.classList.toggle('d-none', alle);
        abschliessen.dataset.confirm = 'Auszählung jetzt abschließen? ' + (abweichend === 0
            ? (alle ? 'Alle Artikel stimmen. ' : 'Alle gezählten Artikel stimmen. ')
            : abweichend + ' Artikel ' + (abweichend === 1 ? 'weicht' : 'weichen') + ' ab (zusammen ' + euro(cent) + '). ') + DANACH;
    }

    formular.addEventListener('input', function (ereignis) {
        var ziel = ereignis.target;

        if (ziel.matches('.js-ist, .js-kisten, .js-einzeln, #bemerkung')) {
            ungespeichert = true;
        }

        if (ziel.matches('.js-ist, .js-kisten, .js-einzeln')) {
            var index = zeilen.indexOf(ziel.closest('.js-zeile'));

            if (index >= 0) {
                aktualisiereZeile(index);
                aktualisiereLeiste();
            }
        }
    });

    formular.addEventListener('submit', function () {
        ungespeichert = false;
    });

    // Startzustand nur lesen (die Zeilen sind serverseitig vorgerendert), dann die Leiste setzen.
    zeilen.forEach(function (zeile, index) {
        differenzen[index] = differenz(gezaehlt(zeile), zeile);
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
