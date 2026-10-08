<?php $tablet = ($modus ?? 'web') === 'tablet'; ?>
<?= $this->extend($tablet ? 'layouts/einfach' : 'layouts/main') ?>

<?= $this->section('title') ?>Buchen<?= $this->endSection() ?>

<?php if ($tablet): ?>
<?= $this->section('seitenklasse') ?>login-breit<?= $this->endSection() ?>
<?php endif; ?>

<?= $this->section('content') ?>
<div id="buchen-app" data-buchen-url="<?= esc(base_url($tablet ? 'tablet/buchen' : 'buchen'), 'attr') ?>"
     data-rueckgaengig-url="<?= esc(base_url($tablet ? 'tablet/rueckgaengig' : 'buchen/rueckgaengig'), 'attr') ?>"
<?php if ($tablet): ?>
     data-fertig-url="<?= esc(base_url('tablet/fertig'), 'attr') ?>" data-timeout-s="<?= (int) $timeoutS ?>"
<?php endif; ?>
     data-vorgang-id="<?= esc($vorgangId, 'attr') ?>"
     data-csrf-token="<?= esc(csrf_hash(), 'attr') ?>">

    <?php if ($tablet): ?>
        <div class="tablet-kopf">
            <h1 class="h3 mb-0"><i class="bi bi-person-circle" aria-hidden="true"></i> <?= esc($kontoName) ?></h1>
            <form action="<?= esc(base_url('tablet/fertig'), 'attr') ?>" method="post" class="js-fertig-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-vdst btn-lg"><i class="bi bi-x-lg" aria-hidden="true"></i> Abbrechen</button>
            </form>
        </div>
    <?php else: ?>
        <h1 class="h3 mb-3">Buchen</h1>
    <?php endif; ?>

    <?php if ($bereiche === []): ?>
        <div class="alert alert-info">Zurzeit gibt es keine buchbaren Artikel.</div>
    <?php else: ?>
        <ul class="nav nav-tabs mb-3" role="tablist">
            <?php foreach ($bereiche as $i => $bereich): ?>
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link<?= $i === 0 ? ' active' : '' ?>" data-bs-toggle="tab"
                            data-bs-target="#bereich-<?= esc($bereich['schluessel'], 'attr') ?>" role="tab">
                        <?= esc($bereich['name']) ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content">
            <?php foreach ($bereiche as $i => $bereich): ?>
                <div class="tab-pane fade<?= $i === 0 ? ' show active' : '' ?>" id="bereich-<?= esc($bereich['schluessel'], 'attr') ?>" role="tabpanel">
                    <ul class="nav nav-pills mb-3 gap-1" role="tablist">
                        <?php foreach ($bereich['kategorien'] as $k => $kategorie): ?>
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link<?= $k === 0 ? ' active' : '' ?>" data-bs-toggle="tab"
                                        data-bs-target="#kategorie-<?= (int) $kategorie['id'] ?>" role="tab">
                                    <?= esc($kategorie['name']) ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="tab-content">
                        <?php foreach ($bereich['kategorien'] as $k => $kategorie): ?>
                            <div class="tab-pane fade<?= $k === 0 ? ' show active' : '' ?>" id="kategorie-<?= (int) $kategorie['id'] ?>" role="tabpanel">
                                <?= $this->setVar('artikelListe', $kategorie['artikel'])->include('buchen/_artikel') ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="buchen-warenkorb mt-4" aria-labelledby="warenkorb-titel">
            <h2 class="h5" id="warenkorb-titel"><i class="bi bi-cart3" aria-hidden="true"></i> Warenkorb</h2>

            <?php if (! $tablet): ?>
            <div class="mb-3">
                <div class="btn-group" role="group" aria-label="Buchen für">
                    <input type="radio" class="btn-check" name="konto" id="konto-ich" value="ich" checked>
                    <label class="btn btn-outline-vdst" for="konto-ich">für mich</label>
                    <input type="radio" class="btn-check" name="konto" id="konto-couleur" value="couleur">
                    <label class="btn btn-outline-vdst" for="konto-couleur">Couleur</label>
                    <input type="radio" class="btn-check" name="konto" id="konto-bund" value="bund">
                    <label class="btn btn-outline-vdst" for="konto-bund">Bund</label>
                </div>
            </div>
            <?php endif; ?>

            <p class="text-muted mb-2 js-leer">Noch nichts ausgewählt.</p>
            <ul class="list-unstyled mb-2 js-positionen" hidden></ul>
            <div class="buchen-summe d-flex justify-content-between fw-semibold mb-3">
                <span>Summe</span><span class="js-summe">0,00 €</span>
            </div>

            <div class="alert alert-danger js-fehler" role="alert" hidden></div>

            <button type="button" class="btn btn-vdst js-buchen" disabled>
                <span class="spinner-border spinner-border-sm me-1 js-spinner" aria-hidden="true" hidden></span>
                <i class="bi bi-check2-circle js-buchen-icon" aria-hidden="true"></i> Buchen
            </button>
        </section>

        <section class="buchen-bestaetigung alert alert-success mt-4 js-bestaetigung" role="status" hidden>
            <p class="mb-2"><i class="bi bi-check-circle-fill js-bestaetigung-icon" aria-hidden="true"></i>
                <span class="js-bestaetigung-text"></span></p>
            <button type="button" class="btn btn-outline-vdst btn-sm js-rueckgaengig">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Rückgängig
            </button>
            <?php if ($tablet): ?>
                <p class="mb-0 mt-2 small js-zurueck-info" hidden>Zurück zur Namensauswahl in <span class="js-zurueck-sekunden"></span> s</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('js/buchen.js') ?>?v=3"></script>
<?= $this->endSection() ?>
