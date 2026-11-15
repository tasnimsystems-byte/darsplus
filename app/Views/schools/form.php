<?php
use App\Core\App;
use App\Core\Lang;
use App\Core\View;

$isEdit = $school !== null;
View::share('title', __($isEdit ? 'schools.edit' : 'schools.add'));
$isCurrent = $isEdit && (int) $school['id'] === App::auth()->schoolId();
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__($isEdit ? 'schools.edit' : 'schools.add')) ?></h1>
</div>

<div class="card form-card">
    <div class="card-body">
        <form method="post" action="<?= e(url($isEdit ? '/schools/' . (int) $school['id'] : '/schools')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= View::partial('partials/input', [
                'name' => 'name', 'label' => __('schools.fields.name'), 'required' => true,
                'value' => $school['name'] ?? '', 'attrs' => 'maxlength="150"',
            ]) ?>
            <?= View::partial('partials/input', [
                'name' => 'code', 'label' => __('schools.fields.code'), 'required' => true,
                'value' => $school['code'] ?? '', 'help' => __('schools.code_help'),
                'attrs' => 'maxlength="30" dir="ltr" autocapitalize="characters" spellcheck="false"',
            ]) ?>
            <?= View::partial('partials/input', [
                'name' => 'default_locale', 'label' => __('schools.fields.default_locale'), 'type' => 'select',
                'required' => true, 'value' => $school['default_locale'] ?? 'he', 'options' => Lang::SUPPORTED,
            ]) ?>
            <?php if ($isEdit): ?>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                           <?= $school['is_active'] ? 'checked' : '' ?><?= $isCurrent ? ' disabled' : '' ?>>
                    <label class="form-check-label" for="is_active"><?= e(__('schools.fields.is_active')) ?></label>
                    <?php if ($isCurrent): ?><div class="form-text"><?= e(__('schools.current_locked')) ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/schools')) ?>"><?= e(__('app.cancel')) ?></a>
            </div>
        </form>
    </div>
</div>
