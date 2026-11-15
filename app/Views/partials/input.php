<?php
use App\Core\View;

/**
 * One labelled form control with its error message.
 *
 * @var string      $name
 * @var string      $label     already translated
 * @var mixed       $value     current value (old input wins)
 * @var string|null $type      text (default) | email | password | date | number | select | textarea | file
 * @var bool|null   $required
 * @var array|null  $options   value => label, for type=select
 * @var string|null $help      already translated
 * @var string|null $attrs     extra raw attributes written by the calling view (never user input)
 */
$type = $type ?? 'text';
$required = $required ?? false;
$current = $type === 'password' || $type === 'file' ? '' : old($name, $value ?? '');

// A page may hold several forms with a field of the same name: ids stay unique.
$used = View::shared('input_ids') ?? [];
$used[$name] = ($used[$name] ?? 0) + 1;
View::share('input_ids', $used);
$fieldId = 'f-' . $name . ($used[$name] > 1 ? '-' . $used[$name] : '');

$error = error_for($name);
$invalid = $error !== null ? ' is-invalid' : '';
$describedBy = array_filter([isset($help) ? "help-$fieldId" : null, $error !== null ? "err-$fieldId" : null]);
$aria = ($error !== null ? ' aria-invalid="true"' : '') . ($describedBy !== [] ? ' aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '');
?>
<div class="mb-3">
    <label class="form-label" for="<?= e($fieldId) ?>">
        <?= e($label) ?>
        <?php if ($required): ?><span class="text-danger" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <?php if ($type === 'select'): ?>
        <select class="form-select<?= $invalid ?>" id="<?= e($fieldId) ?>" name="<?= e($name) ?>"
                <?= $required ? 'required' : '' ?><?= $aria ?> <?= $attrs ?? '' ?>>
            <?php foreach ($options ?? [] as $optionValue => $optionLabel): ?>
                <option value="<?= e($optionValue) ?>"<?= (string) $current === (string) $optionValue ? ' selected' : '' ?>><?= e($optionLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($type === 'textarea'): ?>
        <textarea class="form-control<?= $invalid ?>" id="<?= e($fieldId) ?>" name="<?= e($name) ?>"
                  <?= str_contains((string) ($attrs ?? ''), 'rows=') ? '' : 'rows="3"' ?>
                  <?= $required ? 'required' : '' ?><?= $aria ?> <?= $attrs ?? '' ?>><?= e($current) ?></textarea>
    <?php else: ?>
        <input class="form-control<?= $invalid ?>" type="<?= e($type) ?>" id="<?= e($fieldId) ?>" name="<?= e($name) ?>"
               <?= $type === 'file' ? '' : 'value="' . e($current) . '"' ?>
               <?= $required ? 'required' : '' ?><?= $aria ?> <?= $attrs ?? '' ?>>
    <?php endif; ?>
    <?php if (isset($help)): ?><div class="form-text" id="help-<?= e($fieldId) ?>"><?= e($help) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="invalid-feedback d-block" id="err-<?= e($fieldId) ?>"><?= e($error) ?></div><?php endif; ?>
</div>
