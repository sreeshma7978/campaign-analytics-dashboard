<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CampaignService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->campaigns->listForDashboard(),
        ]);
    }
}
