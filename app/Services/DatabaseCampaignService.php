<?php

namespace App\Services;

use App\Contracts\CampaignService;
use App\Models\Campaign;
use Illuminate\Support\Collection;

class DatabaseCampaignService implements CampaignService
{
    public function listForDashboard(int $limit = 50): Collection
    {
        return Campaign::query()
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'name']);
    }
}
