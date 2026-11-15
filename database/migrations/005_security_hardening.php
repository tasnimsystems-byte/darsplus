<?php

declare(strict_types=1);

/**
 * Stage G: sessions opened before a password change (or an administrator's
 * reset) stop being valid. The time of the last change is compared with the
 * time the session was authenticated.
 */
return [
    'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password',
];
