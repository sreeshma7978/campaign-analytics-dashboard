<?php

namespace App\Models;

use App\Enums\EngagementEventType;
use Illuminate\Database\Eloquent\Model;

class CampaignEvent extends Model
{
    protected $fillable = [
        'event_id',
        'campaign_id',
        'type',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => EngagementEventType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
