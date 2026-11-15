<?php
use App\Core\App;
use App\Core\View;

$path = (string) View::shared('path');
$isActive = static fn (string $item): bool => $item === '/' ? $path === '/' : str_starts_with($path . '/', $item . '/');
?>
<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="sidebar" aria-labelledby="sidebar-title">
    <div class="app-sidebar-brand">
        <i class="bi bi-mortarboard" aria-hidden="true"></i>
        <span id="sidebar-title"><?= e(__('app.name')) ?></span>
        <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas"
                data-bs-target="#sidebar" aria-label="<?= e(__('app.close')) ?>"></button>
    </div>
    <nav aria-label="<?= e(__('app.main_nav')) ?>">
        <?php foreach (App::config('navigation') as $group): ?>
            <?php $items = array_filter($group['items'], static fn ($item) => can($item['permission']) && (empty($item['network']) || App::auth()->isNetwork())); ?>
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
