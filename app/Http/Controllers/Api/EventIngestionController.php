<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CampaignAnalyticsService;
use App\Enums\EngagementEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEngagementEventRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    public function getEvents(Request $request, string $campaignId): JsonResponse
    {
        $limit = $request->query('limit', 50); // Default limit is 50
        $events = $this->analytics->getEventsForCampaign($campaignId, (int) $limit);

        return response()->json($events);
    }
    

    public function ingestBurst(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_id' => 'required|string',
            'event_total' => 'required|integer|min:1|max:20000',
            'mode'        => 'sometimes|string|in:sync,redis',
        ]);
 
        $campaignId = $validated['campaign_id'];
        $eventTotal = (int) $validated['event_total'];
        $mode       = $validated['mode'] ?? 'sync';
        $eventTypes = ['sent', 'opened', 'clicked', 'bounced'];
        $now        = now();
 
        // ── Build the events array ────────────────────────────────────────────
        // Pre-calculate timestamps to avoid calling now() 20 000 times.
        $events = [];
        for ($i = 0; $i < $eventTotal; $i++) {
            $events[] = [
                'event_id'    => (string) Str::uuid(),
                'campaign_id' => $campaignId,
                'type'        => $eventTypes[$i % count($eventTypes)],
                'occurred_at' => $now->subSeconds(random_int(0, 3600))->toDateTimeString(),
                'created_at'  => $now->toDateTimeString(),
                'updated_at'  => $now->toDateTimeString(),
            ];
        }
 
        // ── Choose ingestion strategy ─────────────────────────────────────────
        if ($mode === 'redis') {
            // Ultra-fast: push to Redis and return immediately (~5 ms).
            // A background worker drains events into MySQL asynchronously.
            $this->analytics->ingestViaRedisBuffer($events);
 
            return response()->json([
                'message'    => 'Events queued for processing.',
                'total'      => $eventTotal,
                'mode'       => 'redis',
                'campaign_id'=> $campaignId,
            ], 202);
        }
 
        // Default: direct bulk insert — target <1 s for 20 000 events.
        $start = microtime(true);
        $this->analytics->ingestBulk($events);
        $elapsed = round((microtime(true) - $start) * 1000); // ms
 
        return response()->json([
            'message'     => 'Burst events processed successfully.',
            'total'       => $eventTotal,
            'mode'        => 'sync',
            'campaign_id' => $campaignId,
            'elapsed_ms'  => $elapsed,
        ]);
    }

}
