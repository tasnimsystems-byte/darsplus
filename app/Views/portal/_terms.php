<?php
/** @var array $terms @var array|null $term @var string $action */
?>
<?php if (count($terms) > 1): ?>
    <nav class="nav nav-pills mb-4 gap-2" aria-label="<?= e(__('portal.terms')) ?>">
        <?php foreach ($terms as $t): ?>
            <?php $active = $term !== null && (int) $t['id'] === (int) $term['id']; ?>
            <a class="nav-link border<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?> href="<?= e(url($action . '?term_id=' . (int) $t['id'])) ?>"><?= e($t['name']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
