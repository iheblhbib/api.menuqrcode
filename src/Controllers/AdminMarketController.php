<?php

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AdminMarketRepository;

class AdminMarketController
{
    public function index(Request $request): void
    {
        $clientId = $request->input('client_id') !== null ? (int) $request->input('client_id') : null;
        Response::success((new AdminMarketRepository())->list($clientId));
    }

    public function show(Request $request): void
    {
        $market = (new AdminMarketRepository())->find((int) $request->params['id']);
        if (!$market) {
            throw new NotFoundException('Market not found');
        }
        Response::success($market);
    }
}
