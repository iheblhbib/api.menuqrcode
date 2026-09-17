<?php

namespace App\Controllers;

use App\Core\ForbiddenException;
use App\Core\MarketScope;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Repositories\QrCodeRepository;

class QrCodeController
{
    public function index(Request $request): void
    {
        $clientId = (int) $request->auth['client_id'];
        Response::success((new QrCodeRepository())->listForGestionnaire($clientId));
    }

    public function show(Request $request): void
    {
        Response::success($this->findOrFail($request, (int) $request->params['id']));
    }

    public function store(Request $request): void
    {
        Validator::required($request->body, ['libelle']);
        $marketIds = $request->input('market_ids', []);
        foreach ($marketIds as $marketId) {
            MarketScope::assertOwned($request, (int) $marketId);
        }

        $repo = new QrCodeRepository();
        $id = $repo->create(
            (int) $request->auth['client_id'],
            (string) $request->input('libelle'),
            $request->input('url'),
            $request->input('link'),
            $marketIds
        );

        Response::success($repo->find($id), 201);
    }

    public function update(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        $marketIds = $request->body['market_ids'] ?? null;
        if ($marketIds !== null) {
            foreach ($marketIds as $marketId) {
                MarketScope::assertOwned($request, (int) $marketId);
            }
        }

        $repo = new QrCodeRepository();
        $repo->update($id, array_intersect_key($request->body, array_flip(['libelle', 'url', 'link'])), $marketIds);

        Response::success($repo->find($id));
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        (new QrCodeRepository())->softDelete($id);
        Response::success(['message' => 'QR code deleted']);
    }

    public function customStyle(Request $request): void
    {
        Validator::required($request->query, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $style = (new QrCodeRepository())->customStyleForMarket($marketId);
        Response::success($style);
    }

    public function updateCustomStyle(Request $request): void
    {
        Validator::required($request->body, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $repo = new QrCodeRepository();
        $repo->upsertCustomStyle($marketId, $request->body);

        Response::success($repo->customStyleForMarket($marketId));
    }

    private function findOrFail(Request $request, int $id): array
    {
        $qr = (new QrCodeRepository())->find($id);
        if (!$qr) {
            throw new NotFoundException('QR code not found');
        }
        if ((int) $qr['gestionnaire'] !== (int) $request->auth['client_id']) {
            throw new ForbiddenException('This QR code does not belong to your account');
        }
        return $qr;
    }
}
