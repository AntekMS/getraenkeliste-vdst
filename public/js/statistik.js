// Diagramme der Statistikseiten (Einkauf, Schwund): liest canvas[data-diagramm] und zeichnet mit Chart.js.
// Die Tabelle neben dem Diagramm bleibt die zugängliche Datenquelle; der Rahmen wird erst nach erfolgreichem Zeichnen sichtbar.
(function () {
    'use strict';

    var TOKENS = ['--vdst-rot', '--grau-700', '--status-positiv', '--status-wartend', '--grau-500', '--vdst-schwarz'];
    var diagramme = [];

    function token(name, ersatz) {
        var wert = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return wert || ersatz;
    }

    function farbe(index) {
        return token(TOKENS[index % TOKENS.length], '#dc143c');
    }

    var euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
    var zahl = new Intl.NumberFormat('de-DE');

    function optionen(daten) {
        var istEuro = daten.typ === 'saeulen-gestapelt';
        var text = token('--grau-700', '#495057');
        var gitter = token('--grau-300', '#dee2e6');
        var format = function (wert) { return istEuro ? euro.format(wert) : zahl.format(wert); };
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: text } },
                tooltip: { callbacks: { label: function (k) { return k.dataset.label + ': ' + format(k.parsed.y); } } }
            },
            scales: {
                x: { stacked: istEuro, ticks: { color: text }, grid: { color: gitter } },
                y: {
                    stacked: istEuro,
                    beginAtZero: true,
                    title: { display: true, text: istEuro ? 'Euro' : 'Stück', color: text },
                    ticks: { color: text, callback: function (wert) { return format(wert); } },
                    grid: { color: gitter }
                }
            }
        };
    }

    function zeichne(eintrag) {
        var daten = eintrag.daten;
        var linie = daten.typ !== 'saeulen-gestapelt';
        if (eintrag.chart) {
            eintrag.chart.destroy();
            eintrag.chart = null;
        }
        eintrag.chart = new Chart(eintrag.canvas, {
            type: linie ? 'line' : 'bar',
            data: {
                labels: daten.labels,
                datasets: daten.reihen.map(function (reihe, i) {
                    var f = farbe(i);
                    return linie
                        ? { label: reihe.name, data: reihe.werte, borderColor: f, backgroundColor: f, tension: 0.2, pointRadius: 3 }
                        : { label: reihe.name, data: reihe.werte, backgroundColor: f };
                })
            },
            options: optionen(daten)
        });
    }

    function start() {
        if (typeof Chart === 'undefined') {
            return; // Chart.js nicht geladen: Tabelle bleibt die Darstellung, Rahmen bleibt verborgen
        }
        document.querySelectorAll('canvas[data-diagramm]').forEach(function (canvas) {
            var daten;
            try {
                daten = JSON.parse(canvas.getAttribute('data-diagramm'));
            } catch (e) {
                return;
            }
            if (!daten || !Array.isArray(daten.labels) || !Array.isArray(daten.reihen)) {
                return;
            }
            var eintrag = { canvas: canvas, daten: daten, chart: null };
            try {
                zeichne(eintrag);
            } catch (e) {
                return;
            }
            diagramme.push(eintrag);
            var rahmen = canvas.closest('.diagramm-rahmen');
            if (rahmen) {
                rahmen.hidden = false;
            }
        });

        // Theme-Wechsel (app.js setzt data-bs-theme): Farben neu lesen und neu zeichnen
        new MutationObserver(function () {
            diagramme.forEach(function (eintrag) {
                try { zeichne(eintrag); } catch (e) { /* Darstellung bleibt wie sie ist */ }
            });
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
