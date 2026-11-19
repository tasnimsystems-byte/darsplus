<?php
use App\Core\View;

/** @var array $student @var array $children @var array $terms @var array|null $term @var array $rows @var array $counts @var string $kind */
View::share('title', __('portal.remarks'));
$link = static fn (string $k): string => url('/me/remarks?' . http_build_query(['term_id' => $term['id'] ?? null, 'kind' => $k === 'all' ? null : $k]));
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('portal.remarks'), 'back' => '/me/remarks']) ?>
<?= View::partial('portal/_terms', ['terms' => $terms, 'term' => $term, 'action' => '/me/remarks']) ?>

<div class="row g-3 mb-4">
    <?php foreach (['positive' => 'hand-thumbs-up', 'negative' => 'exclamation-triangle'] as $group => $icon): ?>
        <div class="col-12 col-sm-6">
            <a class="card kpi-card h-100 remark-kpi remark-kpi-<?= e($group) ?><?= $kind === $group ? ' is-active' : '' ?>" href="<?= e($link($kind === $group ? 'all' : $group)) ?>"<?= $kind === $group ? ' aria-current="true"' : '' ?>>
                <div class="card-body">
                    <div class="kpi-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                    <div>
                        <div class="kpi-value"><?= (int) $counts[$group] ?></div>
                        <div class="kpi-label"><?= e(__('portal.remark_kinds.' . $group)) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<nav class="btn-group btn-group-sm mb-3" aria-label="<?= e(__('portal.remark_filter')) ?>">
    <?php foreach (['all', 'positive', 'negative'] as $k): ?>
        <a class="btn <?= $kind === $k ? 'btn-primary' : 'btn-outline-primary' ?>"<?= $kind === $k ? ' aria-current="page"' : '' ?> href="<?= e($link($k)) ?>"><?= e(__('portal.remark_kinds.' . $k)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card" aria-labelledby="pt-remarks">
    <h2 class="visually-hidden" id="pt-remarks"><?= e(__('portal.remarks')) ?></h2>
    <?php if ($rows === []): ?>
        <div class="empty-state"><i class="bi bi-chat-square" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.no_remarks')) ?></p></div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($rows as $row): ?>
                <li class="list-group-item portal-remark portal-remark-<?= e($row['kind']) ?>">
                    <i class="bi bi-<?= $row['kind'] === 'positive' ? 'hand-thumbs-up-fill' : 'exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong><?= e(__('attendance.remarks.' . $row['remark'])) ?></strong>
                        <span class="visually-hidden">(<?= e(__('portal.remark_kinds.' . $row['kind'])) ?>)</span>
                        <div class="small text-secondary"><?= e($row['subject']) ?> · <?= e($row['teacher']) ?> · <?= e(__('attendance.period', ['n' => (int) $row['period_number']])) ?></div>
                    </div>
                    <span class="small text-secondary text-nowrap" dir="ltr"><?= e(date('d/m/Y', strtotime($row['lesson_date']))) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<p class="small text-secondary mt-3"><?= e(__('portal.remarks_help')) ?></p>