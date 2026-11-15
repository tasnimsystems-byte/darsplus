<?php
use App\Controllers\CurriculumController;
use App\Core\View;

View::share('title', __('curriculum.progress_title'));
$id = (int) $plan['id'];
$aid = (int) $assignment['id'];
$statuses = [];
foreach (CurriculumController::PROGRESS as $status) {
    $statuses[$status] = __('curriculum.progress_statuses.' . $status);
}
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('curriculum.progress_title')) ?></h1>
        <p class="text-secondary mb-0"><?= e($plan['title']) ?> · <?= e($assignment['class_name']) ?> · <?= e($assignment['teacher_name']) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url("/curriculum/$id")) ?>"><?= e(__('app.back')) ?></a>
</div>

<?php if ($topics === []): ?>
    <div class="card card-body text-secondary"><?= e(__('curriculum.no_topics')) ?></div>
<?php else: ?>
    <form method="post" action="<?= e(url("/curriculum/$id/progress/$aid")) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden"><?= e(__('curriculum.progress_title')) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('curriculum.topic_fields.title')) ?></th>
                        <th scope="col"><?= e(__('curriculum.topic_fields.planned_week_start')) ?></th>
                        <th scope="col"><?= e(__('curriculum.progress_fields.status')) ?></th>
                        <th scope="col"><?= e(__('curriculum.progress_fields.actual_date')) ?></th>
                        <th scope="col"><?= e(__('curriculum.progress_fields.actual_hours')) ?></th>
                        <th scope="col"><?= e(__('curriculum.progress_fields.note')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($topics as $topic): ?>
                        <?php
                        $tid = (int) $topic['id'];
                        $p = $progress[$tid] ?? [];
                        $current = $p['status'] ?? 'not_started';
                        $late = $current !== 'done' && $current !== 'skipped' && $topic['planned_week_start'] !== null && $topic['planned_week_start'] <= date('Y-m-d');
                        ?>
                        <tr class="<?= $late ? 'table-warning' : '' ?>">
                            <th scope="row" class="fw-normal<?= $topic['parent_topic_id'] !== null ? ' topic-child' : '' ?>">
                                <?= e($topic['title']) ?><div class="small text-secondary"><?= e($topic['unit_title']) ?></div>
                            </th>
                            <td dir="ltr" class="text-end"><?= e($topic['planned_week_start'] ? date('d/m/Y', strtotime($topic['planned_week_start'])) : '—') ?></td>
                            <?php if ($canEdit): ?>
                                <td>
                                    <select class="form-select form-select-sm" name="topics[<?= $tid ?>][status]" aria-label="<?= e(__('curriculum.progress_fields.status') . ': ' . $topic['title']) ?>">
                                        <?php foreach ($statuses as $value => $label): ?>
                                            <option value="<?= e($value) ?>"<?= $current === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input class="form-control form-control-sm" type="date" name="topics[<?= $tid ?>][actual_date]" value="<?= e($p['actual_date'] ?? '') ?>" aria-label="<?= e(__('curriculum.progress_fields.actual_date') . ': ' . $topic['title']) ?>"></td>
                                <td><input class="form-control form-control-sm input-narrow" name="topics[<?= $tid ?>][actual_hours]" dir="ltr" inputmode="decimal" maxlength="4" value="<?= e(isset($p['actual_hours']) ? format_hours($p['actual_hours']) : '') ?>" aria-label="<?= e(__('curriculum.progress_fields.actual_hours') . ': ' . $topic['title']) ?>"></td>
                                <td><input class="form-control form-control-sm" name="topics[<?= $tid ?>][note]" maxlength="500" value="<?= e($p['note'] ?? '') ?>" aria-label="<?= e(__('curriculum.progress_fields.note') . ': ' . $topic['title']) ?>"></td>
                            <?php else: ?>
                                <td><?= e($statuses[$current]) ?></td>
                                <td dir="ltr" class="text-end"><?= e(!empty($p['actual_date']) ? date('d/m/Y', strtotime($p['actual_date'])) : '—') ?></td>
                                <td><?= e(format_hours($p['actual_hours'] ?? null)) ?></td>
                                <td><?= e($p['note'] ?? '') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($canEdit): ?>
            <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
        <?php endif; ?>
    </form>
<?php endif; ?>
