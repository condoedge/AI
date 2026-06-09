<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiLogger
{
    private ?string $traceId = null;
    private bool $debugEnabled;
    private ?string $channel = null;
    private array $timers = [];

    public function __construct()
    {
        $this->debugEnabled = (bool) config('ai.debug', false);
        $this->channel = config('ai.log_channel', config('logging.default'));
    }

    public function startTrace(?string $label = null): string
    {
        $this->traceId = Str::ulid()->toBase32();
        $this->info('trace.start', $label ?? 'pipeline', []);
        return $this->traceId;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function startTimer(string $operation): void
    {
        $this->timers[$operation] = microtime(true);
    }

    public function stopTimer(string $operation): float
    {
        $start = $this->timers[$operation] ?? microtime(true);
        unset($this->timers[$operation]);
        return round((microtime(true) - $start) * 1000, 2);
    }

    public function info(string $service, string $message, array $context = []): void
    {
        $this->log('info', $service, $message, $context);
    }

    public function warning(string $service, string $message, array $context = []): void
    {
        $this->log('warning', $service, $message, $context);
    }

    public function error(string $service, string $message, array $context = []): void
    {
        $this->log('error', $service, $message, $context);
    }

    public function debug(string $service, string $message, array $context = []): void
    {
        if (!$this->debugEnabled) {
            return;
        }
        $this->log('debug', $service, $message, $context);
    }

    public function fallback(string $service, string $from, string $to, string $reason): void
    {
        $this->warning($service, "fallback: {$from} → {$to}", ['reason' => $reason]);
    }

    private function log(string $level, string $service, string $message, array $context): void
    {
        $entry = array_merge([
            'ai_trace_id' => $this->traceId,
            'ai_service' => $service,
        ], $context);

        Log::channel($this->channel)->{$level}("[AI] {$service}: {$message}", $entry);
    }
}
