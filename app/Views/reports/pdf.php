<?php
/* Stand-alone HTML handed to mPDF. Every value is escaped; no remote resources are referenced. */
$cell = static fn (mixed $value): string => $value === null || $value === '' ? '—' : (is_float($value) ? format_hours($value) : (string) $value);
?>
<html>
<head>
    <style>
        body { font-family: dejavusans; font-size: 9.5pt; color: #23302f; }
        h1 { font-size: 15pt; margin: 0 0 4pt; color: #1f3f4a; }
        .meta { font-size: 8.5pt; color: #5b6866; }
        table { width: 100%; border-collapse: collapse; margin-top: 10pt; }
        th, td { border: 0.5pt solid #b9c2bf; padding: 3pt 5pt; text-align: right; }
        thead th { background-color: #e6f1f3; font-weight: bold; }
        .note { font-size: 8pt; color: #5b6866; margin-top: 6pt; }
    </style>
</head>
<body>
<h1><?= e($title) ?></h1>
<?php foreach ($meta as $line): ?>
    <div class="meta"><?= e($line) ?></div>
<?php endforeach; ?>
<table>
    <thead>
    <tr>
        <?php foreach ($table['columns'] as $column): ?>
            <th><?= e($column) ?></th>
        <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($table['rows'] as $row): ?>
        <tr>
            <?php foreach ($row as $value): ?>
                <td><?= e($cell($value)) ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php foreach ($table['notes'] as $note): ?>
    <div class="note"><?= e($note) ?></div>
<?php endforeach; ?>
</body>
</html>
