<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Lieferung erfassen<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$fehler = session()->getFlashdata('fehler') ?? [];
$alt    = old('zeilen');
$alt    = is_array($alt) && $alt !== [] ? $alt : array_fill(0, 3, []);
$feld   = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? ' is-invalid' : '';
$meldung = static fn (string $schluessel): string => isset($fehler[$schluessel]) ? '<div class="invalid-feedback">' . esc($fehler[$schluessel]) . '</div>' : '';

// Eine Zeile; $i ist der Zeilenindex (in der Vorlage der Platzhalter __I__).
$zeile = static function (string|int $i, array $werte) use ($gruppen, $feld, $meldung): void {
    $gewaehlt = (string) ($werte['artikel_id'] ?? '');
    ?>
    <div class="row g-2 align-items-start mb-3 js-lieferung-zeile">
        <div class="col-12 col-md-5">
            <label class="form-label" for="artikel_<?= esc($i) ?>">Artikel</label>
            <select class="form-select<?= $feld("zeilen.{$i}.artikel_id") ?>" id="artikel_<?= esc($i) ?>" name="zeilen[<?= esc($i) ?>][artikel_id]">
                <option value="">– Artikel wählen –</option>
                <?php foreach ($gruppen as $gruppe): ?>
                    <optgroup label="<?= esc($gruppe['kategorie_name']) ?>">
                        <?php foreach ($gruppe['artikel'] as $a): ?>
                            <option value="<?= esc($a['artikel_id']) ?>" <?= $gewaehlt === (string) $a['artikel_id'] ? 'selected' : '' ?>><?= esc($a['name'] . ' (' . $a['einheit'] . ')') ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?= $meldung("zeilen.{$i}.artikel_id") ?>
        </div>
        <div class="col-4 col-md-2">
            <label class="form-label" for="kisten_<?= esc($i) ?>">Kisten</label>
            <input type="text" inputmode="numeric" class="form-control<?= $feld("zeilen.{$i}.kisten") ?>" id="kisten_<?= esc($i) ?>" name="zeilen[<?= esc($i) ?>][kisten]" value="<?= esc((string) ($werte['kisten'] ?? '')) ?>">
            <?= $meldung("zeilen.{$i}.kisten") ?>
        </div>
        <div class="col-4 col-md-2">
            <label class="form-label" for="stueck_<?= esc($i) ?>">Stück</label>
            <input type="text" inputmode="numeric" class="form-control<?= $feld("zeilen.{$i}.stueck") ?>" id="stueck_<?= esc($i) ?>" name="zeilen[<?= esc($i) ?>][stueck]" value="<?= esc((string) ($werte['stueck'] ?? '')) ?>">
            <?= $meldung("zeilen.{$i}.stueck") ?>
        </div>
        <div class="col-4 col-md-3">
            <label class="form-label" for="ek_<?= esc($i) ?>">Einkaufspreis je Stück (€)</label>
            <input type="text" inputmode="decimal" class="form-control<?= $feld("zeilen.{$i}.einkaufspreis") ?>" id="ek_<?= esc($i) ?>" name="zeilen[<?= esc($i) ?>][einkaufspreis]" value="<?= esc((string) ($werte['einkaufspreis'] ?? '')) ?>" placeholder="0,85">
            <?= $meldung("zeilen.{$i}.einkaufspreis") ?>
        </div>
    </div>
    <?php
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Lieferung erfassen – <?= esc($bereich['name']) ?></h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/bestand') ?>">Zum Bestand</a>
</div>

<p class="text-muted">Menge = Kisten × Gebindegröße + Stück. Zeilen ohne Menge werden übersprungen; es wird alles oder nichts gespeichert.</p>

<form action="<?= base_url('wart/' . $bereich['schluessel'] . '/lieferung') ?>" method="post" class="mb-4">
    <?= csrf_field() ?>
    <div id="lieferung-zeilen">
        <?php foreach ($alt as $i => $werte): ?>
            <?php $zeile($i, is_array($werte) ? $werte : []); ?>
        <?php endforeach; ?>
    </div>

    <template id="lieferung-vorlage">
        <?php $zeile('__I__', []); ?>
    </template>

    <button type="button" class="btn btn-outline-vdst mb-3" id="lieferung-zeile-hinzufuegen" data-naechster-index="<?= esc((string) (max(array_map('intval', array_keys($alt))) + 1)) ?>">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Zeile hinzufügen
    </button>

    <div class="mb-3">
        <label for="bemerkung" class="form-label">Bemerkung (optional)</label>
        <input type="text" class="form-control<?= $feld('bemerkung') ?>" id="bemerkung" name="bemerkung" maxlength="255" value="<?= esc((string) old('bemerkung', '')) ?>">
        <?= $meldung('bemerkung') ?>
    </div>

    <button type="submit" class="btn btn-vdst">Lieferung speichern</button>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('js/lieferung.js') ?>?v=1"></script>
<?= $this->endSection() ?>
