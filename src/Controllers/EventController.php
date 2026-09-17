<?php

namespace App\Controllers;

use App\Core\MarketScope;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Helpers\Upload;
use App\Helpers\Url;
use App\Helpers\Validator;
use App\Repositories\EventRepository;

class EventController
{
    public function index(Request $request): void
    {
        Validator::required($request->query, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $events = array_map([$this, 'withImageUrl'], (new EventRepository())->listForMarket($marketId));
        Response::success($events);
    }

    public function store(Request $request): void
    {
        Validator::required($request->body, ['market_id', 'titre']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $repo = new EventRepository();
        $id = $repo->create($marketId, [
            'titre' => $request->input('titre'),
            'texte' => $request->input('texte'),
            'debut' => $request->input('debut'),
            'fin' => $request->input('fin'),
        ]);

        Response::success($this->withImageUrl($repo->find($id)), 201);
    }

    public function update(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        $repo = new EventRepository();
        $repo->update($id, array_intersect_key($request->body, array_flip(['titre', 'texte', 'debut', 'fin', 'statut'])));

        Response::success($this->withImageUrl($repo->find($id)));
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        (new EventRepository())->delete($id);
        Response::success(['message' => 'Event deleted']);
    }

    public function uploadImage(Request $request): void
    {
        $id = (int) $request->params['id'];
        $event = $this->findOrFail($request, $id);

        if (empty($request->files['image'])) {
            throw new ValidationException(['image' => 'This field is required']);
        }

        $relativePath = Upload::storeImage($request->files['image'], 'events/' . $event['market'], (string) $id);

        $repo = new EventRepository();
        $repo->update($id, ['banner' => $relativePath]);

        Response::success($this->withImageUrl($repo->find($id)));
    }

    private function withImageUrl(array $event): array
    {
        $event['banner'] = Url::asset($event['banner']);
        return $event;
    }

    private function findOrFail(Request $request, int $id): array
    {
        $event = (new EventRepository())->find($id);
        if (!$event) {
            throw new NotFoundException('Event not found');
        }
        MarketScope::assertOwned($request, (int) $event['market']);
        return $event;
    }
}
