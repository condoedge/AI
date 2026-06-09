<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Results;

use Condoedge\Ai\Services\Actions\ActionKind;

/**
 * Immutable, typed result of running an AiAction.
 *
 * handle() returns DATA + INTENT, never a Kompo element. The {@see ActionKind} discriminates
 * what the payload means (element | table | export | pending | error), so one presenter can
 * turn it into a _Link / _Rows / download / ApprovalCard and consumers switch on $kind rather
 * than re-sniffing the payload's array shape.
 *
 * Payload by kind:
 *   - Element : mixed intent (e.g. ['route'=>..,'id'=>..] or a structured link descriptor).
 *   - Table   : ['headers'=>string[], 'rows'=>array<int,array<int,string>>].
 *   - Export  : ['content'=>bytes, 'mime'=>string, 'filename'=>string].
 *   - Pending : ['token'=>string,'action_key'=>string,'label'=>string,'text'=>string,
 *                'confirm_message'=>string].
 *   - Error   : null (only $summaryForLlm carries the message).
 *
 * $summaryForLlm is a short, ID-free line the LLM can use to acknowledge the action.
 */
final class AiActionResult
{
    public function __construct(
        public readonly ActionKind $kind,
        public readonly mixed $payload = null,
        public readonly string $summaryForLlm = '',
    ) {
    }

    /** Render-an-element intent (link, redirect, button, model reference). */
    public static function element(mixed $payload, string $summaryForLlm = ''): self
    {
        return new self(ActionKind::Element, $payload, $summaryForLlm);
    }

    /**
     * Render the current result set as a table.
     *
     * @param array{headers: array<int, string>, rows: array<int, array<int, string>>} $table
     */
    public static function table(array $table, string $summaryForLlm = ''): self
    {
        return new self(ActionKind::Table, $table, $summaryForLlm);
    }

    /** Offer a generated document as a download. */
    public static function export(string $content, string $mime, string $filename, string $summaryForLlm = ''): self
    {
        return new self(
            ActionKind::Export,
            ['content' => $content, 'mime' => $mime, 'filename' => $filename],
            $summaryForLlm,
        );
    }

    /**
     * A deferred mutating action awaiting confirmation. The payload carries the signed token +
     * the metadata the approval card renders.
     *
     * @param array<string, mixed> $meta  Extra payload (action_key, label, text).
     */
    public static function pending(string $token, string $confirmMessage, string $summaryForLlm = '', array $meta = []): self
    {
        return new self(
            ActionKind::Pending,
            array_merge($meta, ['token' => $token, 'confirm_message' => $confirmMessage]),
            $summaryForLlm,
        );
    }

    /** A graceful failure: the summary is shown, nothing actionable is rendered. */
    public static function error(string $summaryForLlm): self
    {
        return new self(ActionKind::Error, null, $summaryForLlm);
    }

    /**
     * Whether this result is a graceful error (derived from the kind — no separate flag to keep
     * in sync).
     */
    public function isError(): bool
    {
        return $this->kind === ActionKind::Error;
    }

    /**
     * Legacy renderable view of this result.
     *
     * The typed kind+payload is the canonical contract; this projection bridges consumers that
     * read the `renderable` boundary, deriving the array shapes they expect:
     *   - Element : the payload as-is (intent/element descriptor).
     *   - Table   : ['type'=>'table', ...payload] so consumers can sniff the shape.
     *   - Export  : ['content','mime','filename'] (already the payload).
     *   - Pending : ['pending'=>true, ...payload] (token + card metadata).
     *   - Error   : null (nothing renderable).
     */
    public function renderable(): mixed
    {
        return match ($this->kind) {
            ActionKind::Error => null,
            ActionKind::Table => array_merge(['type' => 'table'], is_array($this->payload) ? $this->payload : []),
            ActionKind::Pending => array_merge(['pending' => true], is_array($this->payload) ? $this->payload : []),
            default => $this->payload,
        };
    }

    /**
     * Read $result->isError / $result->renderable as properties for ergonomics, derived from the
     * typed kind+payload so there is no second field to keep in sync.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'isError' => $this->isError(),
            'renderable' => $this->renderable(),
            default => throw new \OutOfBoundsException("Undefined property: " . self::class . "::\${$name}"),
        };
    }
}
