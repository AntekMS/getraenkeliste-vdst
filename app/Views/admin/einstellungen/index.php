<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Einstellungen<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-3">Einstellungen</h1>

<form action="<?= base_url('admin/einstellungen') ?>" method="post" class="mb-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php foreach ($felder as $f): ?>
            <div class="col-md-6">
                <label for="<?= esc($f['schluessel']) ?>" class="form-label"><?= esc($f['label']) ?></label>
                <input type="<?= $f['typ'] === 'int' ? 'number' : 'text' ?>" class="form-control <?= $f['fehler'] !== null ? 'is-invalid' : '' ?>"
                       id="<?= esc($f['schluessel']) ?>" name="<?= esc($f['schluessel']) ?>" value="<?= esc($f['wert']) ?>"
                       aria-describedby="<?= esc($f['schluessel']) ?>_hinweis" required>
                <?php if ($f['fehler'] !== null): ?>
                    <div class="invalid-feedback"><?= esc($f['fehler']) ?></div>
                <?php endif; ?>
                <div class="form-text" id="<?= esc($f['schluessel']) ?>_hinweis"><?= esc($f['hinweis']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="col-md-6">
            <label for="inbetriebnahme_at" class="form-label">Inbetriebnahme</label>
            <input type="text" class="form-control" id="inbetriebnahme_at" value="<?= esc($inbetriebnahme->format('d.m.Y H:i')) ?>" disabled>
            <div class="form-text">Start des Abrechnungszeitraums, nicht änderbar.</div>
        </div>
    </div>
    <button type="submit" class="btn btn-vdst mt-3">Speichern</button>
</form>
<?= $this->endSection() ?>
