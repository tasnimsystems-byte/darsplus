<?php

declare(strict_types=1);

/**
 * Sign-in through an external identity provider (OpenID Connect), intended for the Ministry of
 * Education's identification system once the school is registered there as an approved application.
 * Off until every value below is filled in .env. The user's password is typed only on the provider's
 * page; this application never sees it.
 */
return [
    'enabled' => (bool) env('SSO_ENABLED', false),
    // Base URL of the provider; its settings are read from <issuer>/.well-known/openid-configuration.
    'issuer' => rtrim((string) env('SSO_ISSUER', ''), '/'),
    'client_id' => (string) env('SSO_CLIENT_ID', ''),
    'client_secret' => (string) env('SSO_CLIENT_SECRET', ''),
    // Must be registered with the provider exactly as written, e.g. https://spms.school.org.il/auth/sso/callback
    'redirect_uri' => (string) env('SSO_REDIRECT_URI', ''),
    'scopes' => (string) env('SSO_SCOPES', 'openid'),
    // The claim of the ID token that identifies the person (the provider's documentation names it; "sub" by default).
    'id_claim' => (string) env('SSO_ID_CLAIM', 'sub'),
    // Key of the fingerprint stored instead of that identifier (at least 24 random characters, kept like BACKUP_KEY).
    'hash_key' => (string) env('SSO_HASH_KEY', ''),
    // Text of the button on the sign-in page (Hebrew / Arabic).
    'label_he' => (string) env('SSO_LABEL_HE', 'כניסה דרך משרד החינוך'),
    'label_ar' => (string) env('SSO_LABEL_AR', 'الدخول عبر وزارة التربية والتعليم'),
];
