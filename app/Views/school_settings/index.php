<?php
use App\Controllers\AssessmentTypeController;
use App\Controllers\SchoolSettingsController;
use App\Core\View;

/** @var array $year @var array $terms @var array $components @var int $newRows @var array $template */
View::share('title', __('school_settings.title'));
$old = View::shared('old');
$oldComponents = is_array($old['components'] ?? null) ? $old['components'] : null;
$oldNew = is_array($old['new'] ?? null) ? $old['new'] : [];
$componentErrors = View::shared('errors')['components'] ?? [];
$weighted = array_filter($components, static fn ($c) => $c['term_weight'] !== null);
$total = array_sum(array_map(static fn ($c) => (float) $c['term_weight'], $weighted));
$fmt = static fn ($n) => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('school_settings.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('school_settings.subtitle')) ?></p>
    </div>
</div>

<nav class="nav nav-pills mb-4 gap-2" aria-label="<?= e(__('school_settings.title')) ?>">
    <a class="nav-link border" href="#terms"><i class="bi bi-calendar-range" aria-hidden="true"></i> <?= e(__('school_settings.terms_title')) ?></a>
    <a class="nav-link border" href="#components"><i class="bi bi-percent" aria-hidden="true"></i> <?= e(__('school_settings.components_title')) ?></a>
</nav>

<!-- Terms -->
<section class="card mb-4" id="terms" aria-labelledby="terms-title">
    <div class="card-header"><h2 class="h5 mb-0" id="terms-title"><?= e(__('school_settings.terms_title')) ?></h2></div>
    <div class="card-body">
        <p class="text-secondary small"><?= e(__('school_settings.terms_help', ['year' => $year['name']])) ?></p>
        <?php if ($year['is_locked']): ?>
            <div class="alert alert-warning" role="alert"><?= e(__('app.errors.year_locked')) ?></div>
        <?php else: ?>
            <form class="row g-2 align-items-end mb-3" method="post" action="<?= e(url('/school-settings/terms')) ?>"
                  data-confirm="<?= e(__('school_settings.terms_confirm')) ?>">
                <?= csrf_field() ?>
                <div class="col-auto">
                    <label class="form-label" for="term-count"><?= e(__('school_settings.term_count')) ?></label>
                    <select class="form-select" id="term-count" name="term_count">
                        <?php for ($n = 1; $n <= SchoolSettingsController::MAX_TERMS; $n++): ?>
                            <option value="<?= $n ?>"<?= $n === max(1, count($terms)) ? ' selected' : '' ?>><?= e(__('school_settings.term_count_option.' . $n)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary" type="submit"><?= e(__('school_settings.save_terms')) ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($terms === []): ?>
            <p class="text-secondary mb-0"><?= e(__('school_settings.no_terms')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <caption class="visually-hidden"><?= e(__('school_settings.terms_title')) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('years.term_fields.name')) ?></th>
                        <th scope="col"><?= e(__('years.term_fields.start_date')) ?></th>
                        <th scope="col"><?= e(__('years.term_fields.end_date')) ?></th>
                        <th scope="col"><?= e(__('school_settings.assessments_count')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($terms as $term): ?>
                        <tr>
                            <td><?= e($term['name']) ?></td>
                            <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($term['start_date']))) ?></td>
                            <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($term['end_date']))) ?></td>
                            <td><?= (int) $term['assessments'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <a class="small" href="<?= e(url('/years/' . (int) $year['id'])) ?>"><?= e(__('school_settings.edit_dates')) ?></a>
        <?php endif; ?>
    </div>
</section>

<!-- Components of the term grade -->
<section class="card mb-4" id="components" aria-labelledby="components-title">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 mb-0" id="components-title"><?= e(__('school_settings.components_title')) ?></h2>
        <form method="post" action="<?= e(url('/school-settings/components/template')) ?>" data-confirm="<?= e(__('school_settings.template_confirm')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-magic" aria-hidden="true"></i> <?= e(__('school_settings.use_template')) ?></button>
        </form>
    </div>
    <form method="post" action="<?= e(url('/school-settings/components')) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
            <p class="small text-secondary mb-1"><?= e(__('school_settings.components_help')) ?></p>
            <p class="small text-secondary"><?= e(__('school_settings.template_list', ['list' => implode('، ', array_map(
                static fn ($t) => __('school_settings.template.' . $t[0]) . ' ' . $t[2] . '%', $template
            ))])) ?></p>
            <?php if ($componentErrors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <?php foreach ($componentErrors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
                </div>
            <?php elseif ($weighted === []): ?>
                <div class="alert alert-info" role="note"><?= e(__('school_settings.no_weights')) ?></div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table align-middle mb-0 components-table" data-weight-table>
                    <caption class="visually-hidden"><?= e(__('school_settings.components_title')) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('school_settings.component')) ?></th>
                        <th scope="col"><?= e(__('assessment_types.fields.category')) ?></th>
                        <th scope="col" class="weight-col"><?= e(__('school_settings.weight')) ?></th>
                        <th scope="col"><?= e(__('school_settings.assessments_count')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($components as $c): ?>
                        <?php
                        $id = (int) $c['id'];
                        $nameValue = $oldComponents[$id]['name'] ?? $c['name'];
                        $weightValue = $oldComponents !== null ? (string) ($oldComponents[$id]['weight'] ?? '') : $fmt($c['term_weight']);
                        ?>
                        <tr>
                            <td>
                                <label class="visually-hidden" for="c-name-<?= $id ?>"><?= e(__('school_settings.component')) ?></label>
                                <input class="form-control form-control-sm" id="c-name-<?= $id ?>" name="components[<?= $id ?>][name]" maxlength="80" value="<?= e((string) $nameValue) ?>">
                            </td>
                            <td class="small"><?= e(__('assessment_types.categories.' . $c['category'])) ?></td>
                            <td>
                                <?php if ($c['is_numeric']): ?>
                                    <div class="input-group input-group-sm">
                                        <label class="visually-hidden" for="c-weight-<?= $id ?>"><?= e(__('school_settings.weight_of', ['name' => $c['name']])) ?></label>
                                        <input class="form-control" type="number" min="0" max="100" step="0.5" inputmode="decimal" id="c-weight-<?= $id ?>"
                                               name="components[<?= $id ?>][weight]" value="<?= e($weightValue) ?>" data-weight>
                                        <span class="input-group-text">%</span>
                                    </div>
                                <?php else: ?>
                                    <span class="small text-secondary"><?= e(__('school_settings.not_numeric')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $c['assessments'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php for ($i = 0; $i < $newRows; $i++): ?>
                        <tr class="table-light">
                            <td>
                                <label class="visually-hidden" for="n-name-<?= $i ?>"><?= e(__('school_settings.new_component')) ?></label>
                                <input class="form-control form-control-sm" id="n-name-<?= $i ?>" name="new[<?= $i ?>][name]" maxlength="80"
                                       placeholder="<?= e(__('school_settings.new_component')) ?>" value="<?= e((string) ($oldNew[$i]['name'] ?? '')) ?>">
                            </td>
                            <td>
                                <label class="visually-hidden" for="n-cat-<?= $i ?>"><?= e(__('assessment_types.fields.category')) ?></label>
                                <select class="form-select form-select-sm" id="n-cat-<?= $i ?>" name="new[<?= $i ?>][category]">
                                    <?php foreach (AssessmentTypeController::CATEGORIES as $category): ?>
                                        <option value="<?= e($category) ?>"<?= ($oldNew[$i]['category'] ?? 'exam') === $category ? ' selected' : '' ?>><?= e(__('assessment_types.categories.' . $category)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <label class="visually-hidden" for="n-weight-<?= $i ?>"><?= e(__('school_settings.weight')) ?></label>
                                    <input class="form-control" type="number" min="0" max="100" step="0.5" inputmode="decimal" id="n-weight-<?= $i ?>"
                                           name="new[<?= $i ?>][weight]" value="<?= e((string) ($oldNew[$i]['weight'] ?? '')) ?>" data-weight>
                                    <span class="input-group-text">%</span>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                    <tfoot><tr>
                        <th scope="row" colspan="2"><?= e(__('school_settings.total')) ?></th>
                        <td><strong data-weight-total dir="ltr"><?= e($fmt($total)) ?>%</strong></td>
                        <td></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
            <button class="btn btn-primary" type="submit"><?= e(__('school_settings.save_components')) ?></button>
            <span class="small text-secondary"><?= e(__('school_settings.total_rule')) ?></span>
        </div>
    </form>
</section>
