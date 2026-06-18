<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CampaignStatsController;
use App\Http\Controllers\Api\EventIngestionController;
use Illuminate\Support\Facades\Route;

Route::get('/campaigns', [CampaignController::class, 'index'])
    ->middleware('throttle:analytics-dashboard');

Route::post('/events', [EventIngestionController::class, 'store'])
    ->middleware('throttle:analytics-ingest');
Route::get('/campaigns/{campaignId}/stats', [CampaignStatsController::class, 'show'])
    ->where('campaignId', '[A-Za-z0-9._:-]+')
    ->middleware('throttle:analytics-dashboard');

Route::post('/campaigns/burst', [EventIngestionController::class, 'ingestBurst'])->middleware('throttle:analytics-dashboard');


Route::get('/campaigns/{campaignId}/events', [EventIngestionController::class, 'getEvents']);