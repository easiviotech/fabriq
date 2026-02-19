<?php

declare(strict_types=1);

namespace Fabriq\Security;

/**
 * JWT authenticator — encode and decode HS256 JSON Web Tokens.
 *
 * Pure PHP implementation (no external libraries) for Swoole compatibility.
 * Validates signature, exp, and extracts claims (tenant_id, sub/actor_id, roles).
 */
final class JwtAuthenticator
{
    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'HS256',
        private readonly int $defaultTtl = 3600,
    ) {}

    /**
     * Encode claims into a JWT token.
     *
     * @param array<string, mixed> $claims  Must include 'sub' (actor_id)
     * @param int|null $ttl  Override default TTL
     * @return string Encoded JWT
     */
    public function encode(array $claims, ?int $ttl = null): string
    {
        $header = ['alg' => $this->algorithm, 'typ' => 'JWT'];

        $now = time();
        $claims['iat'] = $claims['iat'] ?? $now;
        $claims['exp'] = $claims['exp'] ?? ($now + ($ttl ?? $this->defaultTtl));

        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $segments[] = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

        $signingInput = implode('.', $segments);
        $signature = $this->sign($signingInput);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Decode and validate a JWT token.
     *
     * @return array<string, mixed>|null  Decoded claims, or null if invalid
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify signature
        $signingInput = "{$headerB64}.{$payloadB64}";
        $expectedSignature = self::base64UrlEncode($this->sign($signingInput));

        if (!hash_equals($expectedSignature, $signatureB64)) {
            return null;
        }

        // Decode header
        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== $this->algorithm) {
            return null;
        }

        // Decode claims
        $claims = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($claims)) {
            return null;
        }

        // Check expiration
        $exp = $claims['exp'] ?? 0;
        if ($exp > 0 && $exp < time()) {
            return null;
        }

        return $claims;
    }

    /**
     * Create the HMAC signature.
     */
    private function sign(string $input): string
    {
        return match ($this->algorithm) {
            'HS256' => hash_hmac('sha256', $input, $this->secret, true),
            'HS384' => hash_hmac('sha384', $input, $this->secret, true),
            'HS512' => hash_hmac('sha512', $input, $this->secret, true),
            default => throw new \RuntimeException("Unsupported algorithm: {$this->algorithm}"),
        };
    }

    /**
     * URL-safe Base64 encode.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe Base64 decode.
     */
    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}

