<?php

namespace App\Controllers;

use App\Core\MarketScope;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\LanguageRepository;

class LanguageController
{
    public function forMarket(Request $request): void
    {
        $marketId = (int) $request->params['marketId'];
        MarketScope::assertOwned($request, $marketId);

        Response::success((new LanguageRepository())->forMarket($marketId));
    }

    public function setEnabled(Request $request): void
    {
        $marketId = (int) $request->params['marketId'];
        $langueId = (int) $request->params['langueId'];
        MarketScope::assertOwned($request, $marketId);

        if (!array_key_exists('enabled', $request->body)) {
            throw new ValidationException(['enabled' => 'This field is required']);
        }

        $repo = new LanguageRepository();
        $repo->setEnabled($marketId, $langueId, (bool) $request->body['enabled']);
        Response::success($repo->forMarket($marketId));
    }
}
