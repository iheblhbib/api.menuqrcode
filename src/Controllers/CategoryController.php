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
use App\Repositories\CategoryRepository;
use App\Repositories\CategoryTranslationRepository;

class CategoryController
{
    public function index(Request $request): void
    {
        Validator::required($request->query, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $repo = new CategoryRepository();
        $categories = array_map([$this, 'withImageUrl'], $repo->listForMarket($marketId));
        Response::success($categories);
    }

    public function show(Request $request): void
    {
        $category = $this->findOrFail($request, (int) $request->params['id']);
        Response::success($this->withImageUrl($category));
    }

    public function store(Request $request): void
    {
        Validator::required($request->body, ['market_id', 'libelle']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $repo = new CategoryRepository();
        $id = $repo->create([
            'gestionnaire' => $request->auth['client_id'],
            'market' => $marketId,
            'libelle' => $request->input('libelle'),
            'image' => $request->input('image'),
            'icon' => $request->input('icon'),
            'statut' => $request->input('statut', 'Activer'),
            // Nests this category under a categorie_sub node when provided
            // (see CategoryTreeRepository) — null/omitted stays top-level.
            'parent_id' => $request->input('parent_id'),
        ]);

        Response::success($this->withImageUrl($repo->find($id)), 201);
    }

    public function update(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        $repo = new CategoryRepository();
        $repo->update($id, array_intersect_key($request->body, array_flip(['libelle', 'statut', 'image', 'icon', 'display_image'])));

        Response::success($this->withImageUrl($repo->find($id)));
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        (new CategoryRepository())->softDelete($id);
        Response::success(['message' => 'Category deleted']);
    }

    public function uploadImage(Request $request): void
    {
        $id = (int) $request->params['id'];
        $category = $this->findOrFail($request, $id);

        if (empty($request->files['image'])) {
            throw new ValidationException(['image' => 'This field is required']);
        }

        $relativePath = Upload::storeImage($request->files['image'], 'categories/' . $category['market'], (string) $id);

        $repo = new CategoryRepository();
        $repo->update($id, ['image' => $relativePath]);

        Response::success($this->withImageUrl($repo->find($id)));
    }

    /** "Afficher l'image" (display_image, '1' = Oui) — when off, never send the real uploaded image, only the generic placeholder. */
    private function withImageUrl(array $category): array
    {
        $path = ($category['display_image'] ?? null) === '1' ? ($category['image'] ?? null) : '../app.menuqrcode.tn/img/no-photo.webp';
        $category['image'] = Url::asset($path);
        return $category;
    }

    public function translations(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        Response::success((new CategoryTranslationRepository())->listFor('categorie', $id));
    }

    public function updateTranslation(Request $request): void
    {
        $id = (int) $request->params['id'];
        $langueId = (int) $request->params['langueId'];
        $this->findOrFail($request, $id);

        $repo = new CategoryTranslationRepository();
        $repo->upsert('categorie', $id, $langueId, $request->input('libelle'));
        Response::success($repo->listFor('categorie', $id));
    }

    public function destroyTranslation(Request $request): void
    {
        $id = (int) $request->params['id'];
        $langueId = (int) $request->params['langueId'];
        $this->findOrFail($request, $id);

        (new CategoryTranslationRepository())->delete('categorie', $id, $langueId);
        Response::success(['message' => 'Translation deleted']);
    }

    public function reorder(Request $request): void
    {
        Validator::required($request->body, ['market_id', 'ordered_ids']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        (new CategoryRepository())->reorder($marketId, $request->input('ordered_ids'));
        Response::success(['message' => 'Order updated']);
    }

    private function findOrFail(Request $request, int $id): array
    {
        $category = (new CategoryRepository())->find($id);
        if (!$category) {
            throw new NotFoundException('Category not found');
        }
        MarketScope::assertOwned($request, (int) $category['market']);
        return $category;
    }
}
