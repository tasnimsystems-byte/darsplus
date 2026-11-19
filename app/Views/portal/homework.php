<?php
use App\Core\View;

/** @var array $student @var array $children @var array $rows */
View::share('title', __('portal.homework'));
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('portal.homework'), 'back' => '/me/homework']) ?>

<?php if ($rows === []): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-pencil-square" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.no_homework')) ?></p></div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($rows as $row): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <article class="card h-100 portal-homework">
                    <div class="card-header d-flex justify-content-between gap-2">
                        <h2 class="h6 mb-0"><?= e($row['subject']) ?></h2>
                        <span class="small text-secondary" dir="ltr"><?= e(date('d/m/Y', strtotime($row['lesson_date']))) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (($row['topic'] ?? '') !== ''): ?>
                            <p class="small text-secondary mb-2"><?= e(__('portal.topic')) ?>: <?= e($row['topic']) ?></p>
                        <?php endif; ?>
                        <?php if (($row['homework'] ?? '') !== ''): ?>
                            <p class="mb-0 text-prewrap"><?= e($row['homework']) ?></p>
                        <?php endif; ?>
                        <?php if ($row['homework_due'] !== null): ?>
                            <p class="small fw-semibold text-primary mt-2 mb-0"><i class="bi bi-calendar-check" aria-hidden="true"></i> <?= e(__('portal.due_on', ['date' => date('d/m/Y', strtotime($row['homework_due']))])) ?></p>
                        <?php endif; ?>
                        <?php if ($row['attachments'] !== []): ?>
                            <div class="mt-2">
                                <div class="visually-hidden"><?= e(__('portal.attachments')) ?></div>
                                <?= View::partial('partials/homework_attachments', ['attachments' => $row['attachments']]) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small text-secondary mt-3"><?= e(__('portal.homework_help')) ?></p>
<?php endif; ?>
