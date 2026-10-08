<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= $artikel === null ? 'Artikel anlegen' : 'Artikel bearbeiten' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$neu  = $artikel === null;
$wert = static fn (string $feld, string $standard = ''): string => (string) old($feld, $artikel[$feld] ?? $standard);
$preis = old('preis') ?? ($neu ? '' : number_format((int) $artikel['preis_cent'] / 100, 2, ',', ''));
// Beim Neuanlegen ist „Bestand führen“ vorbelegt; nach einem Fehler gilt die Eingabe.
$bestandFuehren = old('name') !== null ? old('bestand_fuehren') === '1' : ($neu || (int) $artikel['bestand_fuehren'] === 1);
$kategorieWert  = (int) old('kategorie_id', $kategorie['id']);
$bildFehler     = (session()->getFlashdata('fehler') ?? [])['bild'] ?? null;
$bildUrl        = $neu ? null : \App\Libraries\Artikelbild::url($artikel);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= $neu ? 'Artikel anlegen' : esc($artikel['name']) ?></h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('admin/stammdaten') ?>">Zur Liste</a>
</div>

<?php if (! $neu && $artikel['archiviert_at'] !== null): ?>
    <div class="alert alert-warning">Dieser Artikel ist archiviert (seit <?= esc($artikel['archiviert_at']) ?>) und kann nicht bearbeitet werden.</div>
<?php endif; ?>

<form action="<?= base_url($neu ? 'admin/artikel' : 'admin/artikel/' . $artikel['id']) ?>" method="post" enctype="multipart/form-data" class="mb-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="name" class="form-label">Name</label>
            <input type="text" class="form-control" id="name" name="name" value="<?= esc($wert('name')) ?>" maxlength="100" required>
        </div>
        <div class="col-md-6">
            <label for="kategorie_id" class="form-label">Kategorie</label>
            <?php if ($neu): ?>
                <input type="hidden" name="kategorie_id" value="<?= esc($kategorie['id']) ?>">
                <input type="text" class="form-control" id="kategorie_id" value="<?= esc($kategorie['name']) ?>" disabled>
            <?php else: ?>
                <select class="form-select" id="kategorie_id" name="kategorie_id">
                    <?php foreach ($kategorien as $k): ?>
                        <option value="<?= esc($k['id']) ?>" <?= $kategorieWert === (int) $k['id'] ? 'selected' : '' ?>><?= esc($k['bereich_name'] . ' – ' . $k['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (! isset($kategorien[(int) $kategorie['id']])): ?>
                        <option value="<?= esc($kategorie['id']) ?>" selected><?= esc($kategorie['name']) ?> (archiviert)</option>
                    <?php endif; ?>
                </select>
                <div class="form-text">Beim Wechsel landet der Artikel am Ende der neuen Kategorie.</div>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <label for="preis" class="form-label">Preis (€)</label>
            <input type="text" class="form-control" id="preis" name="preis" value="<?= esc($preis) ?>" inputmode="decimal" placeholder="1,50" required>
            <div class="form-text">Gilt nur für künftige Buchungen; bestehende Buchungen behalten ihren Preis.</div>
        </div>
        <div class="col-md-6">
            <label for="einheit" class="form-label">Einheit</label>
            <input type="text" class="form-control" id="einheit" name="einheit" value="<?= esc($wert('einheit')) ?>" maxlength="50" placeholder="0,5 l Flasche" required>
        </div>
        <div class="col-md-6">
            <label for="gebinde_groesse" class="form-label">Gebindegröße</label>
            <input type="number" class="form-control" id="gebinde_groesse" name="gebinde_groesse" value="<?= esc($wert('gebinde_groesse')) ?>" min="1" max="100">
            <div class="form-text">Stück pro Kasten o. ä.; leer, wenn nicht relevant.</div>
        </div>
        <div class="col-md-6">
            <label for="mindestbestand" class="form-label">Mindestbestand</label>
            <input type="number" class="form-control" id="mindestbestand" name="mindestbestand" value="<?= esc($wert('mindestbestand', '0')) ?>" min="0">
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="bestand_fuehren" name="bestand_fuehren" value="1" <?= $bestandFuehren ? 'checked' : '' ?>>
                <label class="form-check-label" for="bestand_fuehren">Bestand führen</label>
            </div>
        </div>
        <div class="col-md-6">
            <label for="bild" class="form-label">Bild</label>
            <?php if ($bildUrl !== null): ?>
                <div class="mb-2"><img class="artikel-bild-vorschau" src="<?= esc(base_url($bildUrl), 'attr') ?>" alt="Aktuelles Bild von <?= esc($artikel['name'], 'attr') ?>"></div>
            <?php endif; ?>
            <input type="file" class="form-control<?= $bildFehler !== null ? ' is-invalid' : '' ?>" id="bild" name="bild" accept="image/jpeg,image/png,image/webp">
            <?php if ($bildFehler !== null): ?>
                <div class="invalid-feedback"><?= esc($bildFehler) ?></div>
            <?php endif; ?>
            <div class="form-text">JPG, PNG oder WebP, höchstens 5 MB; wird auf 600 px verkleinert. Ein neues Bild ersetzt das bisherige.</div>
            <?php if ($bildUrl !== null): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="bild_entfernen" name="bild_entfernen" value="1">
                    <label class="form-check-label" for="bild_entfernen">Bild entfernen</label>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($neu || $artikel['archiviert_at'] === null): ?>
        <button type="submit" class="btn btn-vdst mt-3"><?= $neu ? 'Anlegen' : 'Speichern' ?></button>
    <?php endif; ?>
</form>
<?= $this->endSection() ?>
