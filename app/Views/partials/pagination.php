<?php
/**
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var string $path   route path, e.g. /users
 * @var array  $query  other query parameters to keep
 */
$pages = max(1, (int) ceil($total / $perPage));
$link = static fn (int $p): string => url($path) . '?' . http_build_query(array_filter($query ?? [], static fn ($v) => $v !== '') + ['page' => $p]);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <div class="text-secondary small"><?= e(__('app.total', ['count' => $total])) ?></div>
    <?php if ($pages > 1): ?>
        <nav aria-label="<?= e(__('app.pagination')) ?>">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <?php if ($page <= 1): ?>
                        <span class="page-link"><?= e(__('app.previous')) ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= e($link($page - 1)) ?>" rel="prev"><?= e(__('app.previous')) ?></a>
                    <?php endif; ?>
                </li>
                <li class="page-item active" aria-current="page"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
                    <?php if ($page >= $pages): ?>
                        <span class="page-link"><?= e(__('app.next')) ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= e($link($page + 1)) ?>" rel="next"><?= e(__('app.next')) ?></a>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
