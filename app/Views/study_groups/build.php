<?php
use App\Core\View;

/**
 * @var array<int,string> $teachers @var int $teacherId @var array<int,string> $subjects @var int $subjectId
 * @var array $lessons @var array<int,array> $students @var string $suggestedName
 */
View::share('title', __('study_groups.add'));
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('study_groups.add')) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/study-groups')) ?>"><?= e(__('app.back')) ?></a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3 align-items-center" method="get" action="<?= e(url('/study-groups/create')) ?>">
            <div class="col-12 col-md-5">
                <div class="ff">
                    <select class="form-select js-autosubmit" id="sg-teacher" name="teacher_id">
                        <option value=""><?= e(__('study_groups.choose_teacher')) ?></option>
                        <?php foreach ($teachers as $tid => $tname): ?>
                            <option value="<?= (int) $tid ?>"<?= $tid === $teacherId ? ' selected' : '' ?>><?= e($tname) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="ff-label" for="sg-teacher">1. <?= e(__('study_groups.step_teacher')) ?></label>
                </div>
            </div>
            <?php if ($teacherId !== 0): ?>
                <div class="col-12 col-md-5">
                    <div class="ff">
                        <select class="form-select js-autosubmit" id="sg-subject" name="subject_id">
                            <option value=""><?= e(__('study_groups.choose_subject')) ?></option>
                            <?php foreach ($subjects as $sid => $sname): ?>
                                <option value="<?= (int) $sid ?>"<?= $sid === $subjectId ? ' selected' : '' ?>><?= e($sname) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="ff-label" for="sg-subject">2. <?= e(__('study_groups.step_subject')) ?></label>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12 col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit"><?= e(__('timetable.show')) ?></button>
            </div>
        </form>
        <?php if ($teachers === []): ?>
            <p class="text-secondary mt-3 mb-0"><?= e(__('study_groups.no_teachers')) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($subjectId !== 0): ?>
    <form method="post" action="<?= e(url('/study-groups/create')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="teacher_id" value="<?= (int) $teacherId ?>">
        <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">

        <div class="card mb-4">
            <div class="card-header"><h2 class="h6 mb-0">3. <?= e(__('study_groups.step_classes')) ?></h2></div>
            <div class="card-body">
                <p class="small text-secondary"><?= e(__('study_groups.classes_help')) ?></p>
                <?php if ($lessons === []): ?>
                    <p class="text-secondary mb-0"><?= e(__('study_groups.no_lessons')) ?></p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($lessons as $lesson): ?>
                        <?php $lid = (int) $lesson['id']; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="assignment_ids[]" value="<?= $lid ?>" id="sg-l-<?= $lid ?>"
                                   data-reveal="sg-students-<?= $lid ?>">
                            <label class="form-check-label fw-semibold" for="sg-l-<?= $lid ?>">
                                <?= e($lesson['class_name'] . ($lesson['group_name'] !== '' ? ' (' . $lesson['group_name'] . ')' : '')) ?>
                                <?php if ($lesson['other_group'] !== null): ?>
                                    <span class="badge text-bg-warning"><?= e(__('study_groups.in_other', ['name' => $lesson['other_group']])) ?></span>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($lessons !== []): ?>
            <h2 class="h5">4. <?= e(__('study_groups.step_students')) ?></h2>
            <div class="row g-3 mb-4">
                <?php foreach ($lessons as $lesson): ?>
                    <?php $lid = (int) $lesson['id']; $list = $students[(int) $lesson['class_id']] ?? []; ?>
                    <div class="col-12 col-md-6 col-xl-4" id="sg-students-<?= $lid ?>" data-revealed-by="sg-l-<?= $lid ?>">
                        <fieldset class="card h-100">
                            <legend class="card-header h6 mb-0 w-100">
                                <?= e(__('study_groups.students_of', ['class' => $lesson['class_name'] . ($lesson['group_name'] !== '' ? ' (' . $lesson['group_name'] . ')' : '')])) ?>
                                <span class="badge text-bg-light border"><?= count($list) ?></span>
                            </legend>
                            <div class="card-body">
                                <?php if ($list === []): ?>
                                    <p class="text-secondary mb-0"><?= e(__('classes.no_students')) ?></p>
                                <?php else: ?>
                                    <div class="form-check border-bottom pb-1 mb-2">
                                        <input class="form-check-input" type="checkbox" id="sg-all-<?= $lid ?>" data-check-all="sg-students-<?= $lid ?>">
                                        <label class="form-check-label small" for="sg-all-<?= $lid ?>"><?= e(__('study_groups.select_all')) ?></label>
                                    </div>
                                    <?php foreach ($list as $student): ?>
                                        <?php $sid = (int) $student['id']; ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="students[<?= $lid ?>][]" value="<?= $sid ?>" id="sg-s-<?= $lid ?>-<?= $sid ?>">
                                            <label class="form-check-label" for="sg-s-<?= $lid ?>-<?= $sid ?>"><?= e($student['last_name'] . ' ' . $student['first_name']) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </fieldset>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card mb-4">
                <div class="card-body row g-3 align-items-end">
                    <div class="col-12 col-md-8">
                        <div class="ff">
                            <input class="form-control" id="sg-name" name="name" maxlength="80" required value="<?= e($suggestedName) ?>" placeholder="<?= e(__('study_groups.fields.name')) ?>">
                            <label class="ff-label" for="sg-name">5. <?= e(__('study_groups.fields.name')) ?></label>
                        </div>
                        <div class="form-text"><?= e(__('study_groups.name_help')) ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-people" aria-hidden="true"></i> <?= e(__('study_groups.create_group')) ?></button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
