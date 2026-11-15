<?php
use App\Core\View;

View::share('title', __('parent_contacts.record'));
$id = (int) $row['id'];
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($meta['student_name']) ?> · <?= e(__('parent_contacts.types.' . $row['contact_type'])) ?></h1>
        <p class="text-secondary mb-0">
            <span dir="ltr"><?= e($date($row['contacted_on'])) ?></span>
            · <?= e(__('parent_contacts.fields.guardian_id')) ?>: <?= e($meta['guardian_name'] ?? '—') ?><?= !empty($meta['relation']) ? ' (' . e($meta['relation']) . ')' : '' ?>
            · <?= e(__('parent_contacts.staff')) ?>: <?= e($meta['staff_name'] ?? '—') ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url("/parent-contacts/$id/summary")) ?>"><i class="bi bi-printer" aria-hidden="true"></i> <?= e(__('parent_contacts.print_summary')) ?></a>
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/parent-contacts/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('parent_contacts.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/parent-contacts')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100 border-warning">
            <div class="card-header"><h2 class="h6 mb-0"><i class="bi bi-lock" aria-hidden="true"></i> <?= e(__('parent_contacts.fields.internal_notes')) ?></h2></div>
            <div class="card-body">
                <p class="small text-secondary"><?= e(__('parent_contacts.internal_help')) ?></p>
                <div class="prewrap"><?= e($row['internal_notes'] ?? '—') ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('parent_contacts.fields.shared_summary')) ?></h2></div>
            <div class="card-body">
                <div class="prewrap"><?= e($row['shared_summary'] ?? '—') ?></div>
                <?php if ($row['decisions']): ?>
                    <h3 class="h6 mt-3"><?= e(__('parent_contacts.fields.decisions')) ?></h3>
                    <div class="prewrap"><?= e($row['decisions']) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-footer small">
                <?= e(__('parent_contacts.fields.follow_up_status')) ?>:
                <span class="badge <?= $row['follow_up_status'] === 'open' ? 'text-bg-warning' : 'text-bg-light' ?>"><?= e(__('parent_contacts.follow_up.' . $row['follow_up_status'])) ?></span>
                <?php if ($row['follow_up_date']): ?><span dir="ltr"><?= e($date($row['follow_up_date'])) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
</div>
