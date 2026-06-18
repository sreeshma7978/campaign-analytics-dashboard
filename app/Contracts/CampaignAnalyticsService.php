<?php

namespace App\Contracts;

use App\Enums\EngagementEventType;
use Carbon\CarbonImmutable;

interface CampaignAnalyticsService
{
    /**
     * Returns true when this event is new and false when it was already seen.
     */
    public function ingest(
        string $eventId,
        string $campaignId,
        EngagementEventType $type,
        CarbonImmutable $occurredAt,
    ): bool;

    /**
     * @return array{sent: int, opened: int, clicked: int, bounced: int}
     */
    public function statsForCampaign(string $campaignId): array;

    public function incrementStats(string $campaignId, EngagementEventType $type, CarbonImmutable $occurredAt): void;
}
