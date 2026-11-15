<?php
use App\Core\View;

/** @var array $def @var array $fields @var array|null $row @var array $values */
$lang = $def['lang'];
$isEdit = $row !== null;
$heading = __($isEdit ? "$lang.edit" : "$lang.add");
View::share('title', $heading);
$required = static fn (array $field): bool => in_array('required', is_array($field['rules']) ? $field['rules'] : explode('|', $field['rules']), true);
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e($heading) ?></h1>
</div>

<div class="card form-card">
    <div class="card-body">
        <form method="post" action="<?= e(url($isEdit ? $def['path'] . '/' . (int) $row['id'] : $def['path'])) ?>" novalidate>
            <?= csrf_field() ?>
            <?php foreach ($fields as $name => $field): ?>
                <?php
                $label = __("$lang.fields.$name");
                $value = $isEdit ? ($values[$name] ?? '') : $field['default'];
                $help = isset($field['help']) ? __($field['help']) : null;
                ?>
                <?php if ($field['type'] === 'hidden'): ?>
                    <input type="hidden" name="<?= e($name) ?>" value="<?= e(old($name, $value)) ?>">
                <?php elseif ($field['type'] === 'checkbox'): ?>
                    <?php $checked = View::shared('old') !== [] ? old($name, null) !== null : (bool) $value; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="f-<?= e($name) ?>" name="<?= e($name) ?>" value="1"<?= $checked ? ' checked' : '' ?>>
                        <label class="form-check-label" for="f-<?= e($name) ?>"><?= e($label) ?></label>
                    </div>
                <?php elseif ($field['type'] === 'multicheck'): ?>
                    <?php
                    $oldValues = old($name, null);
                    $selected = array_map('intval', is_array($oldValues) ? $oldValues : (array) $value);
                    ?>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6"><?= e($label) ?></legend>
                        <?php if ($field['options'] === []): ?>
                            <div class="form-text"><?= e(__('app.no_options')) ?></div>
                        <?php endif; ?>
                        <div class="row row-cols-2 row-cols-md-3 g-1">
                            <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                                <div class="col">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="<?= e($name) ?>[]" value="<?= (int) $optionValue ?>"
                                               id="f-<?= e($name) ?>-<?= (int) $optionValue ?>"<?= in_array((int) $optionValue, $selected, true) ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="f-<?= e($name) ?>-<?= (int) $optionValue ?>"><?= e($optionLabel) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php else: ?>
                    <?= View::partial('partials/input', array_filter([
                        'name' => $name,
                        'label' => $label,
                        'value' => $value,
                        'type' => $field['type'],
                        'required' => $required($field),
                        'options' => $field['type'] === 'select'
                            ? ($required($field) && $value !== '' ? [] : ['' => __('app.select_placeholder')]) + $field['options']
                            : null,
                        'help' => $help,
                        'attrs' => $field['attrs'] ?? null,
                    ], static fn ($v) => $v !== null)) ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url($def['path'])) ?>"><?= e(__('app.cancel')) ?></a>
            </div>
        </form>
    </div>
</div>
