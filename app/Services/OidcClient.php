<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * A small OpenID Connect client (authorization code flow with PKCE), enough to sign in through an
 * identity provider such as the Ministry of Education's.
 *
 *  - The provider's endpoints come from its discovery document.
 *  - The ID token is verified here: RS256 signature against the provider's published keys, issuer,
 *    audience, expiry and the nonce of this sign-in. Nothing is trusted otherwise.
 *  - The person's identifier is reduced to a keyed fingerprint (HMAC-SHA256) before it is stored or compared.
 *
 * Failures are \DomainException carrying a translation key.
 */
final class OidcClient
{
    /** @var array|null settings that replace config/sso.php (tests) */
    private static ?array $override = null;
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} (tests) */
    private static $transport = null;

    private ?array $discovery = null;

    public static function override(?array $config, ?callable $transport = null): void
    {
        self::$override = $config;
        self::$transport = $transport;
    }

    public static function config(): array
    {
        return self::$override ?? (array) App::config('sso', []);
    }

    /** On only when every required value is set. */
    public static function enabled(): bool
    {
        $c = self::config();
        return !empty($c['enabled']) && ($c['issuer'] ?? '') !== '' && ($c['client_id'] ?? '') !== ''
            && ($c['redirect_uri'] ?? '') !== '' && mb_strlen((string) ($c['hash_key'] ?? '')) >= 24;
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unbase64url(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }

    /** The stored stand-in for the provider's identifier of a person. */
    public static function fingerprint(string $subject): string
    {
        return hash_hmac('sha256', $subject, (string) self::config()['hash_key']);
    }

    /** @return array{0:int,1:string} */
    private function http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        if (!str_starts_with($url, 'https://')) {
            throw new \DomainException('auth.sso.errors.provider');
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($response === false) {
            throw new \DomainException('auth.sso.errors.provider');
        }
        return [$status, (string) $response];
    }

    private function json(string $url): array
    {
        [$status, $body] = $this->http('GET', $url, ['Accept' => 'application/json']);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new \DomainException('auth.sso.errors.provider');
        }
        return $data;
    }

    public function discovery(): array
    {
        if ($this->discovery === null) {
            $config = self::config();
            $data = $this->json($config['issuer'] . '/.well-known/openid-configuration');
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                    throw new \DomainException('auth.sso.errors.provider');
                }
            }
            $this->discovery = $data;
        }
        return $this->discovery;
    }

    /** Where to send the browser: the provider's sign-in page for this attempt. */
    public function authorizationUrl(string $state, string $nonce, string $verifier): string
    {
        $config = self::config();
        $endpoint = $this->discovery()['authorization_endpoint'];
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code', 'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'],
            'scope' => $config['scopes'] ?: 'openid', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => self::base64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchanges the code for tokens and returns the verified claims of the ID token. */
    public function claims(string $code, string $verifier, string $nonce): array
    {
        $config = self::config();
        [$status, $body] = $this->http('POST', $this->discovery()['token_endpoint'], [
            'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json',
        ], http_build_query([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $config['redirect_uri'],
            'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'], 'code_verifier' => $verifier,
        ], '', '&', PHP_QUERY_RFC3986));
        $tokens = json_decode($body, true);
        if ($status !== 200 || !is_array($tokens) || !is_string($tokens['id_token'] ?? null)) {
            throw new \DomainException('auth.sso.errors.token');
        }
        return $this->verify($tokens['id_token'], $nonce);
    }

    /** Verifies a signed ID token (RS256 only) and returns its claims. */
    public function verify(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \DomainException('auth.sso.errors.token');
        }
        $header = json_decode((string) self::unbase64url($parts[0]), true);
        $claims = json_decode((string) self::unbase64url($parts[1]), true);
        $signature = self::unbase64url($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === false || ($header['alg'] ?? '') !== 'RS256') {
            throw new \DomainException('auth.sso.errors.token');
        }

        $key = null;
        foreach ($this->json($this->discovery()['jwks_uri'])['keys'] ?? [] as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? '') === 'RSA' && (!isset($header['kid']) || ($jwk['kid'] ?? null) === $header['kid'])
                && ($jwk['use'] ?? 'sig') === 'sig' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                $key = self::rsaPublicKey($jwk['n'], $jwk['e']);
                break;
            }
        }
        if ($key === null || openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \DomainException('auth.sso.errors.token');
        }

        $config = self::config();
        $audience = (array) ($claims['aud'] ?? []);
        $now = time();
        if (($claims['iss'] ?? null) !== $this->discovery()['issuer']
            || !in_array($config['client_id'], $audience, true)
            || (count($audience) > 1 && ($claims['azp'] ?? null) !== $config['client_id'])
            || !is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - 60
            || (isset($claims['iat']) && (int) $claims['iat'] > $now + 120)
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new \DomainException('auth.sso.errors.token');
        }
        return $claims;
    }

    /** The person's identifier from the verified claims (the configured claim). */
    public static function subject(array $claims): string
    {
        $value = $claims[self::config()['id_claim'] ?: 'sub'] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new \DomainException('auth.sso.errors.token');
        }
        return trim((string) $value);
    }

    /** PEM of an RSA public key given as a JWK (modulus n, exponent e). */
    public static function rsaPublicKey(string $n, string $e): ?\OpenSSLAsymmetricKey
    {
        $modulus = self::unbase64url($n);
        $exponent = self::unbase64url($e);
        if ($modulus === false || $exponent === false) {
            return null;
        }
        $der = static function (string $tag, string $value): string {
            $length = strlen($value);
            $encoded = $length < 128 ? chr($length) : (($bytes = ltrim(pack('N', $length), "\0")) !== '' ? chr(0x80 | strlen($bytes)) . $bytes : "\0");
            return $tag . $encoded . $value;
        };
        $integer = static fn (string $v): string => $der("\x02", (ord($v[0]) > 0x7f ? "\0" : '') . $v);
        $rsaKey = $der("\x30", $integer($modulus) . $integer($exponent));
        $algorithm = $der("\x30", "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = $der("\x30", $algorithm . $der("\x03", "\0" . $rsaKey));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem) ?: null;
    }
}
