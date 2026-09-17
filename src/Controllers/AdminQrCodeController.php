<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AdminQrCodeRepository;

class AdminQrCodeController
{
    public function index(Request $request): void
    {
        $clientId = $request->input('client_id') !== null ? (int) $request->input('client_id') : null;
        Response::success((new AdminQrCodeRepository())->list($clientId));
    }
}
