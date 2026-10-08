<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * Bootstrap-5-Pager (Config\Pager::$templates['bootstrap_full']).
 *
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>
<nav aria-label="<?= lang('Pager.pageNavigation') ?>">
    <ul class="pagination flex-wrap">
        <?php if ($pager->hasPrevious()): ?>
            <li class="page-item"><a class="page-link" href="<?= $pager->getFirst() ?>" aria-label="<?= lang('Pager.first') ?>"><?= lang('Pager.first') ?></a></li>
            <li class="page-item"><a class="page-link" href="<?= $pager->getPrevious() ?>" aria-label="<?= lang('Pager.previous') ?>"><?= lang('Pager.previous') ?></a></li>
        <?php endif; ?>

        <?php foreach ($pager->links() as $link): ?>
            <li class="page-item <?= $link['active'] ? 'active' : '' ?>">
                <a class="page-link" href="<?= $link['uri'] ?>" <?= $link['active'] ? 'aria-current="page"' : '' ?>><?= $link['title'] ?></a>
            </li>
        <?php endforeach; ?>

        <?php if ($pager->hasNext()): ?>
            <li class="page-item"><a class="page-link" href="<?= $pager->getNext() ?>" aria-label="<?= lang('Pager.next') ?>"><?= lang('Pager.next') ?></a></li>
            <li class="page-item"><a class="page-link" href="<?= $pager->getLast() ?>" aria-label="<?= lang('Pager.last') ?>"><?= lang('Pager.last') ?></a></li>
        <?php endif; ?>
    </ul>
</nav>
