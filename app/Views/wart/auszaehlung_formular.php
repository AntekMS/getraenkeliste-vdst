<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Auszählung<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$feld    = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? ' is-invalid' : '';
$meldung = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? '<div class="invalid-feedback d-block">' . esc($fehler[$schluessel]) . '</div>' : '';
$basis   = base_url('wart/' . $bereich['schluessel'] . '/auszaehlung');

// Gleiche Darstellung wie public/js/auszaehlung.js (dort live nachgerechnet).
$euro = static fn (int $cent): string => ($cent < 0 ? '−' : ($cent > 0 ? '+' : '')) . formatiere_cent(abs($cent));
$abweichung = static function (?int $differenz): string {
    if ($differenz === null) {
        return '<span class="text-muted">–</span>';
    }

    [$klasse, $icon, $text] = match (true) {
        $differenz === 0 => ['badge-status-gruen', 'bi-check-circle', 'stimmt'],
        $differenz < 0   => ['badge-status-rot', 'bi-dash-circle', -$differenz === 1 ? '1 fehlt' : (-$differenz) . ' fehlen'],
        default          => ['badge-status-amber', 'bi-plus-circle', $differenz . ' zu viel'],
    };

    return '<span class="badge-status ' . $klasse . '"><i class="bi ' . $icon . ' me-1" aria-hidden="true"></i>' . esc($text) . '</span>';
};
$danach    = 'Danach sind alle Buchungen bis zum Stichtag abgerechnet und können nicht mehr geändert werden.';
$alleGezaehlt = $summe['gezaehlt'] === $summe['artikel'];
// Abweichende Anzahl/Summe ohne „neu“-Artikel (deren Abweichung zählt nicht als Schwund).
$bestaetig = 'Auszählung jetzt abschließen? ' . ($summe['abweichend'] === 0
    ? ($alleGezaehlt ? 'Alle Artikel stimmen. ' : 'Alle gezählten Artikel stimmen. ')
    : $summe['abweichend'] . ' Artikel ' . ($summe['abweichend'] === 1 ? 'weicht' : 'weichen') . ' ab (zusammen ' . $euro($summe['cent']) . '). ') . $danach;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h3 mb-0">Auszählung – <?= esc($bereich['name']) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/auszaehlungen') ?>">Abgeschlossene Auszählungen</a>
        <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/einkauf') ?>">Zurück zum Einkauf</a>
    </div>
</div>
<p class="text-muted mb-3">Zähle, was wirklich da ist, und trag es hier ein. Mit „Abschließen“ wird der Zeitraum abgerechnet und die Excel für den Kassenwart erstellt.</p>

<?php if ($hatEntwurf): ?>
    <div class="alert alert-info" role="status">Du hast einen gespeicherten Entwurf. Zähl einfach weiter, wo du aufgehört hast.</div>
<?php endif; ?>

<h2 class="h5 mt-4">1. Stichtag</h2>
<form action="<?= esc($basis) ?>" method="get" class="row g-2 align-items-end mb-4" id="stichtag-form">
    <div class="col-md-5">
        <label for="stichtag" class="form-label">Stichtag</label>
        <input type="datetime-local" class="form-control auszaehlung-stichtag<?= $feld('stichtag') ?>" id="stichtag" name="stichtag" value="<?= esc($stichtag) ?>" data-geladen="<?= esc($stichtag) ?>" aria-describedby="stichtag-hilfe" required>
        <div class="form-text text-warning-emphasis d-none" id="stichtag-hinweis">Bitte „Stichtag ändern“ klicken, damit die Zahlen „Laut System“ neu berechnet werden.</div>
        <?= $meldung('stichtag') ?>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-vdst auszaehlung-stichtag">Stichtag ändern</button>
    </div>
    <div class="col-12 form-text" id="stichtag-hilfe">Bis zu diesem Zeitpunkt zählen alle Buchungen mit. Meist: jetzt.</div>
</form>

<h2 class="h5 mt-4">2. Zählen</h2>
<form action="<?= esc($basis) ?>" method="post" id="auszaehlung-form"<?= $nachFehler ? ' data-ungespeichert="1"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="stichtag" id="stichtag-senden" value="<?= esc($stichtag) ?>">

    <?php if ($gruppen === []): ?>
        <p class="text-muted">Noch keine Artikel zum Zählen. Lege unter „Getränke &amp; Preise“ Artikel mit Bestandsführung an.</p>
    <?php endif; ?>

    <?php if ($summe['neu']): ?>
        <p class="form-text"><span class="badge-status badge-status-neutral me-1">neu</span>Neu: Für diesen Artikel gab es noch keine Zählung. Die Abweichung zählt diesmal nicht als Schwund.</p>
    <?php endif; ?>

    <?php foreach ($gruppen as $gruppe): ?>
        <h3 class="h6 mt-4"><?= esc($gruppe['kategorie_name']) ?> <span class="text-muted fw-normal">(<?= count($gruppe['positionen']) ?> Artikel)</span></h3>
        <table class="table align-middle table-stack auszaehlung-tabelle">
            <thead>
                <tr>
                    <th>Artikel</th>
                    <th class="text-end">Laut System</th>
                    <th class="text-end">Gezählt</th>
                    <th class="text-end">Abweichung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gruppe['positionen'] as $p): ?>
                    <?php $id = (int) $p['artikel_id']; ?>
                    <tr class="js-zeile" data-soll="<?= esc((string) $p['soll']) ?>" data-preis="<?= esc((string) $p['preis_cent']) ?>"<?= $p['start'] ? ' data-neu="1"' : '' ?><?= $p['gebinde'] === null ? '' : ' data-gebinde="' . esc((string) $p['gebinde']) . '"' ?>>
                        <td data-label="Artikel">
                            <span>
                                <?= esc($p['name']) ?> <span class="text-muted small"><?= esc($p['einheit']) ?></span>
                                <?php if ($p['start']): ?><span class="badge-status badge-status-neutral ms-1" title="Für diesen Artikel gab es noch keine Zählung. Die Abweichung zählt diesmal nicht als Schwund.">neu</span><?php endif; ?>
                            </span>
                        </td>
                        <td data-label="Laut System" class="text-end"><?= esc((string) $p['soll']) ?></td>
                        <td data-label="Gezählt" class="text-end">
                            <div class="auszaehlung-eingaben">
                                <?php if ($p['gebinde'] === null): ?>
                                    <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control text-end auszaehlung-eingabe js-ist<?= $feld("ist.{$id}") ?>"
                                           name="ist[<?= $id ?>]" value="<?= esc($p['wert']) ?>" aria-label="Gezählt: <?= esc($p['name']) ?>">
                                <?php else: ?>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <div>
                                            <label class="form-label small mb-0" for="kisten-<?= $id ?>">Kisten</label>
                                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control text-end auszaehlung-eingabe auszaehlung-eingabe-gebinde js-kisten<?= $feld("ist.{$id}") ?>"
                                                   id="kisten-<?= $id ?>" name="kisten[<?= $id ?>]" value="<?= esc($p['kisten']) ?>" aria-label="Kisten: <?= esc($p['name']) ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small mb-0" for="einzeln-<?= $id ?>">einzeln</label>
                                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control text-end auszaehlung-eingabe auszaehlung-eingabe-gebinde js-einzeln<?= $feld("ist.{$id}") ?>"
                                                   id="einzeln-<?= $id ?>" name="einzeln[<?= $id ?>]" value="<?= esc($p['einzeln']) ?>" aria-label="einzeln: <?= esc($p['name']) ?>">
                                        </div>
                                    </div>
                                    <div class="small text-muted mt-1 js-stueck" aria-live="polite"><?= $p['gezaehlt'] === null ? '' : esc('= ' . $p['gezaehlt'] . ' Stück') ?></div>
                                <?php endif; ?>
                                <?= $meldung("ist.{$id}") ?>
                            </div>
                        </td>
                        <td data-label="Abweichung" class="text-end js-abweichung" aria-live="polite"><?= $abweichung($p['abweichung']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <div class="mb-3 mt-3">
        <label for="bemerkung" class="form-label">Bemerkung (optional)</label>
        <textarea class="form-control<?= $feld('bemerkung') ?>" id="bemerkung" name="bemerkung" rows="2" maxlength="1000" placeholder="z. B. Kiste im Keller mitgezählt"><?= esc($bemerkung) ?></textarea>
        <?= $meldung('bemerkung') ?>
    </div>

    <div class="auszaehlung-leiste">
        <div class="auszaehlung-leiste-stand" aria-live="polite">
            <div class="fw-semibold" id="auszaehlung-fortschritt"><?= esc($summe['gezaehlt'] . ' von ' . $summe['artikel'] . ' gezählt') ?></div>
            <div class="small" id="auszaehlung-summe"><?= esc($summe['abweichend'] === 0 ? 'keine Abweichung' : 'Abweichung: ' . $euro($summe['cent'])) ?></div>
        </div>
        <div class="auszaehlung-leiste-aktionen">
            <?php /* Entwurf zuerst: Enter in einem Feld löst den ersten Submit-Knopf aus, nie den Abschluss. */ ?>
            <button type="submit" class="btn btn-outline-vdst" name="aktion" value="entwurf">Entwurf speichern</button>
            <button type="submit" class="btn btn-vdst" name="aktion" value="abschliessen" id="abschliessen" aria-describedby="abschliessen-hinweis"
                    data-confirm="<?= esc($bestaetig) ?>">Abschließen</button>
            <div class="form-text w-100 text-end mt-0<?= $alleGezaehlt ? ' d-none' : '' ?>" id="abschliessen-hinweis">Zum Abschließen bitte alle Artikel zählen.</div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('js/auszaehlung.js') ?>?v=4"></script>
<?= $this->endSection() ?>
