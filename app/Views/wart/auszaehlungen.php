<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Auszählungen<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$anzeige = static fn (?string $zeit): string => $zeit === null ? '–' : date('d.m.Y H:i', strtotime($zeit));
$basis   = 'wart/' . $bereich['schluessel'] . '/auszaehlungen/';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Auszählungen – <?= esc($bereich['name']) ?></h1>
    <?php if ($darfErzeugen): ?>
        <a class="btn btn-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/auszaehlung') ?>">
            <i class="bi bi-clipboard-check" aria-hidden="true"></i> Neue Auszählung
        </a>
    <?php endif; ?>
</div>

<?php if ($auszaehlungen === []): ?>
    <p class="text-muted">Noch keine abgeschlossene Auszählung.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover table-stack align-middle">
            <thead class="table-vdst">
                <tr>
                    <th>Zeitraum</th>
                    <th>Art</th>
                    <th>Abgeschlossen</th>
                    <th>Datei</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auszaehlungen as $a): ?>
                    <?php $id = (int) $a['id']; ?>
                    <tr>
                        <td data-label="Zeitraum" class="text-nowrap"><?= esc($anzeige($a['zeitraum_von']) . ' – ' . $anzeige($a['stichtag'])) ?></td>
                        <td data-label="Art"><?= $a['art'] === 'start' ? 'Start' : 'Regulär' ?></td>
                        <td data-label="Abgeschlossen">
                            <?= esc($anzeige($a['abgeschlossen_at'])) ?>
                            <div class="small text-muted"><?= esc($a['abgeschlossen_von']) ?></div>
                        </td>
                        <td data-label="Datei">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <?php if ($a['datei_pfad'] !== null): ?>
                                    <a class="btn btn-outline-vdst btn-sm" href="<?= base_url($basis . $id . '/download') ?>">
                                        <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Download
                                    </a>
                                <?php else: ?>
                                    <span class="badge-status badge-status-amber">Datei fehlt</span>
                                <?php endif; ?>
                                <?php if ($darfErzeugen): ?>
                                    <form method="post" action="<?= esc(base_url($basis . $id . '/neu-erzeugen'), 'attr') ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-outline-vdst btn-sm">Datei neu erzeugen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
