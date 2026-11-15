<?php
use App\Core\View;

View::share('title', __('tasks.board'));
$priorityBadge = ['low' => 'text-bg-light', 'normal' => 'text-bg-light', 'high' => 'text-bg-warning', 'urgent' => 'text-bg-danger'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('tasks.board')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__($all ? 'tasks.board_all' : 'tasks.board_mine')) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('tasks.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/tasks/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= e(__('tasks.add')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/tasks')) ?>"><?= e(__('tasks.list')) ?></a>
    </div>
</div>

<div class="board">
    <?php foreach ($columns as $status => $tasks): ?>
        <section class="card" aria-labelledby="col-<?= e($status) ?>">
            <div class="card-header d-flex justify-content-between">
                <h2 class="h6 mb-0" id="col-<?= e($status) ?>"><?= e(__('tasks.statuses.' . $status)) ?></h2>
                <span class="badge text-bg-light"><?= count($tasks) ?></span>
            </div>
            <div class="card-body d-grid gap-2">
                <?php if ($tasks === []): ?>
                    <span class="text-secondary small"><?= e(__('app.none')) ?></span>
                <?php endif; ?>
                <?php foreach ($tasks as $task): ?>
                    <?php $late = $status !== 'done' && $task['due_date'] !== null && $task['due_date'] < $today; ?>
                    <a class="board-card" href="<?= e(url('/tasks/' . (int) $task['id'])) ?>">
                        <div><?= e($task['title']) ?></div>
                        <div class="small text-secondary">
                            <?= e($task['assignee_name'] ?? '—') ?>
                            <?php if ($task['due_date']): ?> · <span dir="ltr"><?= e(date('d/m/Y', strtotime($task['due_date']))) ?></span><?php endif; ?>
                        </div>
                        <?php if (in_array($task['priority'], ['high', 'urgent'], true)): ?><span class="badge <?= $priorityBadge[$task['priority']] ?>"><?= e(__('tasks.priorities.' . $task['priority'])) ?></span><?php endif; ?>
                        <?php if ($late): ?><span class="badge text-bg-warning"><?= e(__('tasks.overdue')) ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
