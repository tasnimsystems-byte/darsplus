<?php
use App\Core\App;
use App\Core\View;

View::share('title', __('nav.dashboard'));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('dashboard.welcome', ['name' => App::auth()->user()['full_name']])) ?></h1>
        <p class="text-secondary mb-0">
            <?= e(App::auth()->school()['name']) ?> ·
            <?= e($year['name'] ?? __('nav.no_year')) ?>
        </p>
    </div>
    <?php if (can('reports.view')): ?>
        <a class="btn btn-outline-primary" href="<?= e(url('/reports')) ?>"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> <?= e(__('nav.reports')) ?></a>
    <?php endif; ?>
</div>

<?php if ($options !== []): ?>
    <form class="card card-body mb-4" method="get" action="<?= e(url('/')) ?>">
        <div class="row g-2 align-items-end">
            <?php foreach ($options as $name => $choices): ?>
                <div class="col-6 col-md">
                    <label class="form-label" for="f-<?= e($name) ?>"><?= e(__('reports.filters.' . $name)) ?></label>
                    <select class="form-select" id="f-<?= e($name) ?>" name="<?= e($name) ?>">
                        <option value=""><?= e(__('app.all')) ?></option>
                        <?php foreach ($choices as $value => $label): ?>
                            <option value="<?= (int) $value ?>"<?= ($filters[$name] ?? null) === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-outline-primary" type="submit"><?= e(__('app.filter')) ?></button>
                <?php if ($filters !== []): ?><a class="btn btn-link" href="<?= e(url('/')) ?>"><?= e(__('app.clear')) ?></a><?php endif; ?>
            </div>
        </div>
        <div class="form-text"><?= e(__('dashboard.filter_note')) ?></div>
    </form>
<?php endif; ?>

<?php foreach ($groups as $heading => $cards): ?>
    <h2 class="h6 text-secondary mb-2"><?= e(__($heading)) ?></h2>
    <div class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <a class="card kpi-card h-100" href="<?= e(url($card['link'])) ?>">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="bi bi-<?= e($card['icon']) ?>" aria-hidden="true"></i></div>
                        <div>
                            <div class="kpi-value"><?= e($card['value']) ?></div>
                            <div class="kpi-label"><?= e(__($card['label'])) ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if ($charts !== []): ?>
    <div class="row g-4 mb-4">
        <?php foreach ($charts as $i => $chart): ?>
            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0" id="chart-title-<?= $i ?>"><?= e(__($chart['title'])) ?></h2></div>
                    <div class="card-body">
                        <div class="chart-box">
                            <canvas class="js-chart" role="img" aria-labelledby="chart-title-<?= $i ?>" aria-describedby="chart-data-<?= $i ?>"
                                    data-chart="<?= e(json_encode([
                                        'type' => $chart['type'], 'max' => $chart['max'], 'label' => __($chart['title']),
                                        'labels' => array_map('strval', array_keys($chart['data'])), 'values' => array_values($chart['data']),
                                    ], JSON_UNESCAPED_UNICODE)) ?>"></canvas>
                        </div>
                        <?php /* The same numbers as a table: for screen readers, for copying, and when scripts are off. */ ?>
                        <details class="mt-2" id="chart-data-<?= $i ?>">
                            <summary class="small"><?= e(__('dashboard.chart_data')) ?></summary>
                            <table class="table table-sm mb-0">
                                <caption class="visually-hidden"><?= e(__($chart['title'])) ?></caption>
                                <tbody>
                                <?php foreach ($chart['data'] as $label => $value): ?>
                                    <tr><th scope="row" class="fw-normal"><?= e($label) ?></th><td dir="ltr" class="text-end"><?= e(format_hours($value)) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (can('audit.view')): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><?= e(__('dashboard.recent_activity')) ?></h2>
            <a class="small" href="<?= e(url('/audit')) ?>"><?= e(__('dashboard.all_activity')) ?></a>
        </div>
        <?php if ($activity === []): ?>
            <div class="card-body text-secondary"><?= e(__('app.none')) ?></div>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($activity as $row): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                        <span>
                            <?= e($row['full_name'] ?? __('audit.system')) ?> —
                            <?= e(trans_or('audit.actions.' . $row['action'], $row['action'])) ?>
                            (<?= e(trans_or('audit.entities.' . $row['entity_type'], $row['entity_type'])) ?>)
                        </span>
                        <time class="text-secondary small" dir="ltr"><?= e(format_datetime($row['created_at'])) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="text-secondary small mt-4 mb-0"><?= e(__('dashboard.stage_note')) ?></p>
