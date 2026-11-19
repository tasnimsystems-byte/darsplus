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
            <?php if (!$isEdit): ?>
                <fieldset class="setup-part">
                    <legend class="h6"><i class="bi bi-calendar-range" aria-hidden="true"></i> <?= e(__('schools.setup.year_title')) ?></legend>
                    <p class="small text-secondary"><?= e(__('schools.setup.year_help')) ?></p>
                    <div class="row g-3">
                        <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'year_name', 'label' => __('schools.setup.year_name'), 'attrs' => 'maxlength="30" dir="ltr" placeholder="2026-2027"']) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'year_start', 'label' => __('schools.setup.year_start'), 'type' => 'date']) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'year_end', 'label' => __('schools.setup.year_end'), 'type' => 'date']) ?></div>
                        <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'term_count', 'label' => __('school_settings.term_count'), 'type' => 'select', 'value' => '2',
                            'options' => array_combine(['1', '2', '3', '4'], array_map(static fn ($n) => __('school_settings.term_count_option.' . $n), [1, 2, 3, 4]))]) ?></div>
                    </div>
                </fieldset>
                <fieldset class="setup-part">
                    <legend class="h6"><i class="bi bi-layers" aria-hidden="true"></i> <?= e(__('schools.setup.levels_title')) ?></legend>
                    <p class="small text-secondary"><?= e(__('schools.setup.levels_help')) ?></p>
                    <div class="row g-3">
                        <?php $numbers = ['' => '—'] + array_combine(array_map('strval', range(1, 12)), array_map('strval', range(1, 12))); ?>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'levels_from', 'label' => __('schools.setup.levels_from'), 'type' => 'select', 'options' => $numbers]) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'levels_to', 'label' => __('schools.setup.levels_to'), 'type' => 'select', 'options' => $numbers]) ?></div>
                        <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'classes_per_level', 'label' => __('schools.setup.classes_per_level'), 'type' => 'number', 'value' => '0', 'attrs' => 'min="0" max="15"']) ?></div>
                    </div>
                </fieldset>
                <fieldset class="setup-part">
                    <legend class="h6"><i class="bi bi-person-badge" aria-hidden="true"></i> <?= e(__('schools.setup.principal_title')) ?></legend>
                    <p class="small text-secondary"><?= e(__('schools.setup.principal_help')) ?></p>
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'principal_name', 'label' => __('schools.setup.principal_name'), 'attrs' => 'maxlength="150"']) ?></div>
                        <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'principal_username', 'label' => __('schools.setup.principal_username'), 'attrs' => 'maxlength="60" dir="ltr" autocapitalize="none" spellcheck="false"']) ?></div>
                    </div>
                </fieldset>
            <?php endif; ?>
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
