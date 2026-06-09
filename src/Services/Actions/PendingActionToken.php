<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

/**
 * PendingActionToken
 *
 * A signed, tamper-evident token that carries a deferred mutating action across the
 * approval round-trip. When the assistant proposes an APPROVAL-REQUIRED action we:
 *   1. Validate the input against the action's inputSchema (drop forbidden params),
 *   2. Bind it to the acting user + action key,
 *   3. Sign the payload (HMAC over the JSON, keyed by APP_KEY) so the client cannot
 *      tamper with the action key, params, or user binding,
 *   4. Persist the token on the message metadata.
 *
 * On approval, the token is decoded, the signature re-verified, and the action's
 * authorize() is re-run server-side BEFORE handle() executes — so a stale or tampered
 * token, or a user who lost permission, is rejected. Nothing mutating ever runs inline.
 *
 * Beyond tamper-evidence, the token carries two replay defenses the HMAC alone cannot give:
 *   - TTL: decode() rejects a token older than config('ai.actions.token_ttl'), so a captured
 *     token is not valid forever (the signature stays valid until APP_KEY rotates).
 *   - Single-use nonce: each token carries a unique nonce the dispatcher consumes on confirm,
 *     so a non-idempotent mutation cannot be fired twice by replaying the same token.
 *
 * This is intentionally dependency-light (no DB table) — it rides on existing message
 * metadata and Laravel's APP_KEY.
 */
final class PendingActionToken
{
    /** Fallback TTL (seconds) when config('ai.actions.token_ttl') is unavailable. */
    private const DEFAULT_TTL = 900;

    /**
     * @param string $actionKey   The action key to execute on approval.
     * @param string|null $entityType  Entity label (for entity:// actions) or null.
     * @param array<string, mixed> $input  Schema-validated params.
     * @param int|string|null $userId  The user this token is bound to.
     * @param int $issuedAt     Unix timestamp the token was issued.
     * @param string $nonce     Per-token id that makes the token single-use (consumed on approve).
     */
    public function __construct(
        public readonly string $actionKey,
        public readonly ?string $entityType,
        public readonly array $input,
        public readonly int|string|null $userId,
        public readonly int $issuedAt,
        public readonly string $nonce = '',
    ) {
    }

    /**
     * Create a token bound to a user (timestamped now), with a fresh single-use nonce.
     *
     * @param array<string, mixed> $input
     */
    public static function issue(
        string $actionKey,
        ?string $entityType,
        array $input,
        int|string|null $userId
    ): self {
        return new self($actionKey, $entityType, $input, $userId, time(), self::newNonce());
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'k' => $this->actionKey,
            'e' => $this->entityType,
            'i' => $this->input,
            'u' => $this->userId,
            't' => $this->issuedAt,
            'n' => $this->nonce,
        ];
    }

    /**
     * Whether the token has aged past the configured TTL. Defense against replay of a captured
     * token long after issue — the HMAC alone keeps a token valid until APP_KEY rotates.
     */
    public function isExpired(?int $now = null): bool
    {
        $now = $now ?? time();

        return ($now - $this->issuedAt) > self::ttl();
    }

    /**
     * Encode + sign the token into an opaque string.
     */
    public function encode(?string $secret = null): string
    {
        $secret = $secret ?? self::secret();
        $json = json_encode($this->toPayload(), JSON_UNESCAPED_SLASHES) ?: '{}';
        $b64 = self::base64UrlEncode($json);
        $sig = self::sign($b64, $secret);

        return $b64 . '.' . $sig;
    }

    /**
     * Decode + verify a token string. Returns null if the signature is invalid or the
     * payload is malformed (fail-closed).
     */
    public static function decode(string $token, ?string $secret = null): ?self
    {
        $secret = $secret ?? self::secret();

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$b64, $sig] = $parts;

        $expected = self::sign($b64, $secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $json = self::base64UrlDecode($b64);
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['k'], $payload['t'])) {
            return null;
        }

        $decoded = new self(
            (string) $payload['k'],
            isset($payload['e']) ? ($payload['e'] !== null ? (string) $payload['e'] : null) : null,
            (array) ($payload['i'] ?? []),
            $payload['u'] ?? null,
            (int) $payload['t'],
            isset($payload['n']) ? (string) $payload['n'] : '',
        );

        // Fail closed on an aged token even though its signature is intact.
        if ($decoded->isExpired()) {
            return null;
        }

        return $decoded;
    }

    /**
     * Whether the token belongs to the given user (defense-in-depth against replay by a
     * different session). A null binding matches anyone (e.g. unauthenticated test context).
     */
    public function belongsTo(int|string|null $userId): bool
    {
        if ($this->userId === null) {
            return true;
        }

        return (string) $this->userId === (string) $userId;
    }

    /**
     * Max token age in seconds. Reads config('ai.actions.token_ttl') when a container is booted,
     * falling back to a sane default so pure unit tests (no container) still enforce a TTL — same
     * container-tolerant guard the secret() resolver uses.
     */
    private static function ttl(): int
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    $ttl = config('ai.actions.token_ttl');
                    if (is_int($ttl) && $ttl > 0) {
                        return $ttl;
                    }
                    if (is_string($ttl) && ctype_digit($ttl) && (int) $ttl > 0) {
                        return (int) $ttl;
                    }
                }
            } catch (\Throwable $e) {
                // Container not available — fall through to default.
            }
        }

        return self::DEFAULT_TTL;
    }

    private static function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * How long a consumed-nonce record should be retained. The nonce only needs to outlive the
     * token's own TTL — past that, decode() already rejects the token, so single-use is moot.
     */
    public static function nonceLifetime(): int
    {
        return self::ttl() + 60;
    }

    private static function sign(string $data, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    private static function secret(): string
    {
        // Prefer the framework APP_KEY; fall back to a stable per-process secret so pure
        // unit tests (no booted Laravel container) still round-trip. The config() call is
        // guarded because the helper exists even when the container isn't bound.
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    $key = config('app.key');
                    if (is_string($key) && $key !== '') {
                        return $key;
                    }
                }
            } catch (\Throwable $e) {
                // Container not available — fall through to env/default.
            }
        }

        $env = getenv('APP_KEY');
        return is_string($env) && $env !== '' ? $env : 'condoedge-ai-pending-action';
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, (int) (ceil(strlen($data) / 4) * 4), '=', STR_PAD_RIGHT);
        return base64_decode(strtr($padded, '-_', '+/')) ?: '';
    }
}
