<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ingests_an_event_and_updates_campaign_stats(): void
    {
        $response = $this->postJson('/api/events', [
            'event_id' => 'evt-1',
            'campaign_id' => 'camp-1',
            'type' => 'opened',
            'timestamp' => '2026-06-17T10:00:00Z',
        ]);

        $response
            ->assertAccepted()
            ->assertJson([
                'accepted' => true,
                'duplicate' => false,
            ]);

        $this->assertDatabaseHas(CampaignEvent::class, [
            'event_id' => 'evt-1',
            'campaign_id' => 'camp-1',
            'type' => 'opened',
        ]);

        $this->assertDatabaseHas(Campaign::class, [
            'id' => 'camp-1',
            'name' => 'camp-1',
        ]);

        $this->assertDatabaseHas(CampaignStat::class, [
            'campaign_id' => 'camp-1',
            'sent' => 0,
            'opened' => 1,
            'clicked' => 0,
            'bounced' => 0,
        ]);
    }

    public function test_duplicate_event_ids_are_not_counted_twice(): void
    {
        $payload = [
            'event_id' => 'evt-duplicate',
            'campaign_id' => 'camp-1',
            'type' => 'clicked',
            'timestamp' => '2026-06-17T10:00:00Z',
        ];

        $this->postJson('/api/events', $payload)->assertAccepted();
        $this->postJson('/api/events', $payload)
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'duplicate' => true,
            ]);

        $this->assertSame(1, CampaignEvent::query()->where('event_id', 'evt-duplicate')->count());

        $this->getJson('/api/campaigns/camp-1/stats')
            ->assertOk()
            ->assertExactJson([
                'sent' => 0,
                'opened' => 0,
                'clicked' => 1,
                'bounced' => 0,
            ]);
    }

    public function test_stats_return_zeroes_for_empty_campaigns(): void
    {
        $this->getJson('/api/campaigns/unknown-campaign/stats')
            ->assertOk()
            ->assertExactJson([
                'sent' => 0,
                'opened' => 0,
                'clicked' => 0,
                'bounced' => 0,
            ]);
    }

    public function test_it_validates_event_payloads(): void
    {
        $this->postJson('/api/events', [
            'event_id' => '',
            'campaign_id' => 'camp-1',
            'type' => 'delivered',
            'timestamp' => 'not-a-date',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['event_id', 'type', 'timestamp']);
    }

    public function test_it_lists_known_campaigns_for_the_dashboard(): void
    {
        Campaign::query()->create([
            'id' => 'camp-visible',
            'name' => 'June Launch',
        ]);

        Campaign::query()->create([
            'id' => 'camp-deleted',
            'name' => 'Archived Campaign',
        ])->delete();

        $this->getJson('/api/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'camp-visible')
            ->assertJsonMissing([
                'id' => 'camp-deleted',
            ]);
    }
}
