<?php
use App\Core\View;

View::share('title', $title);
$base = url("/reports/$key");
$link = static fn (string $format): string => $base . '?' . ($query !== '' ? $query . '&' : '') . 'format=' . $format;
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($title) ?></h1>
        <p class="text-secondary mb-0"><?= e($meta[0]) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($table['columns'] !== []): ?>
            <a class="btn btn-outline-primary" href="<?= e($link('print')) ?>"><i class="bi bi-printer" aria-hidden="true"></i> <?= e(__('app.print')) ?></a>
            <?php if ($canExport): ?>
                <a class="btn btn-outline-primary" href="<?= e($link('xlsx')) ?>"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Excel</a>
                <a class="btn btn-outline-primary" href="<?= e($link('pdf')) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</a>
            <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/reports')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<?php if ($options !== []): ?>
    <form class="card card-body mb-3" method="get" action="<?= e($base) ?>">
        <div class="row g-2 align-items-end">
            <?php foreach ($options as $name => $choices): ?>
                <?php $required = in_array($name, $missing, true) || in_array($name, \App\Reports\Reports::ALL[$key][2], true); ?>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="f-<?= e($name) ?>">
                        <?= e(__('reports.filters.' . $name)) ?><?php if ($required): ?> <span class="text-danger" aria-hidden="true">*</span><?php endif; ?>
                    </label>
                    <select class="form-select" id="f-<?= e($name) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?>>
                        <option value=""><?= e(__($required ? 'app.select_placeholder' : 'app.all')) ?></option>
                        <?php foreach ($choices as $value => $label): ?>
                            <option value="<?= (int) $value ?>"<?= ($filters[$name] ?? null) === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="col-auto"><button class="btn btn-primary" type="submit"><?= e(__('reports.run')) ?></button></div>
        </div>
    </form>
<?php endif; ?>

<?php if ($missing !== []): ?>
    <div class="card card-body text-secondary text-center py-5">
        <?= e(__('reports.choose', ['fields' => implode(', ', array_map(static fn ($name) => __('reports.filters.' . $name), $missing))])) ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive"><?= View::partial('reports/table', ['table' => $table, 'title' => $title]) ?></div>
            <p class="small text-secondary mt-2 mb-0"><?= e(__('app.total', ['count' => count($table['rows'])])) ?></p>
        </div>
    </div>
<?php endif; ?>
