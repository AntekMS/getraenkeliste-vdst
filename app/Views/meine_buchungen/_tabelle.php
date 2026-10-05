<?php helper('betrag'); ?>
<div class="table-responsive">
    <table class="table table-hover table-stack mb-0">
        <thead class="table-vdst">
        <tr>
            <th>Gebucht am</th>
            <?php if ($mitKonto): ?><th>Konto</th><?php endif; ?>
            <th>Artikel</th>
            <th class="text-end">Menge</th>
            <th class="text-end">Einzelpreis</th>
            <th class="text-end">Summe</th>
            <th>Status</th>
            <th class="text-center">Aktion</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($zeilen as $z): ?>
            <?php $storniert = $z['storniert_at'] !== null; ?>
            <tr class="<?= $storniert ? 'text-muted text-decoration-line-through' : '' ?>">
                <td data-label="Gebucht am"><?= esc(date('d.m.Y H:i', strtotime($z['gebucht_at']))) ?></td>
                <?php if ($mitKonto): ?><td data-label="Konto"><?= esc($z['konto_name']) ?></td><?php endif; ?>
                <td data-label="Artikel"><?= esc($z['artikel_name']) ?></td>
                <td data-label="Menge" class="text-end"><?= (int) $z['menge'] ?></td>
                <td data-label="Einzelpreis" class="text-end"><?= esc(formatiere_cent((int) $z['einzelpreis_cent'])) ?></td>
                <td data-label="Summe" class="text-end"><?= esc(formatiere_cent((int) $z['menge'] * (int) $z['einzelpreis_cent'])) ?></td>
                <td data-label="Status">
                    <?php if ($storniert): ?>
                        <span class="badge-status badge-status-neutral">storniert</span>
                    <?php endif; ?>
                </td>
                <td data-label="Aktion" class="text-center stack-actions">
                    <?php if ($stornierbar($z)): ?>
                        <form method="post" action="<?= esc(base_url('meine-buchungen/storno/' . (int) $z['id']), 'attr') ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-vdst btn-sm">
                                <i class="bi bi-x-circle" aria-hidden="true"></i> Stornieren
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
