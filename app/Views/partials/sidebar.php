<?php
use App\Core\App;
use App\Core\View;

$path = (string) View::shared('path');
// The active entry is the one whose path matches the most of the current path
// (so /assessments/gradebook marks "grade sheet", not also "assessments").
$best = '';
foreach (App::config('navigation') as $group) {
    foreach ($group['items'] as $item) {
        $candidate = $item['path'];
        if (($candidate === '/' ? $path === '/' : str_starts_with($path . '/', $candidate . '/')) && strlen($candidate) > strlen($best)) {
            $best = $candidate;
        }
    }
}
$isActive = static fn (string $item): bool => $item === $best;
?>
<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="sidebar" aria-labelledby="sidebar-title">
    <div class="app-sidebar-brand">
        <img class="app-logo" src="<?= e(asset('img/logo-96.png')) ?>" alt="" width="44" height="44">
        <span id="sidebar-title" class="app-brand-name"><?= e(__('app.name')) ?>
            <small lang="<?= App::lang()->locale() === 'ar' ? 'en' : 'ar' ?>"<?= App::lang()->locale() === 'ar' ? ' dir="ltr"' : '' ?>><?= App::lang()->locale() === 'ar' ? 'DarsPlus' : 'درس بلاس' ?></small></span>
        <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas"
                data-bs-target="#sidebar" aria-label="<?= e(__('app.close')) ?>"></button>
    </div>
    <nav aria-label="<?= e(__('app.main_nav')) ?>">
        <?php foreach (App::config('navigation') as $group): ?>
            <?php $items = array_filter($group['items'], static fn ($item) => can($item['permission']) && (empty($item['network']) || App::auth()->isNetwork())
                && (empty($item['not_regular_teacher']) || !is_regular_teacher())); ?>
            <?php if ($items === []) { continue; } ?>
            <div class="app-nav-group"><?= e(__($group['group'])) ?></div>
            <ul class="nav flex-column">
                <?php foreach ($items as $item): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $isActive($item['path']) ? ' active' : '' ?>" href="<?= e(url($item['path'])) ?>"
                           <?= $isActive($item['path']) ? 'aria-current="page"' : '' ?>>
                            <i class="bi bi-<?= e($item['icon']) ?>" aria-hidden="true"></i>
                            <span><?= e(__($item['label'])) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>
</div>
