<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Import-Vorschau<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h1 class="h3">Import-Vorschau</h1>
<p><?= esc((string) $gueltig) ?> von <?= esc((string) count($zeilen)) ?> Zeilen sind fehlerfrei und werden angelegt.
    Zeilen mit Fehler werden nicht importiert.</p>

<div class="table-responsive">
    <table class="table align-middle">
        <thead class="table-vdst">
            <tr><th>Zeile</th><th>Vorname</th><th>Nachname</th><th>Gruppe</th><th>Benutzername</th><th>Prüfung</th></tr>
        </thead>
        <tbody>
            <?php foreach ($zeilen as $z): ?>
                <tr>
                    <td><?= esc((string) $z['zeile']) ?></td>
                    <td><?= esc($z['vorname']) ?></td>
                    <td><?= esc($z['nachname']) ?></td>
                    <td><?= esc($z['gruppe']) ?></td>
                    <td><?= esc($z['benutzername']) ?></td>
                    <td>
                        <?php if ($z['fehler'] === null): ?>
                            <span class="text-success">OK</span>
                        <?php else: ?>
                            <span class="text-danger"><?= esc($z['fehler']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="d-flex flex-wrap gap-2">
    <?php if ($gueltig > 0): ?>
        <form action="<?= base_url('admin/personen/import/ausfuehren') ?>" method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-vdst"><?= esc((string) $gueltig) ?> Personen anlegen</button>
        </form>
    <?php endif; ?>
    <a class="btn btn-outline-vdst" href="<?= base_url('admin/personen/import') ?>">Andere Datei wählen</a>
</div>
<?= $this->endSection() ?>
