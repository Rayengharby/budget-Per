<?php
// ── JWT helpers (no external library) ─────────────────────
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'change_this_secret_in_production_32chars');
define('JWT_TTL',    7 * 24 * 3600); // 7 days

function _b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _b64url_decode(string $data): string {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

function jwt_sign(array $payload): string {
    $header    = _b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_TTL;
    $payload   = _b64url_encode(json_encode($payload));
    $signature = _b64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

function jwt_verify(string $token): array|false {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    [$header, $payload, $sig] = $parts;
    $expected = _b64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return false;

    $data = json_decode(_b64url_decode($payload), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return false;

    return $data;
}
