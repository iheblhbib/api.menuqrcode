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
        $marketIds = $this->resolveMarketIds($request);
        $clientId = (int) $request->auth['client_id'];
        $days = $request->input('days') !== null ? (int) $request->input('days') : null;

        Response::success((new StatsRepository())->forMarkets($marketIds, $clientId, $days));
    }

    public function accessSeries(Request $request): void
    {
        $marketIds = $this->resolveMarketIds($request);
        $days = (int) $request->input('days', '30');
        $granularity = (string) $request->input('granularity', 'day');
        if (!in_array($granularity, ['day', 'month'], true)) {
            $granularity = 'day';
        }

        Response::success((new StatsRepository())->accessSeries($marketIds, $days, $granularity));
    }

    public function loginsSeries(Request $request): void
    {
        $clientId = (int) $request->auth['client_id'];
        $days = (int) $request->input('days', '30');

        Response::success((new StatsRepository())->loginsSeries($clientId, $days));
    }

    public function ratingsDistribution(Request $request): void
    {
        $marketIds = $this->resolveMarketIds($request);
        $days = (int) $request->input('days', '30');

        Response::success((new StatsRepository())->ratingsDistribution($marketIds, $days));
    }

    public function browserStats(Request $request): void
    {
        $marketIds = $this->resolveMarketIds($request);
        $days = (int) $request->input('days', '30');
        $limit = (int) $request->input('limit', '6');

        Response::success((new StatsRepository())->browserStats($marketIds, $days, $limit));
    }

    public function adminStats(Request $request): void
    {
        Response::success((new StatsRepository())->global());
    }

    /** @return int[] */
    private function resolveMarketIds(Request $request): array
    {
        $marketIds = $request->auth['market_ids'] ?? [];

        if ($request->input('market_id') !== null) {
            $marketId = (int) $request->input('market_id');
            MarketScope::assertOwned($request, $marketId);
            $marketIds = [$marketId];
        }

        return $marketIds;
    }
}
