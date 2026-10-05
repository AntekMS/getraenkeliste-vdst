<?= $this->extend('layouts/einfach') ?>

<?= $this->section('title') ?>Anmeldung<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form action="<?= base_url('login') ?>" method="post">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="benutzername" class="form-label"><strong>Benutzername</strong></label>
        <input type="text" class="form-control" id="benutzername" name="benutzername"
               autocomplete="username" autocapitalize="none" required autofocus>
    </div>

    <div class="mb-3">
        <label for="passwort" class="form-label"><strong>Passwort</strong></label>
        <input type="password" class="form-control" id="passwort" name="passwort"
               autocomplete="current-password" required>
    </div>

    <div class="form-check mb-4">
        <input type="checkbox" class="form-check-input" id="merken" name="merken" value="1">
        <label for="merken" class="form-check-label">Angemeldet bleiben</label>
    </div>

    <button type="submit" class="btn btn-vdst w-100">
        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Anmelden
    </button>
</form>
<?= $this->endSection() ?>
