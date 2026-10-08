<?php helper('betrag'); ?>
<div class="row g-3">
    <?php foreach ($artikelListe as $artikel): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="artikel-kachel" data-artikel-id="<?= (int) $artikel['id'] ?>"
                 data-name="<?= esc($artikel['name'], 'attr') ?>" data-preis-cent="<?= (int) $artikel['preis_cent'] ?>">
                <?php if (($artikel['bild_url'] ?? null) !== null): ?>
                    <img class="artikel-bild" src="<?= esc(base_url($artikel['bild_url']), 'attr') ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="artikel-kachel-name"><?= esc($artikel['name']) ?></div>
                <div class="artikel-kachel-einheit"><?= esc($artikel['einheit']) ?></div>
                <div class="artikel-kachel-preis"><?= esc(formatiere_cent((int) $artikel['preis_cent'])) ?></div>
                <div class="artikel-kachel-steuerung">
                    <button type="button" class="btn btn-outline-vdst js-minus" title="Eine Einheit weniger"
                            aria-label="Eine Einheit <?= esc($artikel['name'], 'attr') ?> weniger" disabled>
                        <i class="bi bi-dash-lg" aria-hidden="true"></i>
                    </button>
                    <span class="artikel-kachel-menge js-menge" aria-live="polite">0</span>
                    <button type="button" class="btn btn-outline-vdst js-plus" title="Eine Einheit mehr"
                            aria-label="Eine Einheit <?= esc($artikel['name'], 'attr') ?> mehr">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
