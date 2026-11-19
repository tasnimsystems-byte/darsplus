<?php
use App\Core\View;

/**
 * @var array<int,string> $terms @var int $termId @var array<string,string> $lessons @var string $lessonKey
 * @var bool $isGroup @var bool $showAll @var bool $canShowAll @var array $options @var array $pick
 * @var array<int,array> $components @var array $students @var int $drafts @var bool $weighted @var float|null $classAverage @var bool $canEdit
 */
View::share('title', __('gradebook.title'));
$pct = static fn (?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('gradebook.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('gradebook.subtitle')) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/assessments')) ?>"><i class="bi bi-clipboard-check" aria-hidden="true"></i> <?= e(__('gradebook.to_assessments')) ?></a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3 align-items-center gradebook-filters" method="get" action="<?= e(url('/assessments/gradebook')) ?>">
            <div class="col-6 col-lg-3">
                <div class="ff">
                    <select class="form-select js-autosubmit" id="gb-term" name="term_id">
                        <?php foreach ($terms as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= $id === $termId ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="ff-label" for="gb-term"><?= e(__('gradebook.term')) ?></label>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="ff">
                    <select class="form-select js-autosubmit" id="gb-class" name="class">
                        <option value=""><?= e(__('gradebook.class')) ?></option>
                        <?php $groups = array_filter($options['class'], static fn ($k) => str_starts_with((string) $k, 'g'), ARRAY_FILTER_USE_KEY); ?>
                        <?php foreach (array_diff_key($options['class'], $groups) as $key => $label): ?>
                            <option value="<?= e((string) $key) ?>"<?= (string) $key === $pick['class'] ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                        <?php if ($groups !== []): ?>
                            <optgroup label="<?= e(__('nav.study_groups')) ?>">
                                <?php foreach ($groups as $key => $label): ?>
                                    <option value="<?= e((string) $key) ?>"<?= (string) $key === $pick['class'] ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <label class="ff-label" for="gb-class"><?= e(__('gradebook.class')) ?></label>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="ff">
                    <select class="form-select js-autosubmit" id="gb-teacher" name="teacher_id">
                        <option value=""><?= e(__('gradebook.teacher')) ?></option>
                        <?php foreach ($options['teacher'] as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= (int) $id === $pick['teacher'] ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="ff-label" for="gb-teacher"><?= e(__('gradebook.teacher')) ?></label>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="ff">
                    <select class="form-select js-autosubmit" id="gb-subject" name="subject_id">
                        <option value=""><?= e(__('gradebook.subject')) ?></option>
                        <?php foreach ($options['subject'] as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= (int) $id === $pick['subject'] ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="ff-label" for="gb-subject"><?= e(__('gradebook.subject')) ?></label>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                <noscript><button class="btn btn-outline-primary" type="submit"><?= e(__('timetable.show')) ?></button></noscript>
                <?php if ($canShowAll): ?>
                    <div class="form-check mb-0">
                        <input class="form-check-input js-autosubmit" type="checkbox" name="all" value="1" id="gb-all"<?= $showAll ? ' checked' : '' ?>>
                        <label class="form-check-label small" for="gb-all"><?= e(__('gradebook.show_all')) ?></label>
                    </div>
                <?php endif; ?>
                <?php if ($lessonKey === '' && $pick['class'] !== '' && $pick['teacher'] !== 0 && $pick['subject'] === 0): ?>
                    <span class="small text-secondary"><?= e(__('gradebook.choose_subject')) ?></span>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($terms === []): ?>
            <div class="alert alert-warning mt-3 mb-0" role="alert"><?= e(__('gradebook.no_terms')) ?></div>
        <?php elseif ($lessons === []): ?>
            <p class="text-secondary mt-3 mb-0"><?= e(__('gradebook.no_lessons')) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($homeroom !== null): ?>
    <?= \App\Core\View::partial('assessments/homeroom_sheet', ['rows' => $homeroom, 'canEdit' => $canEdit, 'title' => $lessons[$lessonKey] . ' · ' . $terms[$termId], 'termId' => $termId, 'lessonKey' => $lessonKey, 'showAll' => $showAll, 'isGroup' => $isGroup]) ?>
<?php elseif ($lessonKey !== '' && $termId !== 0): ?>
    <section class="card" aria-labelledby="gb-title">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0" id="gb-title"><?= e($lessons[$lessonKey] . ' · ' . $terms[$termId]) ?></h2>
            <span class="small text-secondary">
                <?= e(__('gradebook.students_count', ['count' => count($students)])) ?>
                <?php if ($classAverage !== null): ?> | <?= e(__('gradebook.class_average', ['avg' => $pct($classAverage)])) ?><?php endif; ?>
            </span>
        </div>
        <div class="card-body pb-0">
            <p class="small text-secondary mb-2">
                <?= e(__($weighted ? 'gradebook.help_weighted' : 'gradebook.help_plain')) ?>
                <?php if (can('structure.update')): ?>
                    <a href="<?= e(url('/school-settings#components')) ?>"><?= e(__('gradebook.settings_link')) ?></a>
                <?php endif; ?>
            </p>
            <?php if ($drafts > 0): ?>
                <div class="alert alert-warning py-2 small" role="note"><?= e(__('gradebook.drafts', ['count' => $drafts])) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($students === []): ?>
            <div class="card-body text-secondary"><?= e(__('classes.no_students')) ?></div>
        <?php else: ?>
            <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/assessments/gradebook')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                <input type="hidden" name="lesson" value="<?= e($lessonKey) ?>">
                <?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?>
                <p class="small text-secondary px-3 mb-1"><?= e(__('gradebook.edit_help')) ?></p>
                <p class="small text-secondary px-3 mb-2"><?= e(__('gradebook.final_help')) ?></p>
            <?php endif; ?>
            <div class="table-responsive table-scroll">
                <table class="table table-sm table-hover align-middle mb-0 gradebook-table table-sticky">
                    <caption class="visually-hidden"><?= e(__('gradebook.title')) ?></caption>
                    <thead><tr>
                        <th scope="col">#</th>
                        <th scope="col"><?= e(__('gradebook.student')) ?></th>
                        <?php if ($isGroup): ?><th scope="col"><?= e(__('gradebook.class')) ?></th><?php endif; ?>
                        <?php foreach ($components as $component): ?>
                            <th scope="col" class="text-center">
                                <?= e($component['name']) ?>
                                <?php if ($component['weight'] !== null): ?>
                                    <span class="d-block small fw-normal text-secondary" dir="ltr"><?= e($pct($component['weight'])) ?>%</span>
                                <?php endif; ?>
                                <span class="d-block small fw-normal text-secondary"><?= e(__('gradebook.assessments_count', ['count' => $component['count']])) ?></span>
                            </th>
                        <?php endforeach; ?>
                        <th scope="col" class="text-center final-col"><?= e(__('gradebook.final')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($students as $i => $student): ?>
                        <tr>
                            <td class="text-secondary"><?= $i + 1 ?></td>
                            <th scope="row" class="fw-normal"><?= e($student['last_name'] . ' ' . $student['first_name']) ?></th>
                            <?php if ($isGroup): ?><td class="small"><span class="badge text-bg-light border"><?= e($student['class_name']) ?></span></td><?php endif; ?>
                            <?php foreach ($components as $typeId => $component): ?>
                                <?php
                                $typedValue = $student['typed'][$typeId] ?? null;
                                $computedValue = $student['computed'][$typeId] ?? null;
                                ?>
                                <td class="text-center" dir="ltr">
                                    <?php if ($canEdit): ?>
                                        <input class="form-control form-control-sm text-center grade-cell<?= $typedValue !== null ? ' grade-typed' : '' ?>" type="text" inputmode="decimal"
                                               maxlength="6" pattern="\d{1,3}([.,]\d{1,2})?" name="grades[<?= (int) $student['id'] ?>][<?= (int) $typeId ?>]"
                                               value="<?= e($typedValue === null ? '' : $pct($typedValue)) ?>" placeholder="<?= e($computedValue === null ? '' : $pct($computedValue)) ?>"
                                               aria-label="<?= e(__('gradebook.cell_label', ['student' => $student['last_name'] . ' ' . $student['first_name'], 'component' => $component['name']])) ?>"
                                               <?php if ($typedValue !== null && $computedValue !== null): ?>title="<?= e(__('gradebook.computed_was', ['value' => $pct($computedValue)])) ?>"<?php endif; ?>>
                                    <?php else: ?>
                                        <?= e($pct($student['components'][$typeId] ?? null)) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center final-col">
                                <?php if ($canEdit): ?>
                                    <input class="form-control form-control-sm text-center grade-cell final-cell<?= $student['typedFinal'] !== null ? ' grade-typed' : '' ?><?= $student['final'] !== null && $student['final'] < 55 ? ' text-danger' : '' ?>"
                                           type="text" inputmode="numeric" maxlength="3" pattern="\d{1,3}" dir="ltr"
                                           name="grades[<?= (int) $student['id'] ?>][final]"
                                           value="<?= $student['typedFinal'] !== null ? (int) $student['typedFinal'] : '' ?>"
                                           placeholder="<?= $student['computedFinal'] !== null ? (int) $student['computedFinal'] : '' ?>"
                                           aria-label="<?= e(__('gradebook.final_label', ['student' => $student['last_name'] . ' ' . $student['first_name']])) ?>"
                                           title="<?= e($student['typedFinal'] !== null
                                               ? __('gradebook.final_typed', ['value' => $student['computedFinal'] ?? '—'])
                                               : __('gradebook.final_computed', ['value' => $pct($student['average'])])) ?>">
                                <?php elseif ($student['final'] === null): ?>
                                    <span class="text-secondary">—</span>
                                <?php else: ?>
                                    <strong class="<?= $student['final'] < 55 ? 'text-danger' : '' ?>" title="<?= e($pct($student['average'])) ?>"><?= (int) $student['final'] ?></strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                <?php if ($canEdit): ?>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> <?= e(__('gradebook.save')) ?></button>
                <?php endif; ?>
                <span class="small text-secondary"><?= e(__('gradebook.rounding')) ?></span>
            </div>
            <?php if ($canEdit): ?>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
