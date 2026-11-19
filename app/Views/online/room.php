<?php
use App\Controllers\TimetableController;
use App\Core\View;

/** @var array $meeting @var string $role @var string $back @var string $identity @var string $displayName @var array $periods */
View::share('title', __('online.title') . ' – ' . $meeting['subject_name']);
$id = (int) $meeting['id'];
$isHost = $role === 'host';
$where = $meeting['study_group_name'] !== null ? $meeting['study_group_name'] : $meeting['class_name'];
$strings = [];
foreach (['you', 'host', 'staff', 'joined', 'left', 'hand_raised', 'hand_lowered_by_host', 'muted_by_host', 'camera_stopped_by_host',
          'connecting', 'connect_error', 'device_error', 'screen_error', 'reconnecting', 'reconnected', 'ended_title', 'ended_text',
          'removed_text', 'disconnected_text', 'mic_on', 'mic_off', 'cam_on', 'cam_off', 'screen_on', 'screen_off', 'hand_up', 'hand_down',
          'mics_locked', 'mics_unlocked', 'mic_locked_hint', 'action_failed', 'mute', 'stop_camera', 'remove', 'allow_screen', 'deny_screen',
          'lower_hand', 'remove_confirm', 'screen_allowed', 'screen_denied', 'no_messages', 'enable_audio', 'presenting', 'nobody_else',
          'chat_from_host', 'everyone_muted', 'lock_mics', 'unlock_mics'] as $key) {
    $strings[$key] = __('online.js.' . $key);
}
?>
<div class="meet" id="meet"
     data-token-url="<?= e(url("/online/$id/token")) ?>" data-moderate-url="<?= e(url("/online/$id/moderate")) ?>"
     data-csrf="<?= e(\App\Core\App::csrf()->token()) ?>" data-role="<?= e($role) ?>" data-identity="<?= e($identity) ?>"
     data-back="<?= e(url($back)) ?>" data-started="<?= e((string) (strtotime((string) $meeting['started_at']) * 1000)) ?>"
     data-i18n="<?= e(json_encode($strings, JSON_UNESCAPED_UNICODE)) ?>">

    <header class="meet-bar">
        <div class="meet-heading">
            <span class="meet-live" aria-hidden="true"></span>
            <div class="min-w-0">
                <h1 class="meet-title"><?= e($meeting['subject_name']) ?> <span class="meet-where">· <?= e($where) ?></span></h1>
                <p class="meet-sub">
                    <?= e($meeting['teacher_name']) ?> ·
                    <?= e(TimetableController::periodLabel($periods, (int) $meeting['period_number'])) ?>
                </p>
            </div>
        </div>
        <div class="meet-meta">
            <span class="meet-chip" title="<?= e(__('online.elapsed')) ?>"><i class="bi bi-clock" aria-hidden="true"></i> <span id="meet-clock" dir="ltr">00:00</span></span>
            <span class="meet-chip" title="<?= e(__('online.participants')) ?>"><i class="bi bi-people" aria-hidden="true"></i> <span id="meet-count">0</span></span>
            <span class="meet-chip meet-chip-lock" id="meet-lock-chip" hidden><i class="bi bi-mic-mute" aria-hidden="true"></i> <?= e(__('online.js.mics_locked')) ?></span>
        </div>
    </header>

    <!-- Before entering: choose microphone and camera. -->
    <section class="meet-lobby" id="meet-lobby" aria-labelledby="meet-lobby-title">
        <div class="meet-lobby-card">
            <div class="meet-lobby-avatar" aria-hidden="true"><?= e(mb_substr($displayName !== '' ? $displayName : '?', 0, 1)) ?></div>
            <h2 class="h4" id="meet-lobby-title"><?= e(__('online.ready')) ?></h2>
            <p class="text-secondary mb-4"><?= e(__($isHost ? 'online.lobby_host' : 'online.lobby_student')) ?></p>
            <div class="meet-lobby-options">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="meet-start-mic"<?= $isHost ? ' checked' : '' ?>>
                    <label class="form-check-label" for="meet-start-mic"><i class="bi bi-mic" aria-hidden="true"></i> <?= e(__('online.start_mic')) ?></label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="meet-start-cam"<?= $isHost ? ' checked' : '' ?>>
                    <label class="form-check-label" for="meet-start-cam"><i class="bi bi-camera-video" aria-hidden="true"></i> <?= e(__('online.start_cam')) ?></label>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-lg w-100" id="meet-join"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> <?= e(__('online.join')) ?></button>
            <p class="meet-lobby-status small mt-3 mb-0" id="meet-status" role="status" aria-live="polite"></p>
            <p class="small text-secondary mt-3 mb-0"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= e(__('online.privacy_note')) ?></p>
            <a class="btn btn-link mt-2" href="<?= e(url($back)) ?>"><?= e(__('app.back')) ?></a>
        </div>
    </section>

    <div class="meet-main" id="meet-main" hidden>
        <div class="meet-stage" id="meet-stage">
            <div class="meet-spotlight" id="meet-spotlight" hidden>
                <video id="meet-screen" autoplay playsinline muted></video>
                <span class="meet-spotlight-label" id="meet-spotlight-label"></span>
            </div>
            <div class="meet-grid" id="meet-grid" aria-label="<?= e(__('online.participants')) ?>"></div>
        </div>

        <aside class="meet-panel" id="meet-panel" hidden aria-label="<?= e(__('online.panel')) ?>">
            <div class="meet-panel-tabs" role="tablist">
                <button type="button" role="tab" class="meet-tab" id="meet-tab-people" aria-controls="meet-people" aria-selected="true"><i class="bi bi-people" aria-hidden="true"></i> <?= e(__('online.participants')) ?></button>
                <button type="button" role="tab" class="meet-tab" id="meet-tab-chat" aria-controls="meet-chat" aria-selected="false"><i class="bi bi-chat-dots" aria-hidden="true"></i> <?= e(__('online.chat')) ?></button>
                <button type="button" class="btn-close btn-close-white ms-auto" id="meet-panel-close" aria-label="<?= e(__('app.close')) ?>"></button>
            </div>
            <div class="meet-people" id="meet-people" role="tabpanel" aria-labelledby="meet-tab-people">
                <?php if ($isHost): ?>
                    <div class="meet-host-tools">
                        <button type="button" class="btn btn-sm btn-outline-light" data-meet-moderate="mute_all"><i class="bi bi-mic-mute" aria-hidden="true"></i> <?= e(__('online.mute_all')) ?></button>
                        <button type="button" class="btn btn-sm btn-outline-light" id="meet-lock" aria-pressed="false"><i class="bi bi-lock" aria-hidden="true"></i> <span><?= e(__('online.js.lock_mics')) ?></span></button>
                    </div>
                <?php endif; ?>
                <ul class="meet-people-list" id="meet-people-list"></ul>
            </div>
            <div class="meet-chat" id="meet-chat" role="tabpanel" aria-labelledby="meet-tab-chat" hidden>
                <ol class="meet-chat-list" id="meet-chat-list" aria-live="polite"></ol>
                <form class="meet-chat-form" id="meet-chat-form" autocomplete="off">
                    <label class="visually-hidden" for="meet-chat-input"><?= e(__('online.message')) ?></label>
                    <input class="form-control" id="meet-chat-input" maxlength="500" placeholder="<?= e(__('online.message')) ?>">
                    <button class="btn btn-primary" type="submit" aria-label="<?= e(__('online.send')) ?>"><i class="bi bi-send" aria-hidden="true"></i></button>
                </form>
            </div>
        </aside>
    </div>

    <footer class="meet-controls" id="meet-controls" hidden>
        <div class="meet-controls-group">
            <button type="button" class="meet-btn" id="meet-mic" aria-pressed="false"><i class="bi bi-mic-mute-fill" aria-hidden="true"></i><span><?= e(__('online.mic')) ?></span></button>
            <button type="button" class="meet-btn" id="meet-cam" aria-pressed="false"><i class="bi bi-camera-video-off-fill" aria-hidden="true"></i><span><?= e(__('online.camera')) ?></span></button>
            <button type="button" class="meet-btn" id="meet-share" aria-pressed="false"<?= $isHost ? '' : ' hidden' ?>><i class="bi bi-display" aria-hidden="true"></i><span><?= e(__('online.share')) ?></span></button>
            <button type="button" class="meet-btn" id="meet-hand" aria-pressed="false"><i class="bi bi-hand-index-thumb" aria-hidden="true"></i><span><?= e(__('online.raise_hand')) ?></span></button>
        </div>
        <div class="meet-controls-group">
            <button type="button" class="meet-btn" id="meet-open-people" aria-pressed="false"><i class="bi bi-people" aria-hidden="true"></i><span><?= e(__('online.participants')) ?></span></button>
            <button type="button" class="meet-btn" id="meet-open-chat" aria-pressed="false"><i class="bi bi-chat-dots" aria-hidden="true"></i><span><?= e(__('online.chat')) ?></span><b class="meet-badge" id="meet-unread" hidden>0</b></button>
        </div>
        <div class="meet-controls-group">
            <button type="button" class="meet-btn meet-btn-leave" id="meet-leave"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span><?= e(__('online.leave')) ?></span></button>
            <?php if ($isHost): ?>
                <form method="post" action="<?= e(url("/online/$id/end")) ?>" data-confirm="<?= e(__('online.end_confirm')) ?>" id="meet-end-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="meet-btn meet-btn-end"><i class="bi bi-stop-circle" aria-hidden="true"></i><span><?= e(__('online.end_for_all')) ?></span></button>
                </form>
            <?php endif; ?>
        </div>
    </footer>

    <section class="meet-ended" id="meet-ended" hidden aria-live="assertive">
        <div class="meet-lobby-card">
            <i class="bi bi-door-closed meet-ended-icon" aria-hidden="true"></i>
            <h2 class="h4" id="meet-ended-title"><?= e(__('online.js.ended_title')) ?></h2>
            <p class="text-secondary" id="meet-ended-text"></p>
            <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-outline-primary" id="meet-rejoin" hidden><?= e(__('online.rejoin')) ?></button>
                <a class="btn btn-primary" href="<?= e(url($back)) ?>"><?= e(__('app.back')) ?></a>
            </div>
        </div>
    </section>

    <button type="button" class="btn btn-warning meet-audio-btn" id="meet-audio" hidden><i class="bi bi-volume-up" aria-hidden="true"></i> <?= e(__('online.js.enable_audio')) ?></button>
    <div class="meet-toasts" id="meet-toasts" aria-live="polite"></div>
    <div id="meet-audio-sink" hidden></div>
</div>
