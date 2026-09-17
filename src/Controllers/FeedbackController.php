<?php

namespace App\Controllers;

use App\Core\MarketScope;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FeedbackRepository;

class FeedbackController
{
    public function forMarket(Request $request): void
    {
        $marketId = (int) $request->params['marketId'];
        MarketScope::assertOwned($request, $marketId);

        Response::success((new FeedbackRepository())->forMarket($marketId));
    }
}
