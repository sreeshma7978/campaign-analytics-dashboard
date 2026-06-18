<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface CampaignService
{
    /**
     * @return Collection<int, array{id: string, name: string}>
     */
    public function listForDashboard(int $limit = 50): Collection;
}
