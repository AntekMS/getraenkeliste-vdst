<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Getränke &amp; Preise<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Getränke &amp; Preise</h1>
    <a class="btn btn-outline-vdst" href="<?= base_url('admin/stammdaten' . ($archiviert ? '' : '?archiviert=ja')) ?>">
        <?= $archiviert ? 'Archivierte ausblenden' : 'Archivierte zeigen' ?>
    </a>
</div>

<form action="<?= base_url('admin/kategorien') ?>" method="post" class="row g-2 mb-4">
    <?= csrf_field() ?>
    <?php if (count($bereiche) === 1): ?>
        <input type="hidden" name="bereich_id" value="<?= esc($bereiche[0]['id']) ?>">
    <?php else: ?>
        <div class="col-auto">
            <label for="neu_bereich" class="visually-hidden">Bereich</label>
            <select class="form-select" id="neu_bereich" name="bereich_id">
                <?php foreach ($bereiche as $b): ?>
                    <option value="<?= esc($b['id']) ?>"><?= esc($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-12 col-sm">
        <label for="neu_kategorie" class="visually-hidden">Name der neuen Kategorie</label>
        <input type="text" class="form-control" id="neu_kategorie" name="name" maxlength="100" placeholder="Neue Kategorie, z. B. Bier" required>
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-vdst">Kategorie anlegen</button></div>
</form>

<?php foreach ($bereiche as $bereich): ?>
    <h2 class="h4 mt-4"><?= esc($bereich['name']) ?></h2>

    <?php if ($bereich['kategorien'] === []): ?>
        <p class="text-muted">Noch keine Kategorien.</p>
    <?php endif; ?>

    <?php foreach ($bereich['kategorien'] as $kategorie): ?>
        <?php $archivKat = $kategorie['archiviert_at'] !== null; ?>
        <section class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <?php if ($archivKat): ?>
                    <span class="fw-semibold"><?= esc($kategorie['name']) ?> <span class="badge text-bg-secondary">archiviert</span></span>
                <?php else: ?>
                    <form action="<?= base_url('admin/kategorien/' . $kategorie['id']) ?>" method="post" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <label for="kat_name_<?= esc($kategorie['id']) ?>" class="visually-hidden">Name der Kategorie</label>
                        <input type="text" class="form-control form-control-sm" id="kat_name_<?= esc($kategorie['id']) ?>" name="name"
                               value="<?= esc($kategorie['name']) ?>" maxlength="100" required>
                        <button type="submit" class="btn btn-sm btn-outline-vdst">Umbenennen</button>
                    </form>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach (['hoch' => ['arrow-up', 'nach oben'], 'runter' => ['arrow-down', 'nach unten']] as $richtung => [$icon, $text]): ?>
                            <form action="<?= base_url('admin/kategorien/' . $kategorie['id'] . '/verschieben/' . $richtung) ?>" method="post">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-vdst" aria-label="Kategorie <?= esc($kategorie['name']) ?> <?= $text ?>">
                                    <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endforeach; ?>
                        <a class="btn btn-sm btn-outline-vdst" href="<?= base_url('admin/artikel/neu?kategorie=' . $kategorie['id']) ?>">Artikel anlegen</a>
                        <form action="<?= base_url('admin/kategorien/' . $kategorie['id'] . '/archivieren') ?>" method="post">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Archivieren</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($kategorie['artikel'] === []): ?>
                <div class="card-body text-muted">Keine Artikel.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-vdst">
                            <tr><th>Name</th><th>Einheit</th><th class="text-end">Preis</th><th class="text-end">Gebinde</th><th class="text-end">Mindestbestand</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kategorie['artikel'] as $a): ?>
                                <?php $archivArt = $a['archiviert_at'] !== null; ?>
                                <tr>
                                    <td><?= esc($a['name']) ?><?php if ($archivArt): ?> <span class="badge text-bg-secondary">archiviert</span><?php endif; ?></td>
                                    <td><?= esc($a['einheit']) ?></td>
                                    <td class="text-end"><?= esc(formatiere_cent((int) $a['preis_cent'])) ?></td>
                                    <td class="text-end"><?= $a['gebinde_groesse'] === null ? '–' : esc($a['gebinde_groesse']) ?></td>
                                    <td class="text-end"><?= (int) $a['bestand_fuehren'] === 1 ? esc($a['mindestbestand']) : '–' ?></td>
                                    <td class="text-end">
                                        <?php if (! $archivArt): ?>
                                            <div class="d-flex flex-wrap justify-content-end gap-1">
                                                <?php foreach (['hoch' => ['arrow-up', 'nach oben'], 'runter' => ['arrow-down', 'nach unten']] as $richtung => [$icon, $text]): ?>
                                                    <form action="<?= base_url('admin/artikel/' . $a['id'] . '/verschieben/' . $richtung) ?>" method="post">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="btn btn-sm btn-outline-vdst" aria-label="<?= esc($a['name']) ?> <?= $text ?>">
                                                            <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                <?php endforeach; ?>
                                                <a class="btn btn-sm btn-outline-vdst" href="<?= base_url('admin/artikel/' . $a['id']) ?>">Bearbeiten</a>
                                                <form action="<?= base_url('admin/artikel/' . $a['id'] . '/archivieren') ?>" method="post">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Archivieren</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endforeach; ?>
<?= $this->endSection() ?>
