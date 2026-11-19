<?php
use App\Controllers\TimetableController;
use App\Core\View;

/** @var array $student @var array $children @var bool $isParent @var array $meetings @var bool $enabled @var array $periods */
View::share('title', __('online.live_title'));
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('online.live_title'), 'back' => '/me/live']) ?>

<div data-live-refresh>
    <?php if (!$enabled): ?>
        <div class="empty-state card"><i class="bi bi-camera-video-off" aria-hidden="true"></i><p class="mb-0"><?= e(__('online.live_disabled')) ?></p></div>
    <?php elseif ($isParent): ?>
        <div class="alert alert-info" role="note"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= e(__('online.live_parent')) ?></div>
    <?php endif; ?>

    <?php if ($enabled && $meetings === []): ?>
        <div class="empty-state card"><i class="bi bi-camera-video" aria-hidden="true"></i><p class="mb-0"><?= e(__('online.live_none')) ?></p></div>
    <?php endif; ?>

    <div class="live-list">
        <?php foreach ($meetings as $meeting): ?>
            <article class="card live-card">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <span class="live-dot" aria-hidden="true"></span>
                    <div class="flex-grow-1 min-w-0">
                        <h2 class="h5 mb-1"><?= e($meeting['subject_name']) ?></h2>
                        <p class="small text-secondary mb-0">
                            <?= e($meeting['teacher_name']) ?>
                            · <?= e($meeting['study_group_name'] ?? $meeting['class_name']) ?>
                            · <?= e(TimetableController::periodLabel($periods, (int) $meeting['period_number'])) ?>
                            · <?= e(__('online.started_at', ['time' => date('H:i', strtotime($meeting['started_at']))])) ?>
                        </p>
                    </div>
                    <?php if (!$isParent): ?>
                        <a class="btn btn-primary btn-lg" href="<?= e(url('/online/' . (int) $meeting['id'])) ?>"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> <?= e(__('online.join_short')) ?></a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
