<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Einmal-Passwörter<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Einmal-Passwörter</h1>
    <div class="d-flex gap-2 no-print">
        <button type="button" class="btn btn-vdst" data-print><i class="bi bi-printer" aria-hidden="true"></i> Drucken</button>
        <a class="btn btn-outline-vdst" href="<?= base_url('admin/personen') ?>">Zur Liste</a>
    </div>
</div>

<div class="alert alert-warning no-print">
    Diese Passwörter werden <strong>nur jetzt einmal</strong> angezeigt. Beim ersten Login legt jede Person ein eigenes
    Passwort und eine PIN fest.
</div>

<div class="table-responsive">
    <table class="table align-middle">
        <thead class="table-vdst">
            <tr><th>Name</th><th>Benutzername</th><th>Einmal-Passwort</th></tr>
        </thead>
        <tbody>
            <?php foreach ($liste as $eintrag): ?>
                <tr>
                    <td><?= esc($eintrag['name']) ?></td>
                    <td><?= esc($eintrag['benutzername']) ?></td>
                    <td><code class="einmalpasswort"><?= esc($eintrag['passwort']) ?></code></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
