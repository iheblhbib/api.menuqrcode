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
use App\Repositories\CategoryTranslationRepository;
use App\Repositories\CategoryTreeRepository;

/**
 * Navigation over the categorie_sub_sub / categorie_sub / categorie levels
 * above a menu item — see CategoryTreeRepository for the shape of this
 * hierarchy. The final "categorie" level's own items (articles) are served
 * by MenuItemController, not here.
 */
class CategoryTreeController
{
    public function roots(Request $request): void
    {
        Validator::required($request->query, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $result = (new CategoryTreeRepository())->roots($marketId);
        Response::success($this->withImageUrls($result));
    }

    public function children(Request $request): void
    {
        Validator::required($request->query, ['parent_level', 'parent_id']);
        $parentLevel = (string) $request->input('parent_level');
        $parentId = (int) $request->input('parent_id');

        $this->assertNodeOwned($request, $parentLevel, $parentId);

        $result = (new CategoryTreeRepository())->children($parentLevel, $parentId);
        Response::success($this->withImageUrls($result));
    }

    public function store(Request $request): void
    {
        Validator::required($request->body, ['market_id', 'level', 'libelle']);
        $marketId = (int) $request->input('market_id');
        $level = (string) $request->input('level');
        MarketScope::assertOwned($request, $marketId);

        $repo = new CategoryTreeRepository();
        $parentId = $request->input('parent_id') !== null ? (int) $request->input('parent_id') : null;

        $id = match ($level) {
            'categorie_sub_sub' => $repo->createSubSub($marketId, (string) $request->input('libelle')),
            'categorie_sub' => $repo->createSub($marketId, (string) $request->input('libelle'), $parentId),
            default => throw new ValidationException(['level' => 'Must be categorie_sub_sub or categorie_sub']),
        };

        Response::success(['id' => $id, 'level' => $level], 201);
    }

    public function update(Request $request): void
    {
        $level = (string) $request->params['level'];
        $id = (int) $request->params['id'];

        $this->assertNodeOwned($request, $level, $id);

        $repo = new CategoryTreeRepository();
        $repo->update($level, $id, array_intersect_key($request->body, array_flip(['libelle', 'statut', 'image'])));

        $node = $repo->find($level, $id);
        Response::success($node !== null ? $this->withImageUrl($node) : null);
    }

    public function destroy(Request $request): void
    {
        $level = (string) $request->params['level'];
        $id = (int) $request->params['id'];

        $this->assertNodeOwned($request, $level, $id);

        (new CategoryTreeRepository())->softDelete($level, $id);
        Response::success(['message' => 'Deleted']);
    }

    public function uploadImage(Request $request): void
    {
        $level = (string) $request->params['level'];
        $id = (int) $request->params['id'];

        $marketId = $this->assertNodeOwned($request, $level, $id);

        if (empty($request->files['image'])) {
            throw new ValidationException(['image' => 'This field is required']);
        }

        $relativePath = Upload::storeImage($request->files['image'], 'categories/' . $marketId, $level . '-' . $id);

        $repo = new CategoryTreeRepository();
        $repo->update($level, $id, ['image' => $relativePath]);

        $node = $repo->find($level, $id);
        Response::success($node !== null ? $this->withImageUrl($node) : null);
    }

    public function reorder(Request $request): void
    {
        $level = (string) $request->params['level'];
        $orderedIds = $request->input('ordered_ids', []);

        foreach ($orderedIds as $id) {
            $this->assertNodeOwned($request, $level, (int) $id);
        }

        (new CategoryTreeRepository())->reorder($level, $orderedIds);
        Response::success(['message' => 'Order updated']);
    }

    private function assertNodeOwned(Request $request, string $level, int $id): int
    {
        $marketId = (new CategoryTreeRepository())->marketOf($level, $id);
        if ($marketId === null) {
            throw new NotFoundException('Node not found');
        }
        MarketScope::assertOwned($request, $marketId);
        return $marketId;
    }

    private function withImageUrls(array $result): array
    {
        foreach ($result['items'] as &$item) {
            $item['image'] = Url::asset($item['image']);
        }
        return $result;
    }

    private function withImageUrl(array $node): array
    {
        $node['image'] = Url::asset($node['image']);
        return $node;
    }

    public function translations(Request $request): void
    {
        $level = (string) $request->params['level'];
        $id = (int) $request->params['id'];
        $this->assertNodeOwned($request, $level, $id);

        if (!CategoryTranslationRepository::isValidLevel($level)) {
            throw new ValidationException(['level' => 'Must be categorie_sub_sub or categorie_sub']);
        }

        Response::success((new CategoryTranslationRepository())->listFor($level, $id));
    }

    public function updateTranslation(Request $request): void
    {
        $level = (string) $request->params['level'];
        $id = (int) $request->params['id'];
        $langueId = (int) $request->params['langueId'];
        $this->assertNodeOwned($request, $level, $id);

        if (!CategoryTranslationRepository::isValidLevel($level)) {
            throw new ValidationException(['level' => 'Must be categorie_sub_sub or categorie_sub']);
        }

        $repo = new CategoryTranslationRepository();
        $repo->upsert($level, $id, $langueId, $request->input('libelle'));
        Response::success($repo->listFor($level, $id));
    }

    public function destroyTranslation(Request $request): void
    {
        $level = (string) $request->params['level'];
        $id = (int) $request->params['id'];
        $langueId = (int) $request->params['langueId'];
        $this->assertNodeOwned($request, $level, $id);

        (new CategoryTranslationRepository())->delete($level, $id, $langueId);
        Response::success(['message' => 'Translation deleted']);
    }
}
