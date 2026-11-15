<?php
use App\Core\View;
use App\Services\YearTransitionService;

View::share('title', __('years.transition.title'));
$id = (int) $year['id'];
$old = View::shared('old');
$oldClasses = is_array($old['classes'] ?? null) ? $old['classes'] : [];
$totalStudents = array_sum(array_column($plan, 'student_count'));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('years.transition.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('years.transition.from', ['name' => $year['name']])) ?></p>
    </div>
</div>

<div class="alert alert-info" role="note">
    <ul class="mb-0">
        <li><?= e(__('years.transition.note_history')) ?></li>
        <li><?= e(__('years.transition.note_assignments')) ?></li>
        <li><?= e(__('years.transition.note_single')) ?></li>
    </ul>
</div>

<form method="post" action="<?= e(url("/years/$id/transition")) ?>" novalidate
      data-confirm="<?= e(__('years.transition.confirm', ['classes' => count($plan), 'students' => $totalStudents])) ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('years.transition.new_year')) ?></h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'name', 'label' => __('years.fields.name'), 'required' => true, 'attrs' => 'maxlength="30"']) ?></div>
                <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'start_date', 'label' => __('years.fields.start_date'), 'type' => 'date', 'required' => true, 'value' => $suggested['start_date']]) ?></div>
                <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'end_date', 'label' => __('years.fields.end_date'), 'type' => 'date', 'required' => true, 'value' => $suggested['end_date']]) ?></div>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="make_current" name="make_current" value="1"<?= $old === [] || isset($old['make_current']) ? ' checked' : '' ?>>
                <label class="form-check-label" for="make_current"><?= e(__('years.transition.make_current')) ?></label>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('years.transition.classes')) ?></h2></div>
        <?php if (error_for('classes')): ?>
            <div class="alert alert-danger m-3 mb-0" role="alert"><?= e(error_for('classes')) ?></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden"><?= e(__('years.transition.classes')) ?></caption>
                <thead>
                <tr>
                    <th scope="col"><?= e(__('years.transition.source_class')) ?></th>
                    <th scope="col"><?= e(__('years.transition.students')) ?></th>
                    <th scope="col"><?= e(__('years.transition.target')) ?></th>
                    <th scope="col"><?= e(__('years.transition.new_name')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($plan === []): ?>
                    <tr><td colspan="4" class="text-center text-secondary py-4"><?= e(__('years.transition.no_classes')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($plan as $class): ?>
                    <?php
                    $cid = (int) $class['id'];
                    $target = (string) ($oldClasses[$cid]['target'] ?? $class['target']);
                    $newName = (string) ($oldClasses[$cid]['name'] ?? $class['new_name']);
                    ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= e($class['name']) ?> <span class="text-secondary small">(<?= e($class['level_name']) ?>)</span></th>
                        <td><?= (int) $class['student_count'] ?></td>
                        <td>
                            <select class="form-select" name="classes[<?= $cid ?>][target]"
                                    aria-label="<?= e(__('years.transition.target') . ': ' . $class['name']) ?>">
                                <?php foreach ($levels as $levelId => $levelName): ?>
                                    <option value="<?= (int) $levelId ?>"<?= $target === (string) $levelId ? ' selected' : '' ?>><?= e($levelName) ?></option>
                                <?php endforeach; ?>
                                <option value="<?= YearTransitionService::GRADUATE ?>"<?= $target === YearTransitionService::GRADUATE ? ' selected' : '' ?>><?= e(__('years.transition.graduate')) ?></option>
                                <option value="<?= YearTransitionService::SKIP ?>"<?= $target === YearTransitionService::SKIP ? ' selected' : '' ?>><?= e(__('years.transition.skip')) ?></option>
                            </select>
                        </td>
                        <td>
                            <input class="form-control" name="classes[<?= $cid ?>][name]" maxlength="40" value="<?= e($newName) ?>"
                                   aria-label="<?= e(__('years.transition.new_name') . ': ' . $class['name']) ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit"><?= e(__('years.transition.run')) ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url("/years/$id")) ?>"><?= e(__('app.cancel')) ?></a>
    </div>
</form>
