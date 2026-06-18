<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignStat extends Model
{
    protected $primaryKey = 'campaign_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'campaign_id',
        'sent',
        'opened',
        'clicked',
        'bounced',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'sent' => 'integer',
            'opened' => 'integer',
            'clicked' => 'integer',
            'bounced' => 'integer',
            'last_event_at' => 'immutable_datetime',
        ];
    }
}
