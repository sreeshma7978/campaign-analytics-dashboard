<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronously upsert campaign_stats after a bulk ingestion.
 *
 * Dispatched by AnalyticsService::ingestBulk() — runs outside the
 * hot HTTP path so it never adds latency to the ingestion endpoint.
 */
class UpdateCampaignStatsBulk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5; // seconds between retries

    public function __construct(
        private readonly string $campaignId,
        private readonly array  $stats,       // ['sent'=>N, 'opened'=>N, ...]
        private readonly string $lastEventAt, // datetime string
    ) {
    }

    public function handle(): void
    {
        DB::statement(
            <<<SQL
            INSERT INTO campaign_stats
                (campaign_id, sent, opened, clicked, bounced, last_event_at, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                sent         = sent         + VALUES(sent),
                opened       = opened       + VALUES(opened),
                clicked      = clicked      + VALUES(clicked),
                bounced      = bounced      + VALUES(bounced),
                last_event_at = GREATEST(
                    COALESCE(last_event_at, VALUES(last_event_at)),
                    VALUES(last_event_at)
                ),
                updated_at   = NOW()
            SQL,
            [
                $this->campaignId,
                $this->stats['sent']    ?? 0,
                $this->stats['opened']  ?? 0,
                $this->stats['clicked'] ?? 0,
                $this->stats['bounced'] ?? 0,
                $this->lastEventAt,
            ]
        );

        Log::info('UpdateCampaignStatsBulk completed', [
            'campaign_id' => $this->campaignId,
            'stats'       => $this->stats,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateCampaignStatsBulk failed', [
            'campaign_id' => $this->campaignId,
            'error'       => $exception->getMessage(),
        ]);
    }
}