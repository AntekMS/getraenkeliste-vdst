<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Tablets<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Tablets</h1>
    <form action="<?= base_url('admin/tablets/code') ?>" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-vdst"><i class="bi bi-key" aria-hidden="true"></i> Freischaltcode erzeugen</button>
    </form>
</div>

<?php if (is_array($code)): ?>
    <div class="card mb-4" role="status">
        <div class="card-body text-center">
            <p class="mb-1">Freischaltcode (wird nur einmal angezeigt)</p>
            <p class="display-4 fw-bold font-monospace mb-1"><?= esc($code['code']) ?></p>
            <p class="mb-0 text-muted">gültig bis <?= esc($code['gueltig_bis']) ?> Uhr</p>
        </div>
    </div>
<?php endif; ?>

<?php if ($geraete === []): ?>
    <p class="text-muted">Noch keine Tablets freigeschaltet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-vdst">
                <tr><th>Name</th><th>Zuletzt gesehen</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($geraete as $g): ?>
                    <tr>
                        <td>
                            <form action="<?= base_url('admin/tablets/' . $g['id'] . '/umbenennen') ?>" method="post" class="d-flex gap-2">
                                <?= csrf_field() ?>
                                <label for="name_<?= esc($g['id']) ?>" class="visually-hidden">Name des Tablets</label>
                                <input type="text" class="form-control form-control-sm" id="name_<?= esc($g['id']) ?>" name="name"
                                       value="<?= esc($g['name']) ?>" maxlength="100" required>
                                <button type="submit" class="btn btn-sm btn-outline-vdst">Umbenennen</button>
                            </form>
                        </td>
                        <td><?= $g['zuletzt_gesehen_at'] === null ? 'nie' : esc(date('d.m.Y H:i', strtotime($g['zuletzt_gesehen_at']))) ?></td>
                        <td>
                            <?php if ($g['gesperrt_at'] === null): ?>
                                <span class="badge text-bg-success">aktiv</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">gesperrt</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($g['gesperrt_at'] === null): ?>
                                <form action="<?= base_url('admin/tablets/' . $g['id'] . '/sperren') ?>" method="post">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-vdst">Sperren</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
