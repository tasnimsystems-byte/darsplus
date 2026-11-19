<?php
/** @var array $student @var array $children @var string $heading @var string $icon @var string $back path of this page, for the child switch */
?>
<div class="page-header portal-header">
    <div class="d-flex align-items-center gap-3">
        <span class="portal-avatar" aria-hidden="true"><?= e(mb_substr($student['first_name'], 0, 1)) ?></span>
        <div>
            <h1 class="h3 mb-1"><?= e($heading) ?></h1>
            <p class="text-secondary mb-0">
                <?= e($student['first_name'] . ' ' . $student['last_name']) ?>
                <?php if (!empty($student['class_name'])): ?> · <?= e($student['class_name']) ?><?php endif; ?>
            </p>
        </div>
    </div>
    <?php if ($children !== []): ?>
        <form method="post" action="<?= e(url('/me/child')) ?>" class="ff portal-child">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <select class="form-select js-autosubmit" id="portal-child" name="student_id">
                <?php foreach ($children as $id => $child): ?>
                    <option value="<?= (int) $id ?>"<?= (int) $id === (int) $student['id'] ? ' selected' : '' ?>><?= e($child['first_name'] . ' ' . $child['last_name'] . (!empty($child['class_name']) ? ' – ' . $child['class_name'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="ff-label" for="portal-child"><?= e(__('portal.child')) ?></label>
        </form>
    <?php endif; ?>
</div>
