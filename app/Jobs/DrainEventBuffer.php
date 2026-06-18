<?php

namespace App\Jobs;

use App\Services\AnalyticsService;
use App\Services\DatabaseCampaignAnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Drain the Redis event buffer into MySQL via AnalyticsService::ingestBulk().
 *
 * This job is the other half of the fire-and-forget Redis path.
 * It runs on a queue worker and is safe to run concurrently because
 * campaign_events uses INSERT IGNORE (duplicate event_ids are skipped).
 *
 * Scheduling suggestion (app/Console/Kernel.php):
 *   $schedule->job(new DrainEventBuffer)->everyThirtySeconds()->withoutOverlapping();
 *
 * Or dispatch it from AnalyticsService::ingestViaRedisBuffer() when
 * the buffer reaches a threshold (already done there).
 */
class DrainEventBuffer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * How many events to pull from Redis per drain run.
     * Keep this ≤ 20 000 to stay within ingestBulk's validated max.
     */
    private const DRAIN_BATCH = 20000;
    private const REDIS_KEY   = 'events:pending';

    public function handle(DatabaseCampaignAnalyticsService $analytics): void
    {
        // Atomically pop up to DRAIN_BATCH items
        $pipeline = Redis::pipeline(function ($pipe) {
            $pipe->lrange(self::REDIS_KEY, 0, self::DRAIN_BATCH - 1);
            $pipe->ltrim(self::REDIS_KEY, self::DRAIN_BATCH, -1);
        });

        $rawEvents = $pipeline[0] ?? [];

        if (empty($rawEvents)) {
            return;
        }

        $events = array_map(
            static fn (string $json): array => json_decode($json, true),
            $rawEvents
        );

        Log::info('DrainEventBuffer: draining', ['count' => count($events)]);

        $analytics->ingestBulk($events);

        // If there are still items in the buffer, dispatch another drain
        if (Redis::llen(self::REDIS_KEY) > 0) {
            self::dispatch();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DrainEventBuffer failed', ['error' => $exception->getMessage()]);
    }
}