<?php
use App\Core\View;

/** @var array $student @var array $children @var array $published */
View::share('title', __('portal.certificates'));
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('portal.certificates'), 'back' => '/me/certificates']) ?>

<?php if ($published === []): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-award" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.no_certificates')) ?></p></div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($published as $term): ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <a class="card kpi-card h-100" href="<?= e(url('/me/certificates/' . (int) $term['id'])) ?>" target="_blank" rel="noopener">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="bi bi-award" aria-hidden="true"></i></div>
                        <div>
                            <div class="fw-semibold"><?= e($term['name']) ?></div>
                            <div class="kpi-label"><?= e(__('portal.published_on', ['date' => date('d/m/Y', strtotime($term['published_at']))])) ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
