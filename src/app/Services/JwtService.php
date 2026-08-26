<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class JwtService
{
    protected string $secret;
    protected string $issuer;
    protected string $audience;
    protected int $accessTtl;
    protected int $refreshTtl;

    public function __construct()
    {
        $this->secret     = config('app.jwt_secret', env('JWT_SECRET'));
        $this->issuer     = env('JWT_ISSUER', 'goldenpath-admin-api');
        $this->audience   = env('JWT_AUDIENCE', 'goldenpath-admin-panel');
        $this->accessTtl  = (int) env('JWT_ACCESS_TTL', 1800);
        $this->refreshTtl = (int) env('JWT_REFRESH_TTL', 2592000);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public function createAccessToken(array $claims): string
    {
        $now = CarbonImmutable::now();

        $payload = array_merge([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->addSeconds($this->accessTtl)->getTimestamp(),
            'type'=> 'access',
        ], $claims);

        return $this->encode($payload);
    }

    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$head64, $payload64, $sig64] = $parts;
        $signature = $this->base64UrlDecode($sig64);
        $data = $head64 . '.' . $payload64;

        $expected = hash_hmac('sha256', $data, $this->secret, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $header  = json_decode($this->base64UrlDecode($head64), true);
        $payload = json_decode($this->base64UrlDecode($payload64), true);

        if (!is_array($payload)) {
            return null;
        }

        $now = time();

        if (($payload['iss'] ?? null) !== $this->issuer) {
            return null;
        }
        if (($payload['aud'] ?? null) !== $this->audience) {
            return null;
        }
        if (($payload['nbf'] ?? 0) > $now) {
            return null;
        }
        if (($payload['exp'] ?? 0) < $now) {
            return null;
        }

        return $payload;
    }

    protected function encode(array $payload): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $head64    = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE));
        $payload64 = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $data = $head64 . '.' . $payload64;
        $sig  = hash_hmac('sha256', $data, $this->secret, true);
        $sig64 = $this->base64UrlEncode($sig);

        return $data . '.' . $sig64;
    }
}
