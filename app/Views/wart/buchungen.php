<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Buchungen<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
helper('betrag');
$quellen = ['web' => 'Web', 'tablet' => 'Tablet', 'korrektur' => 'Korrektur'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Buchungen <?= esc($bereich['name']) ?></h1>
    <a class="btn btn-vdst" href="<?= base_url('wart/' . $bereich['schluessel'] . '/korrektur') ?>">
        <i class="bi bi-pencil-square" aria-hidden="true"></i> Korrektur buchen
    </a>
</div>

<form method="get" action="<?= base_url('wart/' . $bereich['schluessel'] . '/buchungen') ?>" class="row g-2 mb-3">
    <div class="col-12 col-md-auto">
        <label for="person" class="form-label mb-0 small">Konto</label>
        <select class="form-select" id="person" name="person">
            <option value="">Alle</option>
            <?php foreach ($konten as $k): ?>
                <option value="<?= esc($k['id']) ?>" <?= (int) $filter['person'] === (int) $k['id'] ? 'selected' : '' ?>><?= esc($k['anzeigename']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-auto">
        <label for="artikel" class="form-label mb-0 small">Artikel</label>
        <select class="form-select" id="artikel" name="artikel">
            <option value="">Alle</option>
            <?php foreach ($artikel as $gruppe): ?>
                <optgroup label="<?= esc($gruppe['kategorie']) ?>">
                    <?php foreach ($gruppe['artikel'] as $a): ?>
                        <option value="<?= esc($a['id']) ?>" <?= (int) $filter['artikel'] === (int) $a['id'] ? 'selected' : '' ?>><?= esc($a['name']) ?><?= $a['archiviert_at'] !== null ? ' (archiviert)' : '' ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-auto">
        <label for="tag" class="form-label mb-0 small">Tag</label>
        <input type="date" class="form-control" id="tag" name="tag" value="<?= esc((string) $filter['tag']) ?>">
    </div>
    <div class="col-12 col-md-auto d-flex align-items-end"><button type="submit" class="btn btn-outline-vdst">Filtern</button></div>
</form>

<?php if ($zeilen === []): ?>
    <p class="text-muted">Keine Buchungen im laufenden Zeitraum gefunden.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover table-stack align-middle">
            <thead class="table-vdst">
                <tr>
                    <th>Gebucht am</th>
                    <th>Konto</th>
                    <th>Gebucht von</th>
                    <th>Artikel</th>
                    <th class="text-end">Menge</th>
                    <th class="text-end">Summe</th>
                    <th>Quelle</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($zeilen as $z): ?>
                    <?php $storniert = $z['storniert_at'] !== null; ?>
                    <tr>
                        <td data-label="Gebucht am" class="text-nowrap"><?= esc(date('d.m.Y H:i', strtotime($z['gebucht_at']))) ?></td>
                        <td data-label="Konto"><?= esc($z['konto_name']) ?></td>
                        <td data-label="Gebucht von"><?= $z['gebucht_von_name'] === null ? '<span class="text-muted">Tablet</span>' : esc($z['gebucht_von_name']) ?></td>
                        <td data-label="Artikel"><?= esc($z['artikel_name']) ?><?= $z['bemerkung'] !== null ? '<div class="small text-muted">' . esc($z['bemerkung']) . '</div>' : '' ?></td>
                        <td data-label="Menge" class="text-end"><?= (int) $z['menge'] ?></td>
                        <td data-label="Summe" class="text-end"><?= esc(formatiere_cent((int) $z['menge'] * (int) $z['einzelpreis_cent'])) ?></td>
                        <td data-label="Quelle"><?= esc($quellen[$z['quelle']] ?? $z['quelle']) ?></td>
                        <td data-label="Status">
                            <?php if ($storniert): ?>
                                <span class="badge-status badge-status-neutral">storniert</span>
                                <?php if ($z['storno_grund'] !== null): ?><div class="small text-muted"><?= esc($z['storno_grund']) ?></div><?php endif; ?>
                            <?php elseif ($eingefroren[(int) $z['id']]): ?>
                                <span class="badge-status badge-status-neutral">abgeschlossen</span>
                            <?php else: ?>
                                <form method="post" action="<?= esc(base_url('wart/' . $bereich['schluessel'] . '/buchungen/' . (int) $z['id'] . '/storno'), 'attr') ?>" class="d-flex gap-2">
                                    <?= csrf_field() ?>
                                    <label for="grund-<?= (int) $z['id'] ?>" class="visually-hidden">Grund</label>
                                    <input type="text" class="form-control form-control-sm" id="grund-<?= (int) $z['id'] ?>" name="grund" maxlength="255" placeholder="Grund" required>
                                    <button type="submit" class="btn btn-outline-vdst btn-sm">Stornieren</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links('default', 'bootstrap_full') ?>
<?php endif; ?>
<?= $this->endSection() ?>
