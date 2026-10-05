<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Konto<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h1 class="h3">Konto</h1>
<p class="text-muted"><?= esc($person['anzeigename']) ?> (<?= esc($person['benutzername']) ?>)</p>

<div class="row g-4">
    <div class="col-md-6">
        <h2 class="h5">Passwort ändern</h2>
        <form action="<?= base_url('konto/passwort') ?>" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="pw_aktuell" class="form-label">Aktuelles Passwort</label>
                <input type="password" class="form-control" id="pw_aktuell" name="passwort_aktuell"
                       autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label for="passwort_neu" class="form-label">Neues Passwort</label>
                <input type="password" class="form-control" id="passwort_neu" name="passwort_neu"
                       autocomplete="new-password" minlength="8" required>
            </div>
            <div class="mb-3">
                <label for="passwort_wiederholen" class="form-label">Neues Passwort wiederholen</label>
                <input type="password" class="form-control" id="passwort_wiederholen" name="passwort_wiederholen"
                       autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-vdst">Passwort ändern</button>
        </form>
    </div>

    <div class="col-md-6">
        <h2 class="h5">PIN ändern</h2>
        <form action="<?= base_url('konto/pin') ?>" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="pin_aktuell" class="form-label">Aktuelles Passwort</label>
                <input type="password" class="form-control" id="pin_aktuell" name="passwort_aktuell"
                       autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label for="pin" class="form-label">Neue PIN (4 bis 6 Ziffern)</label>
                <input type="password" class="form-control" id="pin" name="pin" inputmode="numeric"
                       pattern="[0-9]{4,6}" maxlength="6" autocomplete="off" required>
            </div>
            <div class="mb-3">
                <label for="pin_wiederholen" class="form-label">Neue PIN wiederholen</label>
                <input type="password" class="form-control" id="pin_wiederholen" name="pin_wiederholen"
                       inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off" required>
            </div>
            <button type="submit" class="btn btn-outline-vdst">PIN ändern</button>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
