<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Schwund und Korrektur<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$fehler  = session()->getFlashdata('fehler') ?? [];
$feld    = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? ' is-invalid' : '';
$meldung = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? '<div class="invalid-feedback">' . esc($fehler[$schluessel]) . '</div>' : '';
$art     = (string) old('art', $vorgabe);
$artikel = (string) old('artikel_id', '');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Schwund und Korrektur – <?= esc($bereich['name']) ?></h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/bestand') ?>">Zum Bestand</a>
</div>

<form action="<?= base_url('wart/' . $bereich['schluessel'] . '/bewegung') ?>" method="post" class="mb-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="art" class="form-label">Art</label>
            <select class="form-select" id="art" name="art">
                <?php foreach ($arten as $schluessel => $name): ?>
                    <option value="<?= esc($schluessel) ?>" <?= $art === $schluessel ? 'selected' : '' ?>><?= esc($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label for="artikel_id" class="form-label">Artikel</label>
            <select class="form-select<?= $feld('artikel_id') ?>" id="artikel_id" name="artikel_id">
                <option value="">– Artikel wählen –</option>
                <?php foreach ($gruppen as $gruppe): ?>
                    <optgroup label="<?= esc($gruppe['kategorie_name']) ?>">
                        <?php foreach ($gruppe['artikel'] as $a): ?>
                            <option value="<?= esc($a['artikel_id']) ?>" <?= $artikel === (string) $a['artikel_id'] ? 'selected' : '' ?>><?= esc($a['name'] . ' (' . $a['einheit'] . ')') ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?= $meldung('artikel_id') ?>
        </div>
        <div class="col-md-6">
            <label for="menge" class="form-label">Menge (Stück)</label>
            <input type="text" inputmode="numeric" class="form-control<?= $feld('menge') ?>" id="menge" name="menge" value="<?= esc((string) old('menge', '')) ?>" required>
            <?= $meldung('menge') ?>
            <div class="form-text">Schwund: verlorene Menge positiv eintragen. Korrektur: Plus oder Minus (z. B. -2).</div>
        </div>
        <div class="col-md-6">
            <label for="bemerkung" class="form-label">Bemerkung (Pflicht)</label>
            <input type="text" class="form-control<?= $feld('bemerkung') ?>" id="bemerkung" name="bemerkung" maxlength="255" value="<?= esc((string) old('bemerkung', '')) ?>" required>
            <?= $meldung('bemerkung') ?>
        </div>
    </div>
    <button type="submit" class="btn btn-vdst mt-3">Speichern</button>
</form>
<?= $this->endSection() ?>
