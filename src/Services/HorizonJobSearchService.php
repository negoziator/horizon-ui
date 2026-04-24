<?php

namespace Negoziator\HorizonUi\Services;

use Laravel\Horizon\Contracts\JobRepository;

class HorizonJobSearchService
{
    // Horizon's getJobsByType always returns 50 items per page (hardcoded in RedisJobRepository).
    private const HORIZON_PAGE_SIZE = 50;

    private const MAX_LIMIT = 100;

    public function __construct(protected JobRepository $jobs) {}

    public function search(
        string $query,
        string $type = 'recent',
        ?string $queue = null,
        int $limit = 25,
        int $cursor = 0,
    ): array {
        $limit = min($limit, self::MAX_LIMIT);
        $query = mb_strtolower(trim($query));
        $method = $this->resolveMethod($type);
        $scanLimit = (int) config('horizon-ui.search.scan_limit', 1000);

        $results = [];
        // $cursor is a 0-based position in the Redis sorted set.
        // Horizon's $afterIndex is cursor - 1 (null when cursor === 0).
        $position = $cursor;
        $totalScanned = 0;
        $exhausted = false;

        while (count($results) < $limit && $totalScanned < $scanLimit) {
            $afterIndex = $position === 0 ? null : $position - 1;
            $batch = collect($this->jobs->{$method}($afterIndex));

            if ($batch->isEmpty()) {
                $exhausted = true;
                break;
            }

            foreach ($batch as $job) {
                $totalScanned++;
                if ($this->matches($job, $query, $queue)) {
                    $results[] = $this->formatJob($job);
                }
                if (count($results) >= $limit) {
                    break;
                }
            }

            $position += self::HORIZON_PAGE_SIZE;
        }

        return [
            'data' => $results,
            'next_cursor' => $exhausted ? null : $position,
            'total_scanned' => $totalScanned,
            'query' => $query,
        ];
    }

    private function resolveMethod(string $type): string
    {
        return match ($type) {
            'failed' => 'getFailed',
            'pending' => 'getPending',
            'completed' => 'getCompleted',
            default => 'getRecent',
        };
    }

    private function matches(object $job, string $query, ?string $queue): bool
    {
        if ($queue !== null && ($job->queue ?? '') !== $queue) {
            return false;
        }

        $haystack = implode(' ', array_filter([
            mb_strtolower($job->name ?? ''),
            mb_strtolower($job->displayName ?? ''),
            mb_strtolower($job->queue ?? ''),
            mb_strtolower($job->tags ?? ''),
            mb_strtolower($this->extractPayloadText($job->payload ?? '')),
        ]));

        return str_contains($haystack, $query);
    }

    private function extractPayloadText(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $decoded = json_decode($payload, associative: true);

        if (! is_array($decoded)) {
            return $payload;
        }

        return $this->flattenToString($decoded);
    }

    private function flattenToString(mixed $value, int $depth = 0): string
    {
        if ($depth > 4) {
            return '';
        }

        if (is_array($value)) {
            return implode(' ', array_map(
                fn ($v) => $this->flattenToString($v, $depth + 1),
                $value,
            ));
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function formatJob(object $job): array
    {
        $payload = json_decode($job->payload ?? '{}', associative: true) ?? [];

        return [
            'id' => $job->id,
            'name' => $job->displayName ?? $job->name ?? 'Unknown',
            'queue' => $job->queue ?? 'default',
            'status' => $job->status ?? 'unknown',
            'payload' => $payload['data']['command'] ?? $payload,
            'tags' => $job->tags ?? '',
            'failed_at' => $job->failed_at ?? null,
            'reserved_at' => $job->reserved_at ?? null,
            'created_at' => $job->pushedAt ?? null,
        ];
    }
}
