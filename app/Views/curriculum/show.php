<?php
use App\Core\View;

View::share('title', $plan['title']);
$id = (int) $plan['id'];
$approved = $plan['status'] === 'approved';
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$byUnit = [];
foreach ($topics as $topic) {
    $byUnit[(int) $topic['unit_id']][] = $topic;
}
$unitOptions = array_column($units, 'title', 'id');
$parentOptions = [];
foreach ($topics as $topic) {
    if ($topic['parent_topic_id'] === null) {
        $parentOptions[(int) $topic['id']] = $topic['unit_title'] . ' › ' . $topic['title'];
    }
}
// Month and week views are groupings of the same topics by their planned week.
$groups = [];
if ($view !== 'outline') {
    foreach ($topics as $topic) {
        $key = $topic['planned_week_start'] === null ? '' : ($view === 'month' ? substr($topic['planned_week_start'], 0, 7) : $topic['planned_week_start']);
        $groups[$key][] = $topic;
    }
    ksort($groups);
}
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($plan['title']) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge <?= $approved ? 'text-bg-success' : 'text-bg-light' ?>"><?= e(__('curriculum.statuses.' . $plan['status'])) ?></span>
            <?= e($meta['subject_name']) ?> · <?= e($meta['level_name']) ?><?= $meta['class_name'] ? ' · ' . e($meta['class_name']) : '' ?>
            · <?= e(__('curriculum.fields.owner_teacher_id')) ?>: <?= e($meta['owner_name'] ?? '—') ?>
            · <?= e(__('curriculum.planned_hours', ['hours' => format_hours($plannedHours)])) ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('curriculum.approve')): ?>
            <form method="post" action="<?= e(url("/curriculum/$id/approve")) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline-primary" type="submit"><?= e(__($approved ? 'curriculum.unapprove' : 'curriculum.approve')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/curriculum/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('curriculum.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/curriculum')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('curriculum.planned_vs_actual')) ?></h2></div>
    <?php if ($assignments === []): ?>
        <div class="card-body text-secondary"><?= e(__('curriculum.no_assignments')) ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden"><?= e(__('curriculum.planned_vs_actual')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('assignments.fields.class_id')) ?></th>
                    <th scope="col"><?= e(__('assignments.fields.teacher_id')) ?></th>
                    <th scope="col"><?= e(__('curriculum.compare.done')) ?></th>
                    <th scope="col"><?= e(__('curriculum.compare.behind')) ?></th>
                    <th scope="col"><?= e(__('curriculum.compare.hours')) ?></th>
                    <th scope="col" class="text-end"><?= e(__('app.actions')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= e($assignment['class_name']) ?><?= $assignment['group_name'] !== '' ? ' (' . e($assignment['group_name']) . ')' : '' ?></th>
                        <td><?= e($assignment['teacher_name']) ?></td>
                        <td class="text-nowrap">
                            <progress max="100" value="<?= (int) $assignment['percent'] ?>" aria-label="<?= e($assignment['class_name']) ?>"></progress>
                            <?= (int) $assignment['done'] ?>/<?= count($topics) ?>
                        </td>
                        <td>
                            <?php if ($assignment['behind'] > 0): ?>
                                <span class="badge text-bg-warning"><?= e(__('curriculum.compare.behind_count', ['count' => $assignment['behind']])) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-success"><?= e(__('curriculum.compare.on_track')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr" class="text-end"><?= e(format_hours($assignment['actual_hours'])) ?> / <?= e(format_hours($plannedHours)) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/curriculum/$id/progress/" . (int) $assignment['id'])) ?>">
                                <?= e(__($assignment['editable'] ? 'curriculum.report_progress' : 'curriculum.view_progress')) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['outline', 'month', 'week'] as $mode): ?>
        <li class="nav-item">
            <a class="nav-link<?= $view === $mode ? ' active' : '' ?>" href="<?= e(url("/curriculum/$id") . ($mode === 'outline' ? '' : '?view=' . $mode)) ?>"
               <?= $view === $mode ? 'aria-current="page"' : '' ?>><?= e(__('curriculum.views.' . $mode)) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($view === 'outline'): ?>
    <?php if ($units === []): ?>
        <div class="card card-body text-secondary mb-3"><?= e(__('curriculum.no_units')) ?></div>
    <?php endif; ?>
    <?php foreach ($units as $unit): ?>
        <?php $uid = (int) $unit['id']; ?>
        <section class="card mb-3" aria-labelledby="unit-<?= $uid ?>">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0" id="unit-<?= $uid ?>"><?= e($unit['title']) ?><?= $unit['term_name'] ? ' <span class="text-secondary fw-normal">· ' . e($unit['term_name']) . '</span>' : '' ?></h2>
                <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url("/curriculum/$id/units/$uid/delete")) ?>" data-confirm="<?= e(__('curriculum.confirm_delete_unit', ['name' => $unit['title']])) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $unit['title']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (empty($byUnit[$uid])): ?>
                <div class="card-body text-secondary"><?= e(__('curriculum.no_topics')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($byUnit[$uid] as $topic): ?>
                        <?php $tid = (int) $topic['id']; ?>
                        <li class="list-group-item<?= $topic['parent_topic_id'] !== null ? ' topic-child' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="<?= $topic['parent_topic_id'] === null ? 'fw-semibold' : '' ?>"><?= e($topic['title']) ?></div>
                                    <div class="small text-secondary">
                                        <?= e(__('curriculum.topic_fields.planned_week_start')) ?>: <span dir="ltr"><?= e($date($topic['planned_week_start'])) ?></span>
                                        · <?= e(__('curriculum.topic_fields.planned_hours')) ?>: <?= e(format_hours($topic['planned_hours'])) ?>
                                    </div>
                                    <?php foreach (['learning_objectives', 'skills', 'teaching_methods', 'assessment_methods'] as $field): ?>
                                        <?php if ($topic[$field]): ?>
                                            <div class="small"><span class="text-secondary"><?= e(__('curriculum.topic_fields.' . $field)) ?>:</span> <?= e($topic[$field]) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($canEdit): ?>
                                    <div class="d-flex gap-1">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/curriculum/$id/topics/$tid/edit")) ?>" aria-label="<?= e(__('app.edit_named', ['name' => $topic['title']])) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                        <form method="post" action="<?= e(url("/curriculum/$id/topics/$tid/delete")) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $topic['title']])) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $topic['title']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($canEdit): ?>
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('curriculum.add_unit')) ?></h2></div>
                    <form class="card-body" method="post" action="<?= e(url("/curriculum/$id/units")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <?= View::partial('partials/input', ['name' => 'title', 'label' => __('curriculum.unit_fields.title'), 'required' => true, 'attrs' => 'maxlength="190"']) ?>
                        <?= View::partial('partials/input', ['name' => 'term_id', 'label' => __('curriculum.unit_fields.term_id'), 'type' => 'select', 'options' => ['' => '—'] + $terms]) ?>
                        <button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button>
                    </form>
                </div>
            </div>
            <?php if ($units !== []): ?>
                <div class="col-12 col-lg-8">
                    <div class="card h-100">
                        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('curriculum.add_topic')) ?></h2></div>
                        <form class="card-body" method="post" action="<?= e(url("/curriculum/$id/topics")) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?= View::partial('curriculum/topic-fields', ['topic' => null, 'units' => $unitOptions, 'parents' => $parentOptions]) ?>
                            <button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php if ($topics === []): ?>
        <div class="card card-body text-secondary"><?= e(__('curriculum.no_topics')) ?></div>
    <?php endif; ?>
    <?php foreach ($groups as $key => $list): ?>
        <?php
        $heading = $key === '' ? __('curriculum.unscheduled')
            : ($view === 'month' ? date('m/Y', strtotime($key . '-01')) : __('curriculum.week_of', ['date' => $date($key)]));
        $hours = array_sum(array_map(static fn ($t) => (float) $t['planned_hours'], $list));
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <h2 class="h6 mb-0"><span dir="auto"><?= e($heading) ?></span></h2>
                <span class="small text-secondary"><?= e(__('curriculum.planned_hours', ['hours' => format_hours($hours)])) ?></span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($list as $topic): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span><?= e($topic['title']) ?> <span class="small text-secondary">· <?= e($topic['unit_title']) ?></span></span>
                        <span class="small text-secondary"><?= e(format_hours($topic['planned_hours'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
