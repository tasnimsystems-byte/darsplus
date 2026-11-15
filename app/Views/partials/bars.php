<?php
/**
 * Horizontal bars for values between 0 and 100, as an accessible list.
 * Uses <progress> so that no inline styles are needed (the CSP forbids them).
 *
 * @var array<int, array{label: string, value: ?float, note?: string}> $rows
 * @var string $unit  text after the value, e.g. "%" or ""
 */
$unit = $unit ?? '';
?>
<ul class="bar-list">
    <?php foreach ($rows as $row): ?>
        <li>
            <span class="bar-label"><?= e($row['label']) ?></span>
            <?php if ($row['value'] === null): ?>
                <span class="bar-value text-secondary">—</span>
            <?php else: ?>
                <progress max="100" value="<?= e(max(0, min(100, (float) $row['value']))) ?>" aria-label="<?= e($row['label']) ?>"></progress>
                <span class="bar-value"><?= e(format_hours($row['value'])) ?><?= e($unit) ?></span>
            <?php endif; ?>
            <?php if (!empty($row['note'])): ?><span class="bar-note"><?= e($row['note']) ?></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
