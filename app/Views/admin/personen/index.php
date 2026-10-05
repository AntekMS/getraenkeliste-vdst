<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Personen<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$gruppen     = ['alle' => 'Alle', 'aktiv' => 'Aktive', 'ah' => 'Alte Herren', 'sonstige' => 'Sonstige'];
$gruppenName = ['aktiv' => 'Aktiv', 'ah' => 'AH', 'sonstige' => 'Sonstige'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Personen</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-vdst" href="<?= base_url('admin/personen/import') ?>"><i class="bi bi-upload" aria-hidden="true"></i> CSV-Import</a>
        <a class="btn btn-vdst" href="<?= base_url('admin/personen/neu') ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> Person anlegen</a>
    </div>
</div>

<form method="get" action="<?= base_url('admin/personen') ?>" class="row g-2 mb-3">
    <div class="col-auto">
        <label for="gruppe" class="visually-hidden">Gruppe</label>
        <select class="form-select" id="gruppe" name="gruppe">
            <?php foreach ($gruppen as $wert => $name): ?>
                <option value="<?= esc($wert) ?>" <?= $gruppe === $wert ? 'selected' : '' ?>><?= esc($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label for="archiviert" class="visually-hidden">Status</label>
        <select class="form-select" id="archiviert" name="archiviert">
            <option value="nein" <?= ! $archiviert ? 'selected' : '' ?>>Nicht archiviert</option>
            <option value="ja" <?= $archiviert ? 'selected' : '' ?>>Archiviert</option>
        </select>
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-outline-vdst">Filtern</button></div>
</form>

<?php if ($personen === []): ?>
    <p class="text-muted">Keine Personen gefunden.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-vdst">
                <tr><th>Name</th><th>Benutzername</th><th>Gruppe</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($personen as $p): ?>
                    <tr>
                        <td><?= esc($p['anzeigename']) ?></td>
                        <td><?= esc($p['benutzername']) ?></td>
                        <td><?= esc($gruppenName[$p['gruppe']] ?? $p['gruppe']) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-vdst" href="<?= base_url('admin/personen/' . $p['id']) ?>">Bearbeiten</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
