<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CampaignAnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CampaignStatsController extends Controller
{
    public function __construct(private readonly CampaignAnalyticsService $analytics) {}

    public function show(string $campaignId): JsonResponse
    {
        return response()->json($this->analytics->statsForCampaign($campaignId));
    }
}
