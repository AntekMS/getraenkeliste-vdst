<?php
$erinnerungen = service('zeitraeume')->erinnerungen(service('anmeldung')->rollen());
?>
<?php foreach ($erinnerungen as $e): ?>
    <div class="alert alert-warning mx-3 mt-3 mb-0 d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
        <span>
            <i class="bi bi-clipboard-check" aria-hidden="true"></i>
            <strong><?= esc($e['name']) ?>:</strong>
            <?= $e['hat_auszaehlung'] ? 'Die letzte Auszählung ist ' . (int) $e['tage'] . ' Tage her.' : 'Es gab noch keine Auszählung.' ?>
        </span>
        <a class="btn btn-sm btn-outline-vdst" href="<?= esc(base_url('wart/' . $e['schluessel'] . '/auszaehlung'), 'attr') ?>">Zur Auszählung</a>
    </div>
<?php endforeach; ?>
