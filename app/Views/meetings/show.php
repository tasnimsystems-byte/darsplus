<?php
use App\Core\View;

View::share('title', $meeting['title']);
$id = (int) $meeting['id'];
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$today = date('Y-m-d');
$statusBadge = ['planned' => 'text-bg-primary', 'held' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($meeting['title']) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge <?= $statusBadge[$meeting['status']] ?>"><?= e(__('meetings.statuses.' . $meeting['status'])) ?></span>
            <?= e(__('meetings.types.' . $meeting['meeting_type'])) ?>
            · <span dir="ltr"><?= e($date($meeting['meeting_date'])) ?><?= $meeting['start_time'] ? ' ' . e(substr($meeting['start_time'], 0, 5)) : '' ?></span>
            <?= $meeting['location'] ? ' · ' . e($meeting['location']) : '' ?>
            · <?= e(__('meetings.organizer')) ?>: <?= e($meta['organizer_name'] ?? '—') ?>
            <?= $meta['student_name'] ? ' · ' . e(__('meetings.fields.student_id')) . ': ' . e($meta['student_name']) : '' ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/meetings/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('meetings.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/meetings')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('meetings.fields.agenda')) ?></h2></div>
            <div class="card-body prewrap"><?= e($meeting['agenda'] ?? __('meetings.no_agenda')) ?></div>
        </div>

        <?php if ($previous !== []): ?>
            <div class="card">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('meetings.previous_open')) ?></h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($previous as $item): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="prewrap"><?= e($item['decision']) ?></div>
                                <div class="small text-secondary">
                                    <a href="<?= e(url('/meetings/' . (int) $item['meeting_id'])) ?>"><?= e($item['title']) ?></a>
                                    · <span dir="ltr"><?= e($date($item['meeting_date'])) ?></span> · <?= e($item['owner_name'] ?? '—') ?>
                                    <?php if ($item['due_date'] !== null && $item['due_date'] < $today): ?><span class="badge text-bg-warning"><?= e(__('tasks.overdue')) ?></span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canEdit): ?>
                                <form method="post" action="<?= e(url("/meetings/$id/decisions/" . (int) $item['id'] . '/toggle')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-success" type="submit" aria-label="<?= e(__('goals.complete_action') . ': ' . mb_substr($item['decision'], 0, 60)) ?>"><i class="bi bi-check2" aria-hidden="true"></i></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-7">
        <form class="card mb-4" method="post" action="<?= e(url("/meetings/$id/minutes")) ?>">
            <?= csrf_field() ?>
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('meetings.minutes_and_attendance')) ?></h2></div>
            <div class="card-body">
                <fieldset class="mb-3"<?= $canEdit ? '' : ' disabled' ?>>
                    <legend class="form-label fs-6"><?= e(__('meetings.fields.participant_ids')) ?></legend>
                    <?php if ($participants === []): ?>
                        <div class="text-secondary"><?= e(__('meetings.no_participants')) ?></div>
                    <?php endif; ?>
                    <div class="row row-cols-1 row-cols-md-2 g-1">
                        <?php foreach ($participants as $participant): ?>
                            <div class="col">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="attended[]" value="<?= (int) $participant['user_id'] ?>" id="att-<?= (int) $participant['user_id'] ?>"
                                           <?= $participant['attendance'] === 'attended' || ($meeting['status'] === 'planned' && $participant['attendance'] === 'invited') ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="att-<?= (int) $participant['user_id'] ?>">
                                        <?= e($participant['full_name']) ?>
                                        <?php if ($participant['attendance'] !== 'invited'): ?><span class="small text-secondary">(<?= e(__('meetings.attendance.' . $participant['attendance'])) ?>)</span><?php endif; ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="form-label" for="minutes"><?= e(__('meetings.minutes')) ?></label>
                <?php if ($canEdit): ?>
                    <textarea class="form-control mb-3" id="minutes" name="minutes" rows="8" maxlength="20000"><?= e($meeting['minutes'] ?? '') ?></textarea>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="mark_held" name="mark_held" value="1"<?= $meeting['status'] !== 'cancelled' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="mark_held"><?= e(__('meetings.mark_held')) ?></label>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
                <?php else: ?>
                    <div class="prewrap" id="minutes"><?= e($meeting['minutes'] ?? __('meetings.no_minutes')) ?></div>
                <?php endif; ?>
            </div>
        </form>

        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('meetings.decisions')) ?></h2></div>
            <?php if ($decisions === []): ?>
                <div class="card-body text-secondary"><?= e(__('meetings.no_decisions')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($decisions as $decision): ?>
                        <?php
                        $did = (int) $decision['id'];
                        $done = $decision['status'] === 'done';
                        $mayToggle = $canEdit || (int) $decision['owner_user_id'] === $userId;
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="prewrap<?= $done ? ' text-decoration-line-through text-secondary' : '' ?>"><?= e($decision['decision']) ?></div>
                                <div class="small text-secondary">
                                    <?= e($decision['owner_name'] ?? '—') ?> · <span dir="ltr"><?= e($date($decision['due_date'])) ?></span>
                                    <?php if (!$done && $decision['due_date'] !== null && $decision['due_date'] < $today): ?><span class="badge text-bg-warning"><?= e(__('tasks.overdue')) ?></span><?php endif; ?>
                                    <?php if ($decision['task_id'] !== null): ?> · <a href="<?= e(url('/tasks/' . (int) $decision['task_id'])) ?>"><?= e(__('meetings.linked_task')) ?></a><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <?php if ($mayToggle): ?>
                                    <form method="post" action="<?= e(url("/meetings/$id/decisions/$did/toggle")) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm <?= $done ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"
                                                aria-label="<?= e(__($done ? 'goals.reopen_action' : 'goals.complete_action') . ': ' . mb_substr($decision['decision'], 0, 60)) ?>">
                                            <i class="bi bi-<?= $done ? 'arrow-counterclockwise' : 'check2' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <form method="post" action="<?= e(url("/meetings/$id/decisions/$did/delete")) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => mb_substr($decision['decision'], 0, 60)])) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => mb_substr($decision['decision'], 0, 60)])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <form class="card-body border-top row g-2" method="post" action="<?= e(url("/meetings/$id/decisions")) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-12"><?= View::partial('partials/input', ['name' => 'decision', 'label' => __('meetings.decision_fields.decision'), 'type' => 'textarea', 'required' => true, 'attrs' => 'maxlength="2000"']) ?></div>
                    <div class="col-7"><?= View::partial('partials/input', ['name' => 'owner_user_id', 'label' => __('meetings.decision_fields.owner_user_id'), 'type' => 'select', 'options' => ['' => '—'] + $users]) ?></div>
                    <div class="col-5"><?= View::partial('partials/input', ['name' => 'due_date', 'label' => __('meetings.decision_fields.due_date'), 'type' => 'date']) ?></div>
                    <div class="col-12 d-flex align-items-center gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="create_task" name="create_task" value="1" checked>
                            <label class="form-check-label" for="create_task"><?= e(__('meetings.create_task')) ?></label>
                        </div>
                        <button class="btn btn-primary" type="submit"><?= e(__('meetings.add_decision')) ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
