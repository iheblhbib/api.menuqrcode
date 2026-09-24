<?php

namespace App\Controllers;

use App\Core\MarketScope;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Helpers\Url;
use App\Helpers\Validator;
use App\Repositories\ArticleRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CategoryTreeRepository;

/**
 * Soft-deleted ("etat = '1'") categories and articles — every other query in
 * the app filters etat = '0' out, so this is the only place a deleted item
 * is visible again, until it's restored.
 *
 * Scoped per screen, not per market: each level of the Menu screen (and the
 * article list) has its own Archive showing only what *that* screen would
 * have shown had it not been deleted — mirroring CategoryTreeRepository's
 * own roots()/children() scoping exactly.
 */
class ArchiveController
{
    private const TYPES = ['categorie', 'categorie_sub', 'categorie_sub_sub', 'article'];

    public function index(Request $request): void
    {
        Validator::required($request->query, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $parentLevel = $request->input('parent_level');
        $parentId = $request->input('parent_id');

        // The article list screen (a leaf "categorie" node's own children)
        // asks for its category's deleted articles specifically.
        if ($parentLevel === 'categorie') {
            if ($parentId === null) {
                throw new ValidationException(['parent_id' => 'This field is required']);
            }
            $categoryId = (int) $parentId;
            $categoryMarket = (new CategoryRepository())->marketOfAny($categoryId);
            if ($categoryMarket === null) {
                throw new NotFoundException('Category not found');
            }
            MarketScope::assertOwned($request, $categoryMarket);

            $items = array_map(
                fn (array $row) => $this->present($row, 'article', true),
                (new ArticleRepository())->listDeletedForCategory($categoryId)
            );
            Response::success($items);
            return;
        }

        $tree = new CategoryTreeRepository();

        if ($parentLevel !== null && $parentId !== null) {
            // A drilled-into node's own Archive — deleted children of that
            // specific node only.
            $level = (string) $parentLevel;
            $parentNodeMarket = $tree->marketOf($level, (int) $parentId);
            if ($parentNodeMarket === null) {
                throw new NotFoundException('Node not found');
            }
            MarketScope::assertOwned($request, $parentNodeMarket);

            $rows = $tree->childrenDeleted($level, (int) $parentId);
        } else {
            // The market-root Menu screen's own Archive — deleted items at
            // whichever level roots() currently resolves to for this market.
            $rootLevel = $tree->roots($marketId)['level'];
            $rows = $tree->rootDeleted($rootLevel, $marketId);
        }

        $items = array_map(fn (array $row) => $this->present($row, $row['level'], false), $rows);
        Response::success($items);
    }

    public function restore(Request $request): void
    {
        Validator::required($request->body, ['type', 'id']);
        $type = (string) $request->input('type');
        $id = (int) $request->input('id');

        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException(['type' => 'Must be one of: ' . implode(', ', self::TYPES)]);
        }

        if ($type === 'article') {
            $repo = new ArticleRepository();
            $marketId = $repo->marketOfAny($id);
            if ($marketId === null) {
                throw new NotFoundException('Item not found');
            }
            MarketScope::assertOwned($request, $marketId);
            $repo->restore($id);
        } elseif ($type === 'categorie') {
            $repo = new CategoryRepository();
            $marketId = $repo->marketOfAny($id);
            if ($marketId === null) {
                throw new NotFoundException('Item not found');
            }
            MarketScope::assertOwned($request, $marketId);
            $repo->restore($id);
        } else {
            $tree = new CategoryTreeRepository();
            $marketId = $tree->marketOf($type, $id);
            if ($marketId === null) {
                throw new NotFoundException('Item not found');
            }
            MarketScope::assertOwned($request, $marketId);
            $tree->restore($type, $id);
        }

        Response::success(['message' => 'Item restored']);
    }

    /** Same "Afficher l'image" placeholder-substitution convention as every other controller here. */
    private function present(array $row, string $type, bool $isArticle): array
    {
        $path = ($row['display_image'] ?? null) === '1' ? ($row['image'] ?? null) : '../app.menuqrcode.tn/img/no-photo.webp';

        return [
            'type' => $type,
            'id' => (int) $row['id'],
            'libelle' => $row['libelle'],
            'image' => Url::asset($path),
            'created' => $row['created'] ?? null,
            'is_article' => $isArticle,
        ];
    }
}
