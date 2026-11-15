<?php
use App\Core\View;

/* The page handed to the family. $row arrives without internal_notes. */
View::share('title', __('parent_contacts.summary_title'));
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
?>
<header class="mb-4">
    <div class="text-secondary"><?= e($school['name']) ?></div>
    <h1 class="h3"><?= e(__('parent_contacts.summary_title')) ?></h1>
</header>
<dl class="row">
    <dt class="col-sm-3"><?= e(__('parent_contacts.fields.student_id')) ?></dt><dd class="col-sm-9"><?= e($meta['student_name']) ?></dd>
    <dt class="col-sm-3"><?= e(__('parent_contacts.fields.contacted_on')) ?></dt><dd class="col-sm-9" dir="ltr"><?= e($date($row['contacted_on'])) ?></dd>
    <dt class="col-sm-3"><?= e(__('parent_contacts.fields.contact_type')) ?></dt><dd class="col-sm-9"><?= e(__('parent_contacts.types.' . $row['contact_type'])) ?></dd>
    <dt class="col-sm-3"><?= e(__('parent_contacts.staff')) ?></dt><dd class="col-sm-9"><?= e($meta['staff_name'] ?? '—') ?></dd>
</dl>
<h2 class="h5"><?= e(__('parent_contacts.fields.shared_summary')) ?></h2>
<div class="prewrap mb-4"><?= e($row['shared_summary'] ?? '—') ?></div>
<?php if ($row['decisions']): ?>
    <h2 class="h5"><?= e(__('parent_contacts.fields.decisions')) ?></h2>
    <div class="prewrap mb-4"><?= e($row['decisions']) ?></div>
<?php endif; ?>
<?php if ($row['follow_up_date']): ?>
    <p><?= e(__('parent_contacts.fields.follow_up_date')) ?>: <span dir="ltr"><?= e($date($row['follow_up_date'])) ?></span></p>
<?php endif; ?>
