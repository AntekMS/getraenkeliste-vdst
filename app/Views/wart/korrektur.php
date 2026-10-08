<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Korrekturbuchung<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$fehler  = session()->getFlashdata('fehler') ?? [];
$feld    = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? ' is-invalid' : '';
$meldung = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? '<div class="invalid-feedback">' . esc($fehler[$schluessel]) . '</div>' : '';
$konto   = (string) old('konto_id', '');
$gewaehlt = (string) old('artikel_id', '');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Korrekturbuchung – <?= esc($bereich['name']) ?></h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/buchungen') ?>">Zu den Buchungen</a>
</div>

<form action="<?= base_url('wart/' . $bereich['schluessel'] . '/korrektur') ?>" method="post" class="mb-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="konto_id" class="form-label">Konto</label>
            <select class="form-select" id="konto_id" name="konto_id" required>
                <option value="">– Konto wählen –</option>
                <?php foreach ($konten as $k): ?>
                    <option value="<?= esc($k['id']) ?>" <?= $konto === (string) $k['id'] ? 'selected' : '' ?>><?= esc($k['anzeigename']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label for="artikel_id" class="form-label">Artikel</label>
            <select class="form-select" id="artikel_id" name="artikel_id" required>
                <option value="">– Artikel wählen –</option>
                <?php foreach ($artikel as $gruppe): ?>
                    <optgroup label="<?= esc($gruppe['kategorie']) ?>">
                        <?php foreach ($gruppe['artikel'] as $a): ?>
                            <option value="<?= esc($a['id']) ?>" <?= $gewaehlt === (string) $a['id'] ? 'selected' : '' ?>><?= esc($a['name'] . ' (' . $a['einheit'] . ')') ?><?= $a['archiviert_at'] !== null ? ' – archiviert' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label for="menge" class="form-label">Menge</label>
            <input type="text" inputmode="numeric" class="form-control<?= $feld('menge') ?>" id="menge" name="menge" value="<?= esc((string) old('menge', '')) ?>" required>
            <?= $meldung('menge') ?>
            <div class="form-text">Ganze Zahl von -99 bis 99, nicht 0. Minus nimmt Buchungen zurück (z. B. -2), gebucht wird zum aktuellen Preis.</div>
        </div>
        <div class="col-md-6">
            <label for="bemerkung" class="form-label">Bemerkung (Pflicht)</label>
            <input type="text" class="form-control" id="bemerkung" name="bemerkung" maxlength="255" value="<?= esc((string) old('bemerkung', '')) ?>" required>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="bestandswirksam" name="bestandswirksam" value="1" <?= old('bestandswirksam') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="bestandswirksam">Ware wurde tatsächlich entnommen bzw. zurückgegeben (zählt für den Bestand)</label>
            </div>
            <div class="form-text">Ohne Haken ändert die Korrektur nur den Betrag des Kontos.</div>
        </div>
    </div>
    <button type="submit" class="btn btn-vdst mt-3">Buchen</button>
</form>
<?= $this->endSection() ?>
