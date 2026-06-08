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
 * This is intentionally dependency-light (no DB table) — it rides on existing message
 * metadata and Laravel's APP_KEY.
 */
final class PendingActionToken
{
    /**
     * @param string $actionKey   The action key to execute on approval.
     * @param string|null $entityType  Entity label (for entity:// actions) or null.
     * @param array<string, mixed> $input  Schema-validated params.
     * @param int|string|null $userId  The user this token is bound to.
     * @param int $issuedAt     Unix timestamp the token was issued.
     */
    public function __construct(
        public readonly string $actionKey,
        public readonly ?string $entityType,
        public readonly array $input,
        public readonly int|string|null $userId,
        public readonly int $issuedAt,
    ) {
    }

    /**
     * Create a token bound to a user (timestamped now).
     *
     * @param array<string, mixed> $input
     */
    public static function issue(
        string $actionKey,
        ?string $entityType,
        array $input,
        int|string|null $userId
    ): self {
        return new self($actionKey, $entityType, $input, $userId, time());
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
        ];
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

        return new self(
            (string) $payload['k'],
            isset($payload['e']) ? ($payload['e'] !== null ? (string) $payload['e'] : null) : null,
            (array) ($payload['i'] ?? []),
            $payload['u'] ?? null,
            (int) $payload['t'],
        );
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
