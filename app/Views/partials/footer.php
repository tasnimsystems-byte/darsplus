<?php
use App\Core\App;

/** The product, its maker and the rights notice – at the foot of every page. */
$vendor = (array) App::config('app.vendor', []);
?>
<footer class="app-footer d-print-none">
    <span>&copy; <?= e(date('Y')) ?> <strong><?= e(__('app.name')) ?></strong><?php if (__('app.name') !== 'DarsPlus'): ?> <span dir="ltr">DarsPlus</span><?php endif; ?></span>
    <span class="app-footer-sep" aria-hidden="true">·</span>
    <span><?= e(__('app.made_by', ['vendor' => (string) ($vendor['name'] ?? '')])) ?></span>
    <?php if (!empty($vendor['email'])): ?>
        <span class="app-footer-sep" aria-hidden="true">·</span>
        <a href="mailto:<?= e((string) $vendor['email']) ?>" dir="ltr"><?= e((string) $vendor['email']) ?></a>
    <?php endif; ?>
    <?php if (!empty($vendor['phone'])): ?>
        <span class="app-footer-sep" aria-hidden="true">·</span>
        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $vendor['phone'])) ?>" dir="ltr"><?= e((string) $vendor['phone']) ?></a>
    <?php endif; ?>
    <span class="app-footer-sep" aria-hidden="true">·</span>
    <span><?= e(__('app.rights_reserved')) ?></span>
</footer>
