<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Admin;

use Carbon\Carbon;

/**
 * The date range the admin conversation-insights page reports on: whole days, from
 * 00:00:00 of the first to 23:59:59 of the last, in the app timezone.
 *
 * Input is either a preset (`days` = 7 | 30 | 90 → the last N days including today)
 * or explicit `from` / `to` dates (Y-m-d, from <= to, at most MAX_DAYS). Anything else
 * falls back to the default preset, so a hand-edited URL can never produce an
 * unbounded scan.
 */
final class InsightsRange
{
    public const PRESETS = [7, 30, 90];
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        /** The preset in use, null for an explicit range. */
        public readonly ?int $days,
    ) {
    }

    public static function fromInput(mixed $days = null, mixed $from = null, mixed $to = null): self
    {
        $fromDate = self::parseDate($from);
        $toDate = self::parseDate($to);

        if ($fromDate && $toDate && $fromDate->lte($toDate) && $fromDate->diffInDays($toDate) < self::MAX_DAYS) {
            return new self($fromDate->startOfDay(), $toDate->endOfDay(), null);
        }

        return self::lastDays(in_array((int) $days, self::PRESETS, true) ? (int) $days : self::DEFAULT_DAYS);
    }

    public static function lastDays(int $days): self
    {
        $to = Carbon::now()->endOfDay();

        return new self(Carbon::now()->subDays($days - 1)->startOfDay(), $to, $days);
    }

    public function fromString(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    public function toString(): string
    {
        return $this->to->format('Y-m-d H:i:s');
    }

    /** @return array{days: ?int, from: string, to: string} what a child component needs to rebuild the range */
    public function toStore(): array
    {
        return [
            'days' => $this->days,
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
        ];
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        // A date picker may send "Y-m-d H:i:s" / ISO: only the leading date counts.
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T].*)?$/', $value, $match) !== 1) {
            return null;
        }
        $value = $match[1];

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
