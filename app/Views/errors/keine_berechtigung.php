<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Keine Berechtigung<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h1 class="h3">Keine Berechtigung</h1>
<p>Dafür fehlt dir die Berechtigung.</p>
<a class="btn btn-outline-vdst" href="<?= base_url('buchen') ?>">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Zurück zum Buchen
</a>
<?= $this->endSection() ?>
