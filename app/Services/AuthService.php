<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;

final class AuthService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';

    // Verified when the username does not exist, so both paths cost the same time.
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(private Database $db)
    {
    }

    /** @return array{0: string, 1: ?int} status and, on success, the user id */
    public function attempt(string $username, string $password, string $ip): array
    {
        $username = mb_strtolower(trim($username));
        if ($this->isLocked($username, $ip)) {
            return [self::LOCKED, null];
        }

        $user = $this->db->one(
            'SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1 AND deleted_at IS NULL',
            [$username]
        );
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH) && $user !== null;

        $this->db->insert('login_attempts', [
            'username' => mb_substr($username, 0, 60), 'ip_address' => $ip, 'success' => $valid ? 1 : 0,
        ]);

        if (!$valid) {
            AuditLogger::log('login_failed', 'user', $user['id'] ?? null, null, ['username' => mb_substr($username, 0, 60)]);
            return [self::INVALID, null];
        }

        $userId = (int) $user['id'];
        $update = ['last_login_at' => date('Y-m-d H:i:s')];
        if (Passwords::needsRehash($user['password_hash'])) {
            $update['password_hash'] = Passwords::hash($password);
        }
        $this->db->update('users', $update, ['id' => $userId]);
        AuditLogger::log('login', 'user', $userId, null, null, $userId);
        return [self::OK, $userId];
    }

    /**
     * Failed attempts inside the window, counted since the last successful login,
     * per username and (with a higher ceiling) per IP address.
     */
    public function isLocked(string $username, string $ip): bool
    {
        $config = App::config('app.login');
        $window = (int) $config['window_minutes'];

        $byUser = (int) $this->db->value(
            "SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND success = 0
                AND attempted_at > NOW() - INTERVAL $window MINUTE
                AND id > COALESCE((SELECT MAX(id) FROM login_attempts la2 WHERE la2.username = ? AND la2.success = 1), 0)",
            [$username, $username]
        );
        if ($byUser >= (int) $config['max_attempts_per_user']) {
            return true;
        }
        $byIp = (int) $this->db->value(
            "SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = ? AND success = 0 AND attempted_at > NOW() - INTERVAL $window MINUTE",
            [$ip]
        );
        return $byIp >= (int) $config['max_attempts_per_ip'];
    }
}
