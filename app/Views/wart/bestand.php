<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Bestand<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$ampelKlasse = ['ok' => 'badge-status-gruen', 'niedrig' => 'badge-status-amber', 'leer' => 'badge-status-rot', 'negativ' => 'badge-status-rot'];
$ampelText = ['ok' => 'OK', 'niedrig' => 'Niedrig', 'leer' => 'Leer', 'negativ' => 'Negativ'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Bestand <?= esc($bereich['name']) ?></h1>
    <a class="btn btn-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/lieferung') ?>">
        <i class="bi bi-box-seam" aria-hidden="true"></i> Lieferung erfassen
    </a>
</div>

<?php if ($negativ): ?>
    <div class="alert alert-warning" role="alert">
        Achtung: Der Bestand ist negativ bei mindestens einem Artikel. Bitte Lieferungen oder die letzte Auszählung prüfen.
    </div>
<?php endif; ?>

<?php if ($gruppen === []): ?>
    <p class="text-muted">Keine Artikel mit Bestandsführung.</p>
<?php endif; ?>

<?php foreach ($gruppen as $gruppe): ?>
    <h2 class="h5 mt-4"><?= esc($gruppe['kategorie_name']) ?></h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Artikel</th>
                    <th class="text-end">Bestand</th>
                    <th class="text-end">Mindestbestand</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gruppe['artikel'] as $a): ?>
                    <tr>
                        <td><?= esc($a['name']) ?> <span class="text-muted small"><?= esc($a['einheit']) ?></span></td>
                        <td class="text-end"><?= esc($a['bestand']) ?></td>
                        <td class="text-end"><?= esc($a['mindestbestand']) ?></td>
                        <td><span class="badge-status <?= $ampelKlasse[$a['ampel']] ?>"><?= esc($ampelText[$a['ampel']]) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<p class="mt-4">
    <a href="<?= base_url('wart/' . $bereich['schluessel'] . '/bewegung?art=schwund') ?>">Schwund erfassen</a>
    &middot;
    <a href="<?= base_url('wart/' . $bereich['schluessel'] . '/bewegung?art=korrektur') ?>">Bestand korrigieren</a>
</p>
<?= $this->endSection() ?>
