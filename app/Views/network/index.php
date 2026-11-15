<?php
use App\Core\View;

View::share('title', __('network.title'));
$number = static fn (mixed $value, string $suffix = ''): string => $value === null ? '—' : format_hours($value) . $suffix;
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('network.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('network.intro')) ?></p>
    </div>
</div>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('network.title')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('network.columns.school')) ?></th>
                <th scope="col"><?= e(__('network.columns.year')) ?></th>
                <th scope="col"><?= e(__('network.columns.students')) ?></th>
                <th scope="col"><?= e(__('network.columns.classes')) ?></th>
                <th scope="col"><?= e(__('network.columns.average')) ?></th>
                <th scope="col"><?= e(__('network.columns.attendance')) ?></th>
                <th scope="col"><?= e(__('network.columns.goals')) ?></th>
                <th scope="col"><?= e(__('network.columns.delayed')) ?></th>
                <th scope="col"><?= e(__('network.columns.indications')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-center text-secondary py-4"><?= e(__('app.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e($row['school']['name']) ?> <span class="small text-secondary" dir="ltr"><?= e($row['school']['code']) ?></span></th>
                    <td><?= e($row['year'] ?? __('nav.no_year')) ?></td>
                    <td dir="ltr" class="text-end"><?= e($number($row['students'])) ?></td>
                    <td dir="ltr" class="text-end"><?= e($number($row['classes'])) ?></td>
                    <td dir="ltr" class="text-end">
                        <?= e($number($row['average'])) ?>
                        <?php if ($row['average'] !== null): ?><div class="small text-secondary"><?= e(__('achievements.n_students', ['count' => $row['graded']])) ?></div><?php endif; ?>
                    </td>
                    <td dir="ltr" class="text-end"><?= e($number($row['attendance'], '%')) ?></td>
                    <td dir="ltr" class="text-end"><?= e($number($row['goals'], '%')) ?></td>
                    <td dir="ltr" class="text-end"><?= e($number($row['delayed'])) ?></td>
                    <td dir="ltr" class="text-end"><?= e($number($row['indications'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-secondary mb-1"><?= e(__('achievements.method')) ?></p>
<p class="small text-secondary mb-0"><?= e(__('network.privacy_note')) ?></p>
