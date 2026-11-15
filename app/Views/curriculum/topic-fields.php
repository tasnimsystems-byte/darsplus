<?php
use App\Core\View;

/** @var array|null $topic @var array $units @var array $parents */
$f = 'curriculum.topic_fields.';
?>
<div class="row g-2">
    <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'title', 'label' => __($f . 'title'), 'required' => true, 'value' => $topic['title'] ?? '', 'attrs' => 'maxlength="190"']) ?></div>
    <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'unit_id', 'label' => __($f . 'unit_id'), 'type' => 'select', 'required' => true, 'value' => $topic['unit_id'] ?? '', 'options' => $units]) ?></div>
    <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'parent_topic_id', 'label' => __($f . 'parent_topic_id'), 'type' => 'select', 'value' => $topic['parent_topic_id'] ?? '', 'options' => ['' => '—'] + $parents, 'help' => __('curriculum.parent_help')]) ?></div>
    <div class="col-6 col-md-3"><?= View::partial('partials/input', ['name' => 'planned_week_start', 'label' => __($f . 'planned_week_start'), 'type' => 'date', 'value' => $topic['planned_week_start'] ?? '']) ?></div>
    <div class="col-6 col-md-3"><?= View::partial('partials/input', ['name' => 'planned_hours', 'label' => __($f . 'planned_hours'), 'value' => isset($topic['planned_hours']) ? format_hours($topic['planned_hours']) : '', 'attrs' => 'dir="ltr" inputmode="decimal" maxlength="4"']) ?></div>
    <?php foreach (['learning_objectives', 'skills', 'teaching_methods', 'assessment_methods'] as $field): ?>
        <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => $field, 'label' => __($f . $field), 'type' => 'textarea', 'value' => $topic[$field] ?? '', 'attrs' => 'maxlength="2000" rows="2"']) ?></div>
    <?php endforeach; ?>
</div>
