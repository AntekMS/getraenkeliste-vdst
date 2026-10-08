<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Meine Buchungen<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php helper('betrag'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Meine Buchungen</h1>
    <a class="btn btn-vdst" href="<?= base_url('buchen') ?>"><i class="bi bi-cup-straw" aria-hidden="true"></i> Jetzt buchen</a>
</div>

<?php foreach ($bereiche as $bereich): ?>
    <section class="mb-4">
        <h2 class="h5"><?= esc($bereich['name']) ?></h2>
        <p class="mb-2">Offener Betrag: <strong><?= esc(formatiere_cent($bereich['betrag'])) ?></strong></p>
        <?php if ($bereich['zeilen'] === []): ?>
            <p class="text-muted">Keine Buchungen im laufenden Zeitraum.</p>
        <?php else: ?>
            <?= view('meine_buchungen/_tabelle', ['zeilen' => $bereich['zeilen'], 'mitKonto' => false, 'stornierbar' => $stornierbar]) ?>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($sammel !== []): ?>
    <section class="mb-4">
        <h2 class="h5">Von dir auf Couleur/Bund gebucht</h2>
        <p class="text-muted mb-2">Zählt nicht zu deinem offenen Betrag.</p>
        <?= view('meine_buchungen/_tabelle', ['zeilen' => $sammel, 'mitKonto' => true, 'stornierbar' => $stornierbar]) ?>
    </section>
<?php endif; ?>

<?php if ($fruehere !== []): ?>
    <section class="mb-4">
        <h2 class="h5">Frühere Zeiträume</h2>
        <?php foreach ($fruehere as $bereich): ?>
            <h3 class="h6 mt-3"><?= esc($bereich['name']) ?></h3>
            <div class="table-responsive">
                <table class="table table-hover table-stack mb-0">
                    <thead class="table-vdst">
                    <tr>
                        <th>Zeitraum</th>
                        <th class="text-end">Deine Summe</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bereich['zeitraeume'] as $z): ?>
                        <tr>
                            <td data-label="Zeitraum"><?= esc($z['von']->format('d.m.Y H:i')) ?> – <?= esc($z['bis']->format('d.m.Y H:i')) ?></td>
                            <td data-label="Deine Summe" class="text-end"><?= esc(formatiere_cent($z['summe'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?= $this->endSection() ?>
