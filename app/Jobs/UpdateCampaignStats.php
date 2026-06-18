<?php

namespace App\Jobs;

use App\Contracts\CampaignAnalyticsService;
use App\Enums\EngagementEventType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateCampaignStats implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $campaignId,
        public readonly EngagementEventType $type,
        public readonly CarbonImmutable $occurredAt,
    ) {
        $this->onQueue('analytics');
    }

    public function backoff(): array
    {
        return [1, 5, 15, 60];
    }

    public function handle(CampaignAnalyticsService $analytics): void
    {
        $analytics->incrementStats($this->campaignId, $this->type, $this->occurredAt);
    }
}
