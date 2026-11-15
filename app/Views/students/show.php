<?php
use App\Core\View;

$name = $student['last_name'] . ' ' . $student['first_name'];
View::share('title', $name);
$id = (int) $student['id'];
$statusClass = ['active' => 'text-bg-success', 'left' => 'text-bg-secondary', 'graduated' => 'text-bg-info'][$student['status']] ?? 'text-bg-light';
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($name) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge <?= $statusClass ?>"><?= e(__('students.statuses.' . $student['status'])) ?></span>
            <?= e(__('students.fields.student_code')) ?>: <span dir="ltr"><?= e($student['student_code']) ?></span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('students.update')): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/students/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('students.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/students')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('students.details')) ?></h2></div>
            <dl class="card-body mb-0">
                <dt><?= e(__('students.fields.gender')) ?></dt>
                <dd><?= e($student['gender'] ? __('students.genders.' . $student['gender']) : '—') ?></dd>
                <dt><?= e(__('students.fields.birth_date')) ?></dt>
                <dd class="mb-0" dir="ltr"><?= e($student['birth_date'] ? date('d/m/Y', strtotime($student['birth_date'])) : '—') ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('students.enrollment_history')) ?></h2></div>
            <?php if ($enrollments === []): ?>
                <div class="card-body text-secondary"><?= e(__('students.no_enrollments')) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden"><?= e(__('students.enrollment_history')) ?></caption>
                        <thead><tr>
                            <th scope="col"><?= e(__('students.history.year')) ?></th>
                            <th scope="col"><?= e(__('students.fields.class_id')) ?></th>
                            <th scope="col"><?= e(__('students.history.from')) ?></th>
                            <th scope="col"><?= e(__('students.history.until')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($enrollments as $enrollment): ?>
                            <tr>
                                <th scope="row" class="fw-normal"><?= e($enrollment['year_name']) ?></th>
                                <td><?= e($enrollment['class_name']) ?> <span class="text-secondary small">(<?= e($enrollment['level_name']) ?>)</span></td>
                                <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($enrollment['enrolled_on']))) ?></td>
                                <td dir="ltr" class="text-end"><?= e($enrollment['left_on'] ? date('d/m/Y', strtotime($enrollment['left_on'])) : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (can('assessments.view') || $attendance !== null): ?>
    <h2 class="h5 mt-4 mb-3"><?= e(__('students.pedagogical', ['year' => $year['name'] ?? '—'])) ?></h2>
    <?php if ($partialScope): ?>
        <div class="alert alert-secondary" role="note"><?= e(__('students.partial_scope')) ?></div>
    <?php endif; ?>
    <div class="row g-4">
        <?php if ($attendance !== null): ?>
            <div class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="h6 mb-0"><?= e(__('students.attendance')) ?></h3></div>
                    <dl class="card-body mb-0">
                        <?php foreach (['absent', 'justified', 'late'] as $status): ?>
                            <dt><?= e(__('attendance.statuses.' . $status)) ?></dt><dd><?= (int) $attendance[$status] ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        <?php endif; ?>
        <?php if (can('assessments.view')): ?>
            <div class="col-12 <?= $attendance !== null ? 'col-lg-8' : '' ?>">
                <div class="card h-100">
                    <div class="card-header"><h3 class="h6 mb-0"><?= e(__('students.averages')) ?></h3></div>
                    <?php if ($grades === []): ?>
                        <div class="card-body text-secondary"><?= e(__('students.no_grades')) ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden"><?= e(__('students.averages')) ?></caption>
                                <thead><tr>
                                    <th scope="col"><?= e(__('assignments.fields.subject_id')) ?></th>
                                    <?php foreach ($termNames as $termName): ?><th scope="col"><?= e($termName) ?></th><?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($grades as $subjectId => $terms): ?>
                                    <tr>
                                        <th scope="row" class="fw-normal"><?= e($subjectNames[$subjectId] ?? '?') ?></th>
                                        <?php foreach (array_keys($termNames) as $termId): ?><td><?= e(format_hours($terms[$termId] ?? null)) ?></td><?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($results !== []): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="h6 mb-0"><?= e(__('students.results')) ?></h3></div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden"><?= e(__('students.results')) ?></caption>
                                <?php /* Each result carries its subject, term and assessment type. */ ?>
                                <thead><tr>
                                    <th scope="col"><?= e(__('assessments.fields.title')) ?></th>
                                    <th scope="col"><?= e(__('assignments.fields.subject_id')) ?></th>
                                    <th scope="col"><?= e(__('assessments.fields.term_id')) ?></th>
                                    <th scope="col"><?= e(__('assessments.fields.type_id')) ?></th>
                                    <th scope="col"><?= e(__('assessments.fields.assessment_date')) ?></th>
                                    <th scope="col"><?= e(__('students.result')) ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr>
                                        <th scope="row" class="fw-normal"><?= e($result['title']) ?></th>
                                        <td><?= e($result['subject_name']) ?></td>
                                        <td><?= e($result['term_name']) ?></td>
                                        <td><?= e($result['type_name']) ?></td>
                                        <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($result['assessment_date']))) ?></td>
                                        <td dir="auto">
                                            <?php if ($result['result_status'] !== 'graded'): ?>
                                                <?= e(__('assessments.result_statuses.' . $result['result_status'])) ?>
                                            <?php elseif ($result['score'] !== null): ?>
                                                <span dir="ltr"><?= e(format_hours($result['score'])) ?> / <?= e(format_hours($result['max_score'])) ?></span>
                                            <?php else: ?>
                                                <?= e($result['verbal_evaluation'] ?? '—') ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($guardians !== null || $interventions !== null || $contacts !== null): ?>
    <h2 class="h5 mt-4 mb-3"><?= e(__('students.support')) ?></h2>
    <div class="row g-4">
        <?php if ($interventions !== null): ?>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="h6 mb-0"><?= e(__('interventions.title')) ?></h3></div>
                    <?php if ($interventions === []): ?>
                        <div class="card-body text-secondary"><?= e(__('app.none')) ?></div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($interventions as $item): ?>
                                <li class="list-group-item d-flex justify-content-between gap-2">
                                    <span>
                                        <a href="<?= e(url('/interventions/' . (int) $item['id'])) ?>"><?= e($item['difficulty_area']) ?></a>
                                        <span class="small text-secondary">· <?= e($item['owner_name'] ?? '—') ?></span>
                                    </span>
                                    <span class="badge <?= $item['status'] === 'active' ? 'text-bg-primary' : 'text-bg-light' ?>"><?= e(__('interventions.statuses.' . $item['status'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($contacts !== null): ?>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="h6 mb-0"><?= e(__('parent_contacts.title')) ?></h3></div>
                    <?php if ($contacts === []): ?>
                        <div class="card-body text-secondary"><?= e(__('app.none')) ?></div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($contacts as $item): ?>
                                <li class="list-group-item d-flex justify-content-between gap-2">
                                    <span>
                                        <a href="<?= e(url('/parent-contacts/' . (int) $item['id'])) ?>"><span dir="ltr"><?= e(date('d/m/Y', strtotime($item['contacted_on']))) ?></span> · <?= e(__('parent_contacts.types.' . $item['contact_type'])) ?></a>
                                        <span class="small text-secondary">· <?= e($item['staff_name'] ?? '—') ?></span>
                                    </span>
                                    <?php if ($item['follow_up_status'] === 'open'): ?><span class="badge text-bg-warning"><?= e(__('parent_contacts.follow_up.open')) ?></span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($guardians !== null): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="h6 mb-0"><?= e(__('parent_contacts.guardians')) ?></h3></div>
                    <?php if ($guardians === []): ?>
                        <div class="card-body text-secondary"><?= e(__('app.none')) ?></div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($guardians as $guardian): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                    <span>
                                        <?= e($guardian['full_name']) ?><?= $guardian['relation'] ? ' <span class="text-secondary">(' . e($guardian['relation']) . ')</span>' : '' ?>
                                        <?php if ($guardian['is_primary']): ?><span class="badge text-bg-light"><?= e(__('parent_contacts.guardian_fields.is_primary')) ?></span><?php endif; ?>
                                        <span class="small text-secondary" dir="ltr"><?= e(trim(($guardian['phone'] ?? '') . ' ' . ($guardian['email'] ?? ''))) ?></span>
                                    </span>
                                    <?php if (can('parent_contacts.update')): ?>
                                        <form method="post" action="<?= e(url("/students/$id/guardians/" . (int) $guardian['id'] . '/delete')) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $guardian['full_name']])) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $guardian['full_name']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (can('parent_contacts.update')): ?>
                        <div class="card-body border-top">
                            <h4 class="h6"><?= e(__('parent_contacts.add_guardian')) ?></h4>
                            <form class="row g-2" method="post" action="<?= e(url("/students/$id/guardians")) ?>" novalidate>
                                <?= csrf_field() ?>
                                <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'full_name', 'label' => __('parent_contacts.guardian_fields.full_name'), 'required' => true, 'attrs' => 'maxlength="150"']) ?></div>
                                <div class="col-6 col-md-2"><?= View::partial('partials/input', ['name' => 'relation', 'label' => __('parent_contacts.guardian_fields.relation'), 'attrs' => 'maxlength="40"']) ?></div>
                                <div class="col-6 col-md-2"><?= View::partial('partials/input', ['name' => 'phone', 'label' => __('parent_contacts.guardian_fields.phone'), 'attrs' => 'maxlength="30" dir="ltr" inputmode="tel"']) ?></div>
                                <div class="col-12 col-md-3"><?= View::partial('partials/input', ['name' => 'email', 'label' => __('parent_contacts.guardian_fields.email'), 'type' => 'email', 'attrs' => 'maxlength="190" dir="ltr"']) ?></div>
                                <div class="col-12 d-flex align-items-center gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary" value="1">
                                        <label class="form-check-label" for="is_primary"><?= e(__('parent_contacts.guardian_fields.is_primary')) ?></label>
                                    </div>
                                    <button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="text-secondary small mt-4 mb-0"><?= e(__('students.card_note')) ?></p>
