<?php /** @var array<string, mixed> $person */ ?>
<?php if ($person['hat_pin']): ?>
    <form action="<?= esc(site_url('tablet/waehlen/' . (int) $person['id']), 'attr') ?>" method="post" class="namen-kachel-form"
          data-name="<?= esc(mb_strtolower($person['anzeigename']), 'attr') ?>" data-gruppe="<?= esc($person['gruppe'], 'attr') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="namen-kachel"><?= esc($person['anzeigename']) ?></button>
    </form>
<?php else: ?>
    <div class="namen-kachel-form" data-name="<?= esc(mb_strtolower($person['anzeigename']), 'attr') ?>" data-gruppe="<?= esc($person['gruppe'], 'attr') ?>">
        <div class="namen-kachel namen-kachel-gesperrt" aria-disabled="true">
            <?= esc($person['anzeigename']) ?>
            <span class="namen-kachel-hinweis">Bitte zuerst am eigenen Gerät eine PIN setzen</span>
        </div>
    </div>
<?php endif; ?>
