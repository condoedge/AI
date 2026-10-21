<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin\Concerns;

use Carbon\Carbon;
use Condoedge\Ai\Kompo\Admin\AdminTranscriptModal;
use Condoedge\Ai\Services\Admin\InsightsRange;
use Illuminate\Support\Str;

/**
 * Small, SAFE display helpers shared by the admin insights components.
 *
 * Message content, titles, user / team names and file names are DATA (model output can
 * carry document text): they only ever reach the page through text() — HTML-escaped,
 * and bypassing the label translation Kompo applies to element labels.
 */
trait RendersInsightCells
{
    /** Escaped text in an Html element (Kompo renders Html labels as HTML). */
    protected function text(mixed $value, string $class = ''): \Kompo\Html
    {
        $element = _Html();
        $element->label = e((string) ($value ?? ''));

        return $class !== '' ? $element->class($class) : $element;
    }

    protected function excerpt(mixed $value, int $limit): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? ''), $limit);
    }

    protected function dateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    protected function latency(int|float|null $ms): string
    {
        if ($ms === null) {
            return '—';
        }

        return $ms < 1000 ? ((int) round($ms)) . ' ms' : number_format($ms / 1000, 1) . ' s';
    }

    protected function feedbackBadge(?string $feedback): \Kompo\Html
    {
        return match ($feedback) {
            'positive' => _Html('ai.admin.feedback.positive')->class('inline-block whitespace-nowrap rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800'),
            'negative' => _Html('ai.admin.feedback.negative')->class('inline-block whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800'),
            default => _Html(),
        };
    }

    protected function routeBadge(?string $route, bool $isError = false): \Kompo\Html
    {
        $key = 'ai.admin.route.' . (string) $route;
        $label = $route === null ? '—' : (__($key) !== $key ? __($key) : $route);

        return $this->text($label, 'inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium ' . ($isError || $route === 'error'
            ? 'bg-red-100 text-red-800'
            : 'bg-gray-100 text-gray-700'));
    }

    protected function rangeFromStore(): InsightsRange
    {
        return InsightsRange::fromInput($this->store('days'), $this->store('from'), $this->store('to'));
    }

    /**
     * The read-only transcript, opened from a row (selfGet payload: conversation = uuid —
     * ids are never accepted —, message = the answer to highlight). Read by name from the
     * request: Kompo passes the whole request positionally.
     */
    public function openTranscript()
    {
        $conversation = request('conversation');
        $message = request('message');

        return new AdminTranscriptModal([
            'conversation' => is_string($conversation) ? $conversation : '',
            'message' => is_numeric($message) ? (int) $message : null,
        ]);
    }
}
