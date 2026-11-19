<?php
/** @var array $subject one subject of PortalController::grades(): components, assessments */
$num = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
?>
<?php if ($subject['components'] !== []): ?>
    <div class="card-body pb-2">
        <?php foreach ($subject['components'] as $c): ?>
            <div class="portal-component">
                <span><?= e($c['name']) ?><?php if ($c['weight'] !== null): ?> <small class="text-secondary">(<?= e($num($c['weight'])) ?>%)</small><?php endif; ?></span>
                <progress max="100" value="<?= e($num($c['avg'])) ?>" aria-hidden="true"></progress>
                <strong dir="ltr"><?= e($num($c['avg'])) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($subject['assessments'] !== []): ?>
    <ul class="list-group list-group-flush small">
        <?php foreach ($subject['assessments'] as $a): ?>
            <li class="list-group-item d-flex justify-content-between gap-2">
                <span><?= e($a['title']) ?> <span class="text-secondary" dir="ltr"><?= e(date('d/m', strtotime($a['assessment_date']))) ?></span></span>
                <span dir="ltr">
                    <?php if ($a['result_status'] === 'graded' && $a['score'] !== null): ?>
                        <?= e($num($a['score'])) ?>/<?= e($num($a['max_score'])) ?>
                    <?php elseif ($a['result_status'] !== null && $a['result_status'] !== 'graded'): ?>
                        <?= e(__('assessments.result_statuses.' . $a['result_status'])) ?>
                    <?php elseif ($a['verbal_evaluation'] ?? null): ?>
                        <?= e($a['verbal_evaluation']) ?>
                    <?php else: ?>—<?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($subject['components'] === [] && $subject['assessments'] === []): ?>
    <div class="card-body small text-secondary"><?= e(__('portal.no_details')) ?></div>
<?php endif; ?>
