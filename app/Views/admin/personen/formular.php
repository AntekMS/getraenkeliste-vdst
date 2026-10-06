<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= $person === null ? 'Person anlegen' : 'Person bearbeiten' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$neu         = $person === null;
$wert        = static fn (string $feld): string => (string) old($feld, $person[$feld] ?? '');
$gruppeWert  = $wert('gruppe') !== '' ? $wert('gruppe') : 'aktiv';
$rollenNamen = ['getraenkewart' => 'Getränkewart', 'kioskwart' => 'Kioskwart', 'kassenwart' => 'Kassenwart', 'admin' => 'Admin'];
$gewaehlt    = old('rollen') !== null ? (array) old('rollen') : $rollen;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= $neu ? 'Person anlegen' : esc($person['anzeigename']) ?></h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('admin/personen') ?>">Zur Liste</a>
</div>

<?php if (! $neu && $person['archiviert_at'] !== null): ?>
    <div class="alert alert-warning">Diese Person ist archiviert (seit <?= esc($person['archiviert_at']) ?>).</div>
<?php endif; ?>

<form action="<?= base_url($neu ? 'admin/personen' : 'admin/personen/' . $person['id']) ?>" method="post" class="mb-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="vorname" class="form-label">Vorname</label>
            <input type="text" class="form-control" id="vorname" name="vorname" value="<?= esc($wert('vorname')) ?>" required>
        </div>
        <div class="col-md-6">
            <label for="nachname" class="form-label">Nachname</label>
            <input type="text" class="form-control" id="nachname" name="nachname" value="<?= esc($wert('nachname')) ?>" required>
        </div>
        <div class="col-md-6">
            <label for="anzeigename" class="form-label">Anzeigename</label>
            <input type="text" class="form-control" id="anzeigename" name="anzeigename" value="<?= esc($wert('anzeigename')) ?>">
            <div class="form-text">Leer lassen für „Vorname Nachname“.</div>
        </div>
        <div class="col-md-6">
            <label for="benutzername" class="form-label">Benutzername</label>
            <input type="text" class="form-control" id="benutzername" name="benutzername" value="<?= esc($wert('benutzername')) ?>"
                   autocomplete="off" autocapitalize="none" required>
            <div class="form-text">3 bis 40 Zeichen: a-z, 0-9, Punkt, Unterstrich, Minus.</div>
        </div>
        <div class="col-md-6">
            <label for="gruppe" class="form-label">Gruppe</label>
            <select class="form-select" id="gruppe" name="gruppe">
                <?php foreach (['aktiv' => 'Aktiv', 'ah' => 'Alter Herr', 'sonstige' => 'Sonstige'] as $w => $n): ?>
                    <option value="<?= esc($w) ?>" <?= $gruppeWert === $w ? 'selected' : '' ?>><?= esc($n) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <span class="form-label d-block">Zusätzliche Rollen</span>
            <?php foreach ($rollenNamen as $schluessel => $name): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="rolle_<?= esc($schluessel) ?>" name="rollen[]"
                           value="<?= esc($schluessel) ?>" <?= in_array($schluessel, $gewaehlt, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="rolle_<?= esc($schluessel) ?>"><?= esc($name) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($neu): ?>
        <p class="text-muted mt-3 mb-0">Beim Anlegen wird ein Einmal-Passwort erzeugt und einmal angezeigt.</p>
    <?php endif; ?>
    <button type="submit" class="btn btn-vdst mt-3"><?= $neu ? 'Anlegen' : 'Speichern' ?></button>
</form>

<?php if (! $neu && $person['archiviert_at'] === null): ?>
    <h2 class="h5">Aktionen</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($ich): ?>
            <a class="btn btn-outline-vdst" href="<?= base_url('konto') ?>">Passwort unter Konto ändern</a>
        <?php else: ?>
            <form action="<?= base_url('admin/personen/' . $person['id'] . '/passwort-reset') ?>" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-vdst">Passwort zurücksetzen</button>
            </form>
        <?php endif; ?>
        <form action="<?= base_url('admin/personen/' . $person['id'] . '/pin-reset') ?>" method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-vdst">PIN zurücksetzen</button>
        </form>
        <?php if (! $ich): ?>
            <form action="<?= base_url('admin/personen/' . $person['id'] . '/archivieren') ?>" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger">Archivieren</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
