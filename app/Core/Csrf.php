<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    public function verify(?string $candidate): bool
    {
        $token = $this->session->get('_csrf');
        return is_string($token) && $token !== '' && is_string($candidate) && hash_equals($token, $candidate);
    }
}
