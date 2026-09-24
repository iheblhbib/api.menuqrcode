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
use App\Repositories\ArticleRepository;
use App\Repositories\ArticleTranslationRepository;
use App\Repositories\CategoryRepository;

class MenuItemController
{
    public function index(Request $request): void
    {
        // A niveau-1 market has no category level at all — its articles sit
        // directly at the market root, listed by market_id instead.
        if ($request->input('market_id') !== null) {
            $marketId = (int) $request->input('market_id');
            MarketScope::assertOwned($request, $marketId);
            $articles = array_map([$this, 'withImageUrl'], (new ArticleRepository())->listForMarket($marketId));
            Response::success($articles);
            return;
        }

        Validator::required($request->query, ['category_id']);
        $categoryId = (int) $request->input('category_id');
        $this->assertCategoryOwned($request, $categoryId);

        $articles = array_map([$this, 'withImageUrl'], (new ArticleRepository())->listForCategory($categoryId));
        Response::success($articles);
    }

    public function show(Request $request): void
    {
        Response::success($this->withImageUrl($this->findOrFail($request, (int) $request->params['id'])));
    }

    public function store(Request $request): void
    {
        Validator::required($request->body, ['libelle']);

        // A niveau-1 market has no category level — creating an article
        // there takes market_id instead of category_id, and categorie stays
        // null (the market-root article).
        if ($request->input('market_id') !== null) {
            $marketId = (int) $request->input('market_id');
            MarketScope::assertOwned($request, $marketId);

            $repo = new ArticleRepository();
            $id = $repo->create([
                'gestionnaire' => $request->auth['client_id'],
                'market' => $marketId,
                'categorie' => null,
                'libelle' => $request->input('libelle'),
                'prix' => $request->input('prix'),
                'description' => $request->input('description'),
                'image' => $request->input('image'),
                'statut' => $request->input('statut', 'Activer'),
                'display_image' => $request->input('display_image'),
                'type' => $request->input('type', 'product'),
            ]);

            Response::success($this->withImageUrl($repo->find($id)), 201);
            return;
        }

        Validator::required($request->body, ['category_id']);
        $categoryId = (int) $request->input('category_id');
        $category = $this->assertCategoryOwned($request, $categoryId);

        $repo = new ArticleRepository();
        $id = $repo->create([
            'gestionnaire' => $request->auth['client_id'],
            'market' => $category['market'],
            'categorie' => $categoryId,
            'libelle' => $request->input('libelle'),
            'prix' => $request->input('prix'),
            'description' => $request->input('description'),
            'image' => $request->input('image'),
            'statut' => $request->input('statut', 'Activer'),
            'display_image' => $request->input('display_image'),
            'type' => $request->input('type', 'product'),
        ]);

        Response::success($this->withImageUrl($repo->find($id)), 201);
    }

    public function update(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        $repo = new ArticleRepository();
        $repo->update($id, array_intersect_key(
            $request->body,
            array_flip(['libelle', 'prix', 'description', 'image', 'statut', 'display_image', 'icon', 'type'])
        ));

        Response::success($this->withImageUrl($repo->find($id)));
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        (new ArticleRepository())->softDelete($id);
        Response::success(['message' => 'Menu item deleted']);
    }

    public function uploadImage(Request $request): void
    {
        $id = (int) $request->params['id'];
        $article = $this->findOrFail($request, $id);

        if (empty($request->files['image'])) {
            throw new ValidationException(['image' => 'This field is required']);
        }

        $relativePath = Upload::storeImage($request->files['image'], 'menu-items/' . $article['market'], (string) $id);

        $repo = new ArticleRepository();
        $repo->update($id, ['image' => $relativePath]);

        Response::success($this->withImageUrl($repo->find($id)));
    }

    /** "Afficher l'image" (display_image, '1' = Oui) — when off, never send the real uploaded image, only the generic placeholder. */
    private function withImageUrl(array $article): array
    {
        $path = ($article['display_image'] ?? null) === '1' ? ($article['image'] ?? null) : '../app.menuqrcode.tn/img/no-photo.webp';
        $article['image'] = Url::asset($path);
        return $article;
    }

    public function translations(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($request, $id);

        Response::success((new ArticleTranslationRepository())->listFor($id));
    }

    public function updateTranslation(Request $request): void
    {
        $id = (int) $request->params['id'];
        $langueId = (int) $request->params['langueId'];
        $this->findOrFail($request, $id);

        $repo = new ArticleTranslationRepository();
        $repo->upsert($id, $langueId, $request->input('libelle'), $request->input('prix'), $request->input('description'));
        Response::success($repo->listFor($id));
    }

    public function destroyTranslation(Request $request): void
    {
        $id = (int) $request->params['id'];
        $langueId = (int) $request->params['langueId'];
        $this->findOrFail($request, $id);

        (new ArticleTranslationRepository())->delete($id, $langueId);
        Response::success(['message' => 'Translation deleted']);
    }

    private function findOrFail(Request $request, int $id): array
    {
        $article = (new ArticleRepository())->find($id);
        if (!$article) {
            throw new NotFoundException('Menu item not found');
        }
        MarketScope::assertOwned($request, (int) $article['market']);
        return $article;
    }

    private function assertCategoryOwned(Request $request, int $categoryId): array
    {
        $category = (new CategoryRepository())->find($categoryId);
        if (!$category) {
            throw new NotFoundException('Category not found');
        }
        MarketScope::assertOwned($request, (int) $category['market']);
        return $category;
    }
}
