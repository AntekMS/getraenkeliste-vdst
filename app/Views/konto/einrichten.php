<?= $this->extend('layouts/einfach') ?>

<?= $this->section('title') ?>Konto einrichten<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h2 class="h5">Bitte richte dein Konto ein</h2>

<form action="<?= base_url('konto/einrichten') ?>" method="post">
    <?= csrf_field() ?>

    <?php if ($offen['passwort']): ?>
        <div class="mb-3">
            <label for="passwort_neu" class="form-label"><strong>Neues Passwort</strong></label>
            <input type="password" class="form-control" id="passwort_neu" name="passwort_neu"
                   autocomplete="new-password" minlength="8" required autofocus>
            <div class="form-text">Mindestens 8 Zeichen.</div>
        </div>
        <div class="mb-3">
            <label for="passwort_wiederholen" class="form-label"><strong>Passwort wiederholen</strong></label>
            <input type="password" class="form-control" id="passwort_wiederholen" name="passwort_wiederholen"
                   autocomplete="new-password" required>
        </div>
    <?php endif; ?>

    <?php if ($offen['pin']): ?>
        <div class="mb-3">
            <label for="pin" class="form-label"><strong>Neue PIN</strong></label>
            <input type="password" class="form-control" id="pin" name="pin" inputmode="numeric"
                   pattern="[0-9]{4,6}" maxlength="6" autocomplete="off" required>
            <div class="form-text">4 bis 6 Ziffern.</div>
        </div>
        <div class="mb-4">
            <label for="pin_wiederholen" class="form-label"><strong>PIN wiederholen</strong></label>
            <input type="password" class="form-control" id="pin_wiederholen" name="pin_wiederholen"
                   inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off" required>
        </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-vdst w-100">
        <i class="bi bi-check-lg" aria-hidden="true"></i> Speichern
    </button>
</form>

<form action="<?= base_url('logout') ?>" method="post" class="mt-3">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-logout w-100">
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Abmelden
    </button>
</form>
<?= $this->endSection() ?>
