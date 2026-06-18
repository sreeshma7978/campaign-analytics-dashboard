<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CampaignAnalyticsService;
use App\Enums\EngagementEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEngagementEventRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class EventIngestionController extends Controller
{
    public function __construct(private readonly CampaignAnalyticsService $analytics) {}

    public function store(StoreEngagementEventRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $inserted = $this->analytics->ingest(
            eventId: $validated['event_id'],
            campaignId: $validated['campaign_id'],
            type: EngagementEventType::from($validated['type']),
            occurredAt: CarbonImmutable::parse($validated['timestamp'])->utc(),
        );

        return response()->json([
            'accepted' => true,
            'duplicate' => ! $inserted,
        ], $inserted ? 202 : 200);
    }
}
