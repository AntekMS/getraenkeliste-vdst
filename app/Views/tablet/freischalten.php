<?= $this->extend('layouts/einfach') ?>

<?= $this->section('title') ?>Tablet freischalten<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form action="<?= base_url('tablet/freischalten') ?>" method="post">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="code" class="form-label"><strong>Freischaltcode</strong></label>
        <input type="text" class="form-control" id="code" name="code" inputmode="numeric"
               autocomplete="off" maxlength="20" required autofocus>
    </div>

    <div class="mb-4">
        <label for="name" class="form-label"><strong>Name des Tablets</strong></label>
        <input type="text" class="form-control" id="name" name="name" maxlength="100"
               value="<?= esc(old('name')) ?>" placeholder="z. B. Kühlschrank" required>
    </div>

    <button type="submit" class="btn btn-vdst w-100">
        <i class="bi bi-tablet" aria-hidden="true"></i> Freischalten
    </button>
</form>
<?= $this->endSection() ?>
