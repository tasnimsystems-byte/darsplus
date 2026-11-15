<?php
use App\Core\App;
use App\Core\Lang;
use App\Core\View;
use App\Services\Notifier;

$auth = App::auth();
$school = $auth->school();
$year = $auth->year();
$years = $auth->years();
$unread = Notifier::unreadCount((int) $auth->id(), $auth->schoolId());
$canSwitch = count($auth->availableSchools()) > 1;
?>
<header class="app-topbar">
    <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar"
            aria-controls="sidebar" aria-label="<?= e(__('app.open_menu')) ?>">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <div class="app-context">
        <span class="app-context-school"><?= e($school['name'] ?? '') ?></span>
        <span class="app-context-year">
            <?php if (count($years) <= 1): ?>
                <?= e($year['name'] ?? __('nav.no_year')) ?>
                <?php if ($year && $year['is_locked']): ?><i class="bi bi-lock" aria-hidden="true"></i><span class="visually-hidden"><?= e(__('nav.year_locked')) ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($canSwitch): ?>
                <a href="<?= e(url('/select-school')) ?>"><?= e(__('nav.switch_school')) ?></a>
            <?php endif; ?>
        </span>
    </div>

    <div class="app-topbar-actions">
        <?php if (count($years) > 1): ?>
            <form method="post" action="<?= e(url('/year')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e((string) View::shared('path')) ?>">
                <label class="visually-hidden" for="year-switch"><?= e(__('nav.year')) ?></label>
                <select class="form-select form-select-sm js-autosubmit" id="year-switch" name="year_id">
                    <?php foreach ($years as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"<?= (int) $option['id'] === (int) $year['id'] ? ' selected' : '' ?>>
                            <?= e($option['name']) ?><?= $option['is_locked'] ? ' (' . e(__('nav.year_locked')) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-light" type="submit"><?= e(__('nav.year')) ?></button></noscript>
            </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/locale')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e((string) View::shared('path')) ?>">
            <label class="visually-hidden" for="locale-switch"><?= e(__('app.language')) ?></label>
            <select class="form-select form-select-sm js-autosubmit" id="locale-switch" name="locale">
                <?php foreach (Lang::SUPPORTED as $code => $name): ?>
                    <option value="<?= e($code) ?>" lang="<?= e($code) ?>"<?= App::lang()->locale() === $code ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-light" type="submit"><?= e(__('app.change_language')) ?></button></noscript>
        </form>

        <a class="btn btn-light position-relative" href="<?= e(url('/notifications')) ?>"
           aria-label="<?= e(__('nav.notifications_count', ['count' => $unread])) ?>">
            <i class="bi bi-bell" aria-hidden="true"></i>
            <?php if ($unread > 0): ?>
                <span class="badge rounded-pill text-bg-danger app-badge" aria-hidden="true"><?= $unread > 99 ? '99+' : $unread ?></span>
            <?php endif; ?>
        </a>

        <div class="dropdown">
            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle" aria-hidden="true"></i>
                <span class="d-none d-sm-inline"><?= e($auth->user()['full_name']) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text text-secondary small" dir="ltr"><?= e($auth->user()['username']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= e(url('/password')) ?>"><?= e(__('nav.change_password')) ?></a></li>
                <li>
                    <form method="post" action="<?= e(url('/logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="dropdown-item" type="submit"><?= e(__('nav.logout')) ?></button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
