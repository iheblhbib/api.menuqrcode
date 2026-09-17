<?php

namespace App\Controllers;

use App\Core\MarketScope;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\StatsRepository;

class DashboardController
{
    public function clientStats(Request $request): void
    {
        $marketIds = $request->auth['market_ids'] ?? [];

        if ($request->input('market_id') !== null) {
            $marketId = (int) $request->input('market_id');
            MarketScope::assertOwned($request, $marketId);
            $marketIds = [$marketId];
        }

        Response::success((new StatsRepository())->forMarkets($marketIds));
    }

    public function adminStats(Request $request): void
    {
        Response::success((new StatsRepository())->global());
    }
}
