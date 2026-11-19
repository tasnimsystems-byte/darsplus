<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * The LiveKit media server: access tokens for the browsers (a JWT signed with the API secret, HS256)
 * and the room service this server calls to moderate (mute, remove, permissions, end the room).
 * Tests replace the configuration and the HTTP transport through override().
 */
final class LiveKit
{
    private static ?array $config = null;
    /** @var callable|null fn(string $url, array $headers, string $body): array{0: int, 1: string} */
    private static $transport = null;

    public static function override(?array $config, ?callable $transport = null): void
    {
        self::$config = $config;
        self::$transport = $transport;
    }

    public static function config(): array
    {
        return self::$config ?? (array) App::config('livekit', []);
    }

    public static function enabled(): bool
    {
        $c = self::config();
        return !empty($c['enabled']) && ($c['url'] ?? '') !== '' && ($c['api_key'] ?? '') !== '' && ($c['api_secret'] ?? '') !== '';
    }

    /** The browser's address of the server (ws:// or wss://). */
    public static function url(): string
    {
        return (string) (self::config()['url'] ?? '');
    }

    /** The server's address over http(s), for the room service. */
    public static function apiUrl(): string
    {
        $c = self::config();
        if (($c['api_url'] ?? '') !== '') {
            return (string) $c['api_url'];
        }
        return (string) preg_replace('#^ws(s?)://#', 'http$1://', self::url());
    }

    /** Origins a meeting page may connect to (Content-Security-Policy connect-src). */
    public static function connectSources(): array
    {
        $sources = [];
        foreach ([self::url(), self::apiUrl()] as $address) {
            $parts = parse_url($address);
            if (!isset($parts['scheme'], $parts['host'])) {
                continue;
            }
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $secure = in_array($parts['scheme'], ['wss', 'https'], true);
            $sources[] = ($secure ? 'wss://' : 'ws://') . $host;
            $sources[] = ($secure ? 'https://' : 'http://') . $host;
        }
        return array_values(array_unique($sources));
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * An access token for one person in one room.
     *
     * @param array $grant the video grant (room, roomJoin, canPublish, canPublishSources, roomAdmin, …)
     */
    public static function token(string $identity, string $name, array $grant, array $metadata = [], ?int $minutes = null): string
    {
        $c = self::config();
        $now = time();
        $claims = [
            'iss' => (string) $c['api_key'], 'sub' => $identity, 'name' => $name,
            'nbf' => $now - 10, 'iat' => $now, 'exp' => $now + 60 * ($minutes ?? max(5, (int) ($c['token_minutes'] ?? 120))),
            'jti' => bin2hex(random_bytes(8)), 'video' => $grant,
        ];
        if ($metadata !== []) {
            $claims['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }
        $head = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::base64url(json_encode($claims, JSON_UNESCAPED_UNICODE));
        return "$head.$body." . self::base64url(hash_hmac('sha256', "$head.$body", (string) $c['api_secret'], true));
    }

    /**
     * Calls the room service (Twirp over JSON) as an administrator of that room.
     *
     * @throws RuntimeException when the server cannot be reached or refuses
     */
    public static function room(string $method, string $room, array $body = []): array
    {
        $token = self::token('darsplus-server', 'DarsPlus', ['room' => $room, 'roomAdmin' => true, 'roomCreate' => true, 'roomList' => true], [], 5);
        $url = self::apiUrl() . '/twirp/livekit.RoomService/' . $method;
        $payload = json_encode(['room' => $room] + $body, JSON_UNESCAPED_UNICODE);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
        [$status, $response] = self::$transport !== null
            ? (self::$transport)($url, $headers, $payload)
            : self::send($url, $headers, $payload);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("LiveKit $method: HTTP $status");
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    /** @return array{0: int, 1: string} */
    private static function send(string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($response === false) {
                throw new RuntimeException('LiveKit: the server cannot be reached');
            }
            return [$status, (string) $response];
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $body,
            'timeout' => 10, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('LiveKit: the server cannot be reached');
        }
        $status = preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        return [$status, $response];
    }

    /** The participants now in a room: identity => list of their published tracks [sid, source]. */
    public static function participants(string $room): array
    {
        $out = [];
        foreach ((array) (self::room('ListParticipants', $room)['participants'] ?? []) as $p) {
            $tracks = [];
            foreach ((array) ($p['tracks'] ?? []) as $t) {
                $tracks[] = ['sid' => (string) ($t['sid'] ?? ''), 'source' => self::source($t['source'] ?? '', $t['type'] ?? '')];
            }
            $out[(string) ($p['identity'] ?? '')] = $tracks;
        }
        return $out;
    }

    /** A track source as a lower-case name, from the enum name or number the server sends. */
    private static function source(mixed $source, mixed $type): string
    {
        $names = [0 => 'unknown', 1 => 'camera', 2 => 'microphone', 3 => 'screen_share', 4 => 'screen_share_audio'];
        $name = is_int($source) ? ($names[$source] ?? 'unknown') : strtolower((string) $source);
        if ($name === 'unknown' || $name === '') {
            $name = in_array($type, [0, 'AUDIO', 'audio'], true) ? 'microphone' : 'unknown';
        }
        return $name;
    }
}
