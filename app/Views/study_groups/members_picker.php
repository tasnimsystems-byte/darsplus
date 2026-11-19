<?php
use App\Core\View;

/** @var int $groupId @var array<int, array{name: string, students: array}> $classes */
$old = View::shared('old');
$oldChosen = isset($old['student_ids']) && is_array($old['student_ids']) ? array_flip(array_map('intval', $old['student_ids'])) : null;
?>
<fieldset class="mb-4">
    <legend class="h6"><?= e(__('study_groups.members')) ?></legend>
    <?php if ($classes === []): ?>
        <p class="small text-secondary">
            <?= e(__('study_groups.edit_no_classes')) ?>
            <a href="<?= e(url('/study-groups/' . $groupId)) ?>"><?= e(__('study_groups.open_group')) ?></a>
        </p>
    <?php else: ?>
        <p class="small text-secondary"><?= e(__('study_groups.edit_members_help')) ?></p>
        <?php foreach ($classes as $classId => $class): ?>
            <?php $members = count(array_filter($class['students'], static fn ($s) => (bool) $s['member'])); ?>
            <div class="border rounded p-2 mb-3" id="mp-class-<?= (int) $classId ?>">
                <input type="hidden" name="member_classes[]" value="<?= (int) $classId ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-1 mb-2">
                    <strong><?= e($class['name']) ?> <span class="badge text-bg-light border"><?= e(__('study_groups.members_of', ['count' => $members, 'total' => count($class['students'])])) ?></span></strong>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="mp-all-<?= (int) $classId ?>" data-check-all="mp-class-<?= (int) $classId ?>">
                        <label class="form-check-label small" for="mp-all-<?= (int) $classId ?>"><?= e(__('study_groups.select_all')) ?></label>
                    </div>
                </div>
                <div class="row row-cols-1 row-cols-sm-2 g-1">
                    <?php foreach ($class['students'] as $student): ?>
                        <?php
                        $sid = (int) $student['id'];
                        $checked = $oldChosen !== null ? isset($oldChosen[$sid]) : (bool) $student['member'];
                        ?>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="student_ids[]" value="<?= $sid ?>" id="mp-<?= (int) $classId ?>-<?= $sid ?>"<?= $checked ? ' checked' : '' ?>>
                                <label class="form-check-label" for="mp-<?= (int) $classId ?>-<?= $sid ?>"><?= e($student['last_name'] . ' ' . $student['first_name']) ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</fieldset>