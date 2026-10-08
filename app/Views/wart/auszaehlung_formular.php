<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Auszählung<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$feld    = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? ' is-invalid' : '';
$meldung = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? '<div class="invalid-feedback d-block">' . esc($fehler[$schluessel]) . '</div>' : '';
$basis   = base_url('wart/' . $bereich['schluessel'] . '/auszaehlung');
$altIst  = old('ist');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Auszählung – <?= esc($bereich['name']) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/auszaehlungen') ?>">Abgeschlossene Auszählungen</a>
        <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/bestand') ?>">Zum Bestand</a>
    </div>
</div>

<?php if ($hatEntwurf): ?>
    <div class="alert alert-info" role="status">Ein Entwurf ist gespeichert. Er kann jederzeit weiter bearbeitet werden.</div>
<?php endif; ?>

<form action="<?= esc($basis) ?>" method="get" class="row g-2 align-items-end mb-4">
    <div class="col-md-5">
        <label for="stichtag" class="form-label">Stichtag</label>
        <input type="datetime-local" class="form-control<?= $feld('stichtag') ?>" id="stichtag" name="stichtag" value="<?= esc($stichtag) ?>" data-geladen="<?= esc($stichtag) ?>" required>
        <div class="form-text text-warning-emphasis d-none" id="stichtag-hinweis">Bitte „Stichtag übernehmen“ klicken, um das Soll neu zu laden.</div>
        <?= $meldung('stichtag') ?>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-vdst">Stichtag übernehmen</button>
    </div>
    <div class="col-12 form-text">Das Soll wird für den Stichtag neu berechnet. Bereits eingetippte, noch nicht gespeicherte Ist-Werte gehen dabei verloren.</div>
</form>

<form action="<?= esc($basis) ?>" method="post" id="auszaehlung-form">
    <?= csrf_field() ?>
    <input type="hidden" name="stichtag" id="stichtag-senden" value="<?= esc($stichtag) ?>">

    <?php if ($gruppen === []): ?>
        <p class="text-muted">Keine Artikel mit Bestandsführung.</p>
    <?php endif; ?>

    <?php foreach ($gruppen as $gruppe): ?>
        <h2 class="h5 mt-4"><?= esc($gruppe['kategorie_name']) ?></h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th class="text-end">Soll</th>
                        <th class="text-end">Ist</th>
                        <th class="text-end">Differenz</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gruppe['positionen'] as $p): ?>
                        <?php
                        $id    = (int) $p['artikel_id'];
                        $wert  = is_array($altIst) ? (string) ($altIst[$id] ?? '') : (string) ($ist[$id] ?? '');
                        $diff  = ctype_digit($wert) ? (int) $wert - (int) $p['soll'] : null;
                        ?>
                        <tr data-soll="<?= esc((string) $p['soll']) ?>">
                            <td>
                                <?= esc($p['name']) ?> <span class="text-muted small"><?= esc($p['einheit']) ?></span>
                                <?php if ($p['start']): ?><span class="badge text-bg-secondary ms-1">neu</span><?php endif; ?>
                            </td>
                            <td class="text-end"><?= esc((string) $p['soll']) ?></td>
                            <td class="text-end">
                                <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control form-control-sm text-end d-inline-block auszaehlung-ist<?= $feld("ist.{$id}") ?>"
 name="ist[<?= $id ?>]" value="<?= esc($wert) ?>" aria-label="Ist <?= esc($p['name']) ?>">
                                <?= $meldung("ist.{$id}") ?>
                            </td>
                            <td class="text-end js-differenz"><?= $diff === null ? '' : esc(($diff > 0 ? '+' : '') . $diff) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <div class="mb-3 mt-3">
        <label for="bemerkung" class="form-label">Bemerkung (optional)</label>
        <textarea class="form-control<?= $feld('bemerkung') ?>" id="bemerkung" name="bemerkung" rows="2" maxlength="1000"><?= esc($bemerkung) ?></textarea>
        <?= $meldung('bemerkung') ?>
    </div>

    <?php /* Entwurf zuerst: Enter in einem Feld löst den ersten Submit-Knopf aus, nie den Abschluss. */ ?>
    <div class="d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-outline-vdst" name="aktion" value="entwurf">Entwurf speichern</button>
        <button type="submit" class="btn btn-vdst" name="aktion" value="abschliessen"
                data-confirm="Auszählung wirklich abschließen? Danach sind alle Buchungen bis zum Stichtag eingefroren.">Abschließen</button>
    </div>
    <div class="form-text">Zum Abschließen muss für jeden Artikel ein Ist-Wert eingetragen sein.</div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('js/auszaehlung.js') ?>?v=2"></script>
<?= $this->endSection() ?>
