<?php
use App\Core\View;

View::share('title', $task['title']);
$id = (int) $task['id'];
$priorityBadge = ['low' => 'text-bg-light', 'normal' => 'text-bg-light', 'high' => 'text-bg-warning', 'urgent' => 'text-bg-danger'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($task['title']) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge text-bg-primary"><?= e(__('tasks.statuses.' . $task['status'])) ?></span>
            <span class="badge <?= $priorityBadge[$task['priority']] ?>"><?= e(__('tasks.priorities.' . $task['priority'])) ?></span>
            <?= e(__('tasks.fields.assignee_user_id')) ?>: <?= e($meta['assignee_name'] ?? '—') ?>
            · <?= e(__('tasks.assigned_by')) ?>: <?= e($meta['assigner_name'] ?? '—') ?>
            · <?= e(__('tasks.due')) ?>: <span dir="ltr"><?= e($task['due_date'] ? date('d/m/Y', strtotime($task['due_date'])) : '—') ?></span>
            <?php if ($overdue): ?><span class="badge text-bg-warning"><?= e(__('tasks.overdue')) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($meetingId !== null && can('meetings.view')): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/meetings/' . (int) $meetingId)) ?>"><?= e(__('tasks.from_meeting')) ?></a>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/tasks/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('tasks.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/tasks')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <div class="card mb-4">
            <div class="card-body prewrap"><?= e($task['description'] ?? __('tasks.no_description')) ?></div>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('tasks.comments')) ?></h2></div>
            <?php if ($comments !== []): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($comments as $comment): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2 small text-secondary">
                                <span><?= e($comment['full_name'] ?? '—') ?></span><time dir="ltr"><?= e(format_datetime($comment['created_at'])) ?></time>
                            </div>
                            <div class="prewrap"><?= e($comment['body']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form class="card-body<?= $comments !== [] ? ' border-top' : '' ?>" method="post" action="<?= e(url("/tasks/$id/comments")) ?>" novalidate>
                <?= csrf_field() ?>
                <?= View::partial('partials/input', ['name' => 'body', 'label' => __('tasks.comment_fields.body'), 'type' => 'textarea', 'required' => true, 'attrs' => 'maxlength="2000"']) ?>
                <button class="btn btn-outline-primary" type="submit"><?= e(__('tasks.add_comment')) ?></button>
            </form>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <?php if (can('tasks.update')): ?>
            <form class="card" method="post" action="<?= e(url("/tasks/$id/status")) ?>">
                <?= csrf_field() ?>
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('tasks.update_status')) ?></h2></div>
                <div class="card-body">
                    <?= View::partial('partials/input', ['name' => 'status', 'label' => __('tasks.fields.status'), 'type' => 'select', 'required' => true, 'value' => $task['status'], 'options' => $statuses]) ?>
                    <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
