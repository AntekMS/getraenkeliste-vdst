<?php foreach (['success' => ['success', 'check-circle-fill', 'Erfolg!'], 'error' => ['danger', 'x-circle-fill', 'Fehler!']] as $schluessel => [$klasse, $icon, $titel]): ?>
    <?php if (session()->getFlashdata($schluessel)): ?>
        <div class="container-fluid mt-3">
            <div class="alert alert-<?= $klasse ?> alert-dismissible fade show js-auto-dismiss" role="alert">
                <strong><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i> <?= $titel ?></strong>
                <?= esc(session()->getFlashdata($schluessel)) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
