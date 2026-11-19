<?php

declare(strict_types=1);

/**
 * Accounts linked to an external identity (OpenID Connect, e.g. the Ministry of Education).
 * Only a keyed fingerprint (HMAC-SHA256) of the provider's identifier is stored – never the identifier
 * itself, which may be a national ID number.
 */
return [
    'ALTER TABLE users
        ADD COLUMN sso_subject_hash CHAR(64) NULL AFTER password_hash,
        ADD COLUMN sso_linked_at DATETIME NULL AFTER sso_subject_hash,
        ADD UNIQUE KEY uq_users_sso (sso_subject_hash)',
];
