<?php
use App\Core\View;

View::share('title', $group['name']);
$id = (int) $group['id'];
$byClass = [];
foreach ($members as $member) {
    $byClass[$member['class_name'] ?? __('study_groups.no_class')][] = $member;
}
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('study_groups.group_named', ['name' => $group['name']])) ?></h1>
        <p class="text-secondary mb-0">
            <?= e($subjectName ?? __('study_groups.any_subject')) ?>
            | <?= e(__('study_groups.members_total', ['count' => count($members)])) ?>
            <?php if (($group['notes'] ?? '') !== ''): ?> | <?= e($group['notes']) ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/study-groups/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('study_groups.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/study-groups')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="alert alert-info" role="note"><?= e(__('study_groups.how_it_works')) ?></div>

<?php if ($uncovered !== []): ?>
    <div class="alert alert-warning" role="alert">
        <?= e(__('study_groups.uncovered', ['count' => count($uncovered)])) ?>
        <?= e(implode('، ', array_map(static fn ($m) => $m['last_name'] . ' ' . $m['first_name'] . ' (' . ($m['class_name'] ?? '—') . ')', array_slice($uncovered, 0, 10)))) ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Members -->
    <div class="col-12 col-xl-6">
        <div class="card mb-4">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('study_groups.members')) ?> (<?= count($members) ?>)</h2></div>
            <?php if ($members === []): ?>
                <div class="card-body text-secondary"><?= e(__('study_groups.no_members')) ?></div>
            <?php else: ?>
                <div class="card-body">
                    <?php foreach ($byClass as $className => $list): ?>
                        <h3 class="h6 mt-2"><?= e($className) ?> <span class="badge text-bg-light border"><?= count($list) ?></span></h3>
                        <p class="small mb-2"><?= e(implode('، ', array_map(static fn ($m) => $m['last_name'] . ' ' . $m['first_name'], $list))) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canEdit && $classes !== []): ?>
            <div class="card">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('study_groups.choose_students')) ?></h2></div>
                <div class="card-body">
                    <form class="d-flex gap-2 align-items-center mb-3" method="get" action="<?= e(url("/study-groups/$id")) ?>">
                        <div class="ff flex-grow-1">
                            <select class="form-select js-autosubmit" id="pick-class" name="class_id">
                                <?php foreach ($classes as $cid => $cname): ?>
                                    <option value="<?= (int) $cid ?>"<?= $cid === $classId ? ' selected' : '' ?>><?= e($cname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="ff-label" for="pick-class"><?= e(__('study_groups.from_class')) ?></label>
                        </div>
                        <button class="btn btn-outline-primary" type="submit"><?= e(__('timetable.show')) ?></button>
                    </form>

                    <form method="post" action="<?= e(url("/study-groups/$id/members")) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                        <fieldset>
                            <legend class="h6"><?= e(__('study_groups.students_of', ['class' => $classes[$classId] ?? ''])) ?></legend>
                            <?php if ($classStudents === []): ?>
                                <p class="text-secondary"><?= e(__('classes.no_students')) ?></p>
                            <?php endif; ?>
                            <div class="row row-cols-1 row-cols-sm-2 g-1">
                                <?php foreach ($classStudents as $student): ?>
                                    <?php $sid = (int) $student['id']; ?>
                                    <div class="col">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="student_ids[]" value="<?= $sid ?>" id="m-<?= $sid ?>"<?= isset($memberIds[$sid]) ? ' checked' : '' ?>>
                                            <label class="form-check-label" for="m-<?= $sid ?>"><?= e($student['last_name'] . ' ' . $student['first_name']) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <?php if ($classStudents !== []): ?>
                            <button class="btn btn-primary mt-3" type="submit"><?= e(__('study_groups.save_members', ['class' => $classes[$classId] ?? ''])) ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Linked lessons -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0"><?= e(__('study_groups.lessons')) ?></h2>
                <?php if ($group['subject_id'] !== null): ?>
                    <a class="small" href="<?= e(url("/study-groups/$id?class_id=$classId" . ($allSubjects ? '' : '&subjects=all'))) ?>">
                        <?= e(__($allSubjects ? 'study_groups.only_subject' : 'study_groups.all_subjects')) ?>
                    </a>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url("/study-groups/$id/lessons")) ?>">
                <?= csrf_field() ?>
                <div class="card-body">
                    <p class="small text-secondary"><?= e(__('study_groups.lessons_help')) ?></p>
                    <?php if ($assignments === []): ?>
                        <p class="text-secondary mb-0"><?= e(__('study_groups.no_lessons')) ?></p>
                    <?php endif; ?>
                    <?php foreach ($assignments as $a): ?>
                        <?php $aid = (int) $a['id']; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="assignment_ids[]" value="<?= $aid ?>" id="a-<?= $aid ?>"
                                <?= (int) $a['study_group_id'] === $id ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
                            <label class="form-check-label" for="a-<?= $aid ?>">
                                <strong><?= e($a['subject_name']) ?></strong> – <?= e($a['class_name']) ?><?= $a['group_name'] !== '' ? ' (' . e($a['group_name']) . ')' : '' ?>
                                – <?= e($a['teacher_name']) ?>
                                <?php if ($a['other_group'] !== null): ?>
                                    <span class="badge text-bg-warning"><?= e(__('study_groups.in_other', ['name' => $a['other_group']])) ?></span>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($canEdit && $assignments !== []): ?>
                    <div class="card-footer"><button class="btn btn-primary" type="submit"><?= e(__('study_groups.save_lessons')) ?></button></div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
