<?php

namespace App\Services;

use App\Contracts\CampaignAnalyticsService;
use App\Enums\EngagementEventType;
use App\Jobs\UpdateCampaignStats;
use App\Jobs\UpdateCampaignStatsBulk;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DatabaseCampaignAnalyticsService implements CampaignAnalyticsService
{  
    private const BULK_CHUNK_SIZE = 5000;
    private const REDIS_BUFFER_KEY = 'events:pending';
    private const REDIS_BUFFER_THRESHOLD = 500; // flush when this many events are queued

     public function ingestBulk(array $events): bool
    {
        if (empty($events)) {
            return true;
        }
 
        $campaignId = $events[0]['campaign_id'];
 
        // ── 1. Ensure campaign row exists ONCE (not inside the per-event loop) ──
        DB::table('campaigns')->insertOrIgnore([
            'id'         => $campaignId,
            'name'       => $campaignId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
 
        $stats = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];
 
        try {
            // ── 2. Speed up bulk inserts by skipping per-row index/FK validation ──
            //    Safe because UUIDs are guaranteed unique at generation time.
            DB::statement('SET autocommit=0');
            DB::statement('SET unique_checks=0');
            DB::statement('SET foreign_key_checks=0');
 
            // ── 3. Chunk and insert ──
            foreach (array_chunk($events, self::BULK_CHUNK_SIZE) as $chunk) {
                DB::table('campaign_events')->insertOrIgnore($chunk);
 
                foreach ($chunk as $event) {
                    if (isset($stats[$event['type']])) {
                        $stats[$event['type']]++;
                    }
                }
            }
 
            // ── 4. Re-enable checks and commit ──
            DB::statement('COMMIT');
            DB::statement('SET autocommit=1');
            DB::statement('SET unique_checks=1');
            DB::statement('SET foreign_key_checks=1');
 
        } catch (\Throwable $e) {
            // Ensure MySQL session is restored even on failure
            DB::statement('ROLLBACK');
            DB::statement('SET autocommit=1');
            DB::statement('SET unique_checks=1');
            DB::statement('SET foreign_key_checks=1');
 
            Log::error('ingestBulk failed', [
                'campaign_id' => $campaignId,
                'error'       => $e->getMessage(),
            ]);
 
            throw $e;
        }
 
        // ── 5. Single async job to update campaign_stats ──
        UpdateCampaignStatsBulk::dispatch($campaignId, $stats, now()->toDateTimeString());
 
        return true;
    }

   
    public function ingest(
        string $eventId,
        string $campaignId,
        EngagementEventType $type,
        CarbonImmutable $occurredAt,
    ): bool {
        $inserted = DB::transaction(function () use ($eventId, $campaignId, $type, $occurredAt): bool {
            Campaign::query()->insertOrIgnore([
                'id' => $campaignId,
                'name' => $campaignId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rowsInserted = CampaignEvent::query()->insertOrIgnore([
                'event_id' => $eventId,
                'campaign_id' => $campaignId,
                'type' => $type->value,
                'occurred_at' => $occurredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($rowsInserted !== 1) {
                return false;
            }

            UpdateCampaignStats::dispatch($campaignId, $type, $occurredAt)->afterCommit();

            return true;
        });

        return $inserted;
    }

    public function statsForCampaign(string $campaignId): array
    {
        $stats = CampaignStat::query()->find($campaignId);

        return [
            'sent' => (int) ($stats?->sent ?? 0),
            'opened' => (int) ($stats?->opened ?? 0),
            'clicked' => (int) ($stats?->clicked ?? 0),
            'bounced' => (int) ($stats?->bounced ?? 0),
        ];
    }

    public function incrementStats(string $campaignId, EngagementEventType $type, CarbonImmutable $occurredAt): void
    {
        $column = $type->value;
        $timestamp = now()->toDateTimeString();
        $occurredAtValue = $occurredAt->toDateTimeString();

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(
                <<<SQL
                INSERT INTO campaign_stats
                    (campaign_id, sent, opened, clicked, bounced, last_event_at, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(campaign_id) DO UPDATE SET
                    {$column} = {$column} + 1,
                    last_event_at = max(coalesce(last_event_at, excluded.last_event_at), excluded.last_event_at),
                    updated_at = excluded.updated_at
                SQL,
                [
                    $campaignId,
                    $type === EngagementEventType::Sent ? 1 : 0,
                    $type === EngagementEventType::Opened ? 1 : 0,
                    $type === EngagementEventType::Clicked ? 1 : 0,
                    $type === EngagementEventType::Bounced ? 1 : 0,
                    $occurredAtValue,
                    $timestamp,
                    $timestamp,
                ],
            );

            return;
        }

        DB::statement(
            <<<SQL
            INSERT INTO campaign_stats
                (campaign_id, sent, opened, clicked, bounced, last_event_at, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                {$column} = {$column} + 1,
                last_event_at = GREATEST(COALESCE(last_event_at, VALUES(last_event_at)), VALUES(last_event_at)),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $campaignId,
                $type === EngagementEventType::Sent ? 1 : 0,
                $type === EngagementEventType::Opened ? 1 : 0,
                $type === EngagementEventType::Clicked ? 1 : 0,
                $type === EngagementEventType::Bounced ? 1 : 0,
                $occurredAtValue,
                $timestamp,
                $timestamp,
            ],
        );
    }
    public function getEventsForCampaign(string $campaignId, int $limit = 50): array
    {
        return DB::table('campaign_events')
            ->where('campaign_id', $campaignId)
            ->orderBy('occurred_at', 'desc')
            ->limit($limit)
            ->get(['event_id', 'type', 'occurred_at', 'created_at'])
            ->toArray();
    }
    public function ingestViaRedisBuffer(array $events): bool
    {
        foreach ($events as $event) {
            Redis::rpush(self::REDIS_BUFFER_KEY, json_encode($event));
        }
 
        // If the buffer is large enough, kick off a drain job immediately
        $buffered = Redis::llen(self::REDIS_BUFFER_KEY);
        if ($buffered >= self::REDIS_BUFFER_THRESHOLD) {
            \App\Jobs\DrainEventBuffer::dispatch();
        }
 
        return true;
    }
   
}
