<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>CSV-Import<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Personen per CSV importieren</h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('admin/personen') ?>">Zur Liste</a>
</div>

<p>Die Datei braucht die Kopfzeile <code>vorname;nachname;gruppe;benutzername</code> (Trennzeichen Semikolon,
    wie Excel es speichert). Gruppe: <code>aktiv</code>, <code>ah</code> oder <code>sonstige</code>. Erlaubt sind
    <code>.csv</code> und <code>.txt</code> bis 1 MB. Vor dem Anlegen siehst du eine Vorschau.</p>

<form action="<?= base_url('admin/personen/import/vorschau') ?>" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="datei" class="form-label">CSV-Datei</label>
        <input type="file" class="form-control" id="datei" name="datei" accept=".csv,.txt" required>
    </div>
    <button type="submit" class="btn btn-vdst">Vorschau anzeigen</button>
</form>
<?= $this->endSection() ?>
