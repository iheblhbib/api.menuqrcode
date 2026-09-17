<?php

namespace App\Controllers;

use App\Config\Env;
use App\Core\Crypto;
use App\Core\MarketScope;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\QrPngRenderer;
use App\Repositories\MarketRepository;

class MarketController
{
    public function index(Request $request): void
    {
        $marketIds = $request->auth['market_ids'] ?? [];
        Response::success((new MarketRepository())->listByIds($marketIds));
    }

    public function show(Request $request): void
    {
        $marketId = (int) $request->params['id'];
        MarketScope::assertOwned($request, $marketId);

        $market = (new MarketRepository())->find($marketId);
        if ($market === null) {
            throw new NotFoundException('Market not found');
        }
        Response::success($market);
    }

    public function update(Request $request): void
    {
        $marketId = (int) $request->params['id'];
        MarketScope::assertOwned($request, $marketId);

        $repo = new MarketRepository();
        $repo->update($marketId, $request->body);
        Response::success($repo->find($marketId));
    }

    /**
     * The "Standard" QR: a direct, always-available link to this market's
     * public menu (https://menuqrcode.tn/qrcode?data=<encrypted market id>),
     * matching the existing web app's visitor entry point.
     */
    public function menuQrCode(Request $request): void
    {
        $marketId = (int) $request->params['id'];
        MarketScope::assertOwned($request, $marketId);

        Response::success([
            'market_id' => $marketId,
            'url' => $this->menuUrl($marketId),
            'image_url' => rtrim(Env::get('APP_URL', ''), '/') . "/api/v1/client/markets/$marketId/menu-qrcode/image",
        ]);
    }

    public function menuQrCodeImage(Request $request): void
    {
        $marketId = (int) $request->params['id'];
        MarketScope::assertOwned($request, $marketId);

        $png = QrPngRenderer::renderPng($this->menuUrl($marketId));

        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo $png;
    }

    private function menuUrl(int $marketId): string
    {
        // Deliberately NOT url-encoded: the existing site expects the raw
        // base64 value (e.g. "0ak=") in the query string, confirmed by the user.
        $base = rtrim(Env::get('MENU_BASE_URL', 'https://menuqrcode.tn/'), '/');
        return $base . '/qrcode?data=' . Crypto::encrypt((string) $marketId);
    }
}
