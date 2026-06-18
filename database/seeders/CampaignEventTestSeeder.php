<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignEventTestSeeder extends Seeder
{
    public function run(): void
    {
        $campaignId = 'test-campaign1';
        $eventTypes = ['sent', 'opened', 'clicked', 'bounced'];
        $events = [];
        $stats = [
            'sent' => 0,
            'opened' => 0,
            'clicked' => 0,
            'bounced' => 0,
        ];

        // Insert the campaign if it doesn't exist
        Campaign::query()->insertOrIgnore([
            'id' => $campaignId,
            'name' => 'Test Campaign1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Generate 20,000 events
        for ($i = 0; $i < 20000; $i++) {
            $type = $eventTypes[array_rand($eventTypes)];
            $events[] = [
                'event_id' => Str::uuid(),
                'campaign_id' => $campaignId,
                'type' => $type,
                'occurred_at' => now()->subSeconds(rand(0, 3600)),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Increment stats for the event type
            $stats[$type]++;

            // Insert events in batches of 1000
            if (count($events) === 1000) {
                CampaignEvent::query()->insert($events);
                $events = [];
            }
        }

        // Insert remaining events
        if (!empty($events)) {
            CampaignEvent::query()->insert($events);
        }

        // Update the campaign_stats table
        DB::table('campaign_stats')->updateOrInsert(
            ['campaign_id' => $campaignId],
            [
                'sent' => $stats['sent'],
                'opened' => $stats['opened'],
                'clicked' => $stats['clicked'],
                'bounced' => $stats['bounced'],
                'last_event_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}