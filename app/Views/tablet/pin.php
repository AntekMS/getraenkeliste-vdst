<?= $this->extend('layouts/einfach') ?>

<?= $this->section('title') ?>PIN eingeben<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form id="tablet-pin" action="<?= esc(site_url('tablet/pin/' . (int) $person['id']), 'attr') ?>" method="post" class="pin-formular"
      data-zurueck-url="<?= esc(site_url('tablet'), 'attr') ?>" data-idle-s="<?= (int) $timeoutS ?>">
    <?= csrf_field() ?>

    <h2 class="h4 text-center mb-1"><?= esc($person['anzeigename']) ?></h2>
    <p class="text-center text-muted mb-3">Bitte PIN eingeben (4 bis 6 Ziffern)</p>

    <label for="pin-anzeige" class="visually-hidden">PIN</label>
    <input type="password" id="pin-anzeige" name="pin" class="form-control form-control-lg pin-anzeige js-pin"
           inputmode="none" readonly maxlength="6" autocomplete="off">

    <div class="pin-tastatur my-3">
        <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $ziffer): ?>
            <button type="button" class="btn btn-outline-secondary pin-taste js-ziffer" data-ziffer="<?= $ziffer ?>"><?= $ziffer ?></button>
        <?php endforeach; ?>
        <button type="button" class="btn btn-outline-secondary pin-taste js-loeschen" title="Letzte Ziffer löschen" aria-label="Letzte Ziffer löschen">
            <i class="bi bi-backspace" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary pin-taste js-ziffer" data-ziffer="0">0</button>
        <button type="submit" class="btn btn-vdst pin-taste js-weiter" title="PIN bestätigen" aria-label="PIN bestätigen" disabled>
            <i class="bi bi-check-lg" aria-hidden="true"></i>
        </button>
    </div>

    <a href="<?= esc(site_url('tablet'), 'attr') ?>" class="btn btn-outline-vdst w-100">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Zurück
    </a>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('js/tablet.js') ?>?v=1"></script>
<?= $this->endSection() ?>
