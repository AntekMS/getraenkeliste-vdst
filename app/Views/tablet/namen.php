<?= $this->extend('layouts/einfach') ?>

<?= $this->section('title') ?>Wer bist du?<?= $this->endSection() ?>

<?= $this->section('seitenklasse') ?>login-breit<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div id="tablet-namen" data-reload-s="600">
    <h2 class="h4 mb-3">Wer bist du?</h2>

    <div class="namen-raster mb-4">
        <?php foreach ($kacheln['fest'] as $person): ?>
            <?= $this->setVar('person', $person)->include('tablet/_kachel') ?>
        <?php endforeach; ?>
    </div>

    <div class="namen-werkzeuge mb-3">
        <label for="namen-suche" class="visually-hidden">Name suchen</label>
        <input type="search" id="namen-suche" class="form-control form-control-lg js-suche" placeholder="Name suchen" autocomplete="off">
        <div class="btn-group" role="group" aria-label="Anzeige">
            <?php foreach (['zuletzt' => 'Zuletzt', 'aktiv' => 'Aktive', 'ah' => 'AHs', 'alle' => 'Alle'] as $wert => $text): ?>
                <input type="radio" class="btn-check js-filter" name="filter" id="filter-<?= $wert ?>" value="<?= $wert ?>"<?= $wert === 'zuletzt' ? ' checked' : '' ?>>
                <label class="btn btn-outline-vdst btn-lg" for="filter-<?= $wert ?>"><?= $text ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="namen-raster js-zuletzt">
        <?php foreach ($kacheln['zuletzt'] as $person): ?>
            <?= $this->setVar('person', $person)->include('tablet/_kachel') ?>
        <?php endforeach; ?>
    </div>

    <div class="namen-raster js-alle" hidden>
        <?php foreach ($kacheln['alle'] as $person): ?>
            <?= $this->setVar('person', $person)->include('tablet/_kachel') ?>
        <?php endforeach; ?>
    </div>

    <p class="text-muted mt-3 js-keine-treffer" hidden>Kein Name gefunden.</p>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('js/tablet.js') ?>?v=1"></script>
<?= $this->endSection() ?>
