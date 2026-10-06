// Lieferung: weitere Zeilen aus der <template>-Vorlage anfügen (Platzhalter __I__ = laufender Index).
(function () {
    'use strict';

    var knopf = document.getElementById('lieferung-zeile-hinzufuegen');
    var vorlage = document.getElementById('lieferung-vorlage');
    var ziel = document.getElementById('lieferung-zeilen');

    if (!knopf || !vorlage || !ziel) {
        return;
    }

    knopf.addEventListener('click', function () {
        var index = parseInt(knopf.dataset.naechsterIndex, 10) || 0;
        var html = vorlage.innerHTML.split('__I__').join(String(index));

        ziel.insertAdjacentHTML('beforeend', html);
        knopf.dataset.naechsterIndex = String(index + 1);
    });
}());
