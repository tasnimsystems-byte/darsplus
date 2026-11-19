<?php
use App\Controllers\AssessmentController;

/** @var array $rows @var bool $canEdit @var string $title @var int $termId @var string $lessonKey @var bool $showAll @var bool $isGroup */
?>
<section class="card" aria-labelledby="hr-title">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0" id="hr-title"><?= e($title) ?></h2>
        <span class="small text-secondary"><?= e(__('gradebook.students_count', ['count' => count($rows)])) ?></span>
    </div>
    <div class="card-body pb-0">
        <div class="alert alert-info py-2 small" role="note"><?= e(__('gradebook.homeroom_help')) ?></div>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state"><i class="bi bi-people" aria-hidden="true"></i><p class="mb-0"><?= e(__('classes.no_students')) ?></p></div>
    <?php else: ?>
        <?php if ($canEdit): ?>
        <form method="post" action="<?= e(url('/assessments/gradebook')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
            <input type="hidden" name="lesson" value="<?= e($lessonKey) ?>">
            <?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?>
        <?php endif; ?>
        <div class="table-responsive table-scroll">
            <table class="table table-sm align-middle mb-0 homeroom-table table-sticky">
                <caption class="visually-hidden"><?= e(__('gradebook.homeroom_title')) ?></caption>
                <thead><tr>
                    <th scope="col">#</th>
                    <th scope="col"><?= e(__('gradebook.student')) ?></th>
                    <?php if ($isGroup): ?><th scope="col"><?= e(__('gradebook.class')) ?></th><?php endif; ?>
                    <th scope="col" class="text-center"><?= e(__('gradebook.absences')) ?></th>
                    <th scope="col" class="text-center"><?= e(__('gradebook.lates')) ?></th>
                    <th scope="col"><?= e(__('gradebook.remark')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <?php $sid = (int) $row['id']; $name = $row['last_name'] . ' ' . $row['first_name']; ?>
                    <tr>
                        <td class="text-secondary"><?= $i + 1 ?></td>
                        <th scope="row" class="fw-normal text-nowrap"><?= e($name) ?></th>
                        <?php if ($isGroup): ?><td class="small"><span class="badge text-bg-light border"><?= e($row['class_name']) ?></span></td><?php endif; ?>
                        <?php foreach (['absences' => 'counted_absences', 'lates' => 'counted_lates'] as $field => $counted): ?>
                            <td class="text-center">
                                <?php if ($canEdit): ?>
                                    <input class="form-control form-control-sm text-center grade-cell count-cell<?= $row[$field] !== null ? ' grade-typed' : '' ?>"
                                           type="text" inputmode="numeric" maxlength="3" pattern="\d{1,3}" dir="ltr"
                                           name="homeroom[<?= $sid ?>][<?= $field ?>]" value="<?= $row[$field] !== null ? (int) $row[$field] : '' ?>"
                                           placeholder="<?= (int) $row[$counted] ?>"
                                           aria-label="<?= e(__('gradebook.' . $field) . ' – ' . $name) ?>"
                                           title="<?= e(__('gradebook.counted_from_lessons', ['count' => (int) $row[$counted]])) ?>">
                                <?php else: ?>
                                    <?= (int) ($row[$field] ?? $row[$counted]) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="remark-cell">
                            <?php if ($canEdit): ?>
                                <div class="d-grid gap-1">
                                    <label class="visually-hidden" for="hr-p-<?= $sid ?>"><?= e(__('gradebook.remark_list') . ' – ' . $name) ?></label>
                                    <select class="form-select form-select-sm" id="hr-p-<?= $sid ?>" name="homeroom[<?= $sid ?>][preset]" data-fill-target="hr-r-<?= $sid ?>">
                                        <option value=""><?= e(__('gradebook.remark_list')) ?></option>
                                        <?php foreach (AssessmentController::REMARK_PRESETS as $group => $count): ?>
                                            <optgroup label="<?= e(__('gradebook.preset_groups.' . $group)) ?>">
                                                <?php for ($n = 1; $n <= $count; $n++): ?>
                                                    <option value="<?= e("$group.$n") ?>"><?= e(__("gradebook.presets.$group.$n")) ?></option>
                                                <?php endfor; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="visually-hidden" for="hr-r-<?= $sid ?>"><?= e(__('gradebook.remark') . ' – ' . $name) ?></label>
                                    <textarea class="form-control form-control-sm" id="hr-r-<?= $sid ?>" name="homeroom[<?= $sid ?>][remark]" rows="2" maxlength="600"
                                              placeholder="<?= e(__('gradebook.remark_free')) ?>"><?= e($row['remark']) ?></textarea>
                                </div>
                            <?php else: ?>
                                <span class="text-prewrap"><?= e($row['remark'] !== '' ? $row['remark'] : '—') ?></span>
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
            <span class="small text-secondary"><?= e(__('gradebook.homeroom_footer')) ?></span>
        </div>
        <?php if ($canEdit): ?>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>