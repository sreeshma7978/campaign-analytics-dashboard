<?php

namespace App\Services;

use App\Contracts\CampaignAnalyticsService;
use App\Enums\EngagementEventType;
use App\Jobs\UpdateCampaignStats;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DatabaseCampaignAnalyticsService implements CampaignAnalyticsService
{
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
}
