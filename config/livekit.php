<?php

declare(strict_types=1);

/**
 * Online lessons: sound, video and screen sharing go through a LiveKit media server (open source,
 * https://livekit.io). This application never carries media; it decides who may enter a lesson's
 * room and signs a short-lived access token for them. Off until LIVEKIT_ENABLED=true and every value
 * below is filled. See docs/online-lessons.md.
 */
return [
    'enabled' => (bool) env('LIVEKIT_ENABLED', false),
    // Address the browsers connect to, e.g. wss://live.school.org.il (ws://localhost:7880 in development).
    'url' => rtrim((string) env('LIVEKIT_URL', ''), '/'),
    // Address this server calls for moderation (mute, remove, end); defaults to the same host over http(s).
    'api_url' => rtrim((string) env('LIVEKIT_API_URL', ''), '/'),
    'api_key' => (string) env('LIVEKIT_API_KEY', ''),
    'api_secret' => (string) env('LIVEKIT_API_SECRET', ''),
    // Lifetime of an access token, in minutes: long enough for a lesson and its reconnections.
    'token_minutes' => (int) env('LIVEKIT_TOKEN_MINUTES', 120),
];
