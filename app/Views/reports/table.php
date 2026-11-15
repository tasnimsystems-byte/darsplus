<?php
/** The report table itself — shared by the screen, print and PDF outputs. @var array $table @var string $title */
$cell = static fn (mixed $value): string => $value === null || $value === '' ? '—' : (is_float($value) ? format_hours($value) : (string) $value);
?>
<table class="table table-sm table-bordered align-middle report-table">
    <caption class="visually-hidden"><?= e($title) ?></caption>
    <thead>
    <tr>
        <?php foreach ($table['columns'] as $column): ?>
            <th scope="col"><?= e($column) ?></th>
        <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php if ($table['rows'] === []): ?>
        <tr><td colspan="<?= max(1, count($table['columns'])) ?>" class="text-center text-secondary py-4"><?= e(__('app.none')) ?></td></tr>
    <?php endif; ?>
    <?php foreach ($table['rows'] as $row): ?>
        <tr>
            <?php foreach (array_values($row) as $i => $value): ?>
                <?php if ($i === 0): ?>
                    <th scope="row" class="fw-normal"><?= e($cell($value)) ?></th>
                <?php else: ?>
                    <td<?= is_int($value) || is_float($value) ? ' dir="ltr" class="text-end"' : '' ?>><?= e($cell($value)) ?></td>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php foreach ($table['notes'] as $note): ?>
    <p class="small text-secondary mb-1"><?= e($note) ?></p>
<?php endforeach; ?>
