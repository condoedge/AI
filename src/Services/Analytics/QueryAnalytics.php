<?php
// src/Services/Analytics/QueryAnalytics.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Analytics;

use Condoedge\Ai\Models\AiQueryLog;
use Illuminate\Support\Facades\DB;

class QueryAnalytics
{
    public function getSuccessRate(int $days = 7): float
    {
        $stats = AiQueryLog::where('created_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->first();

        return $stats->total > 0 ? ($stats->successful / $stats->total) * 100 : 0;
    }

    public function getAverageExecutionTime(int $days = 7): float
    {
        return AiQueryLog::where('created_at', '>=', now()->subDays($days))
            ->where('status', 'success')
            ->avg('execution_time_ms') ?? 0;
    }

    public function getMostFailedQuestions(int $limit = 10): array
    {
        return AiQueryLog::where('status', 'failed')
            ->select('question', DB::raw('COUNT(*) as failure_count'))
            ->groupBy('question')
            ->orderByDesc('failure_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getTemplateUsage(int $days = 7): array
    {
        return AiQueryLog::where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('template_used')
            ->select('template_used', DB::raw('COUNT(*) as count'))
            ->groupBy('template_used')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    public function getDashboardStats(): array
    {
        return [
            'success_rate_7d' => $this->getSuccessRate(7),
            'avg_execution_time_7d' => $this->getAverageExecutionTime(7),
            'total_queries_today' => AiQueryLog::whereDate('created_at', today())->count(),
            'failed_queries_today' => AiQueryLog::whereDate('created_at', today())
                ->where('status', 'failed')
                ->count(),
        ];
    }
}
