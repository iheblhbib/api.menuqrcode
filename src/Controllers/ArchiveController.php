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
 * Soft-deleted ("etat = '1'") categories and articles across every level —
 * every other query in the app filters etat = '0' out, so this is the only
 * place a deleted item is visible again, until it's restored.
 */
class ArchiveController
{
    private const TYPES = ['categorie', 'categorie_sub', 'categorie_sub_sub', 'article'];

    public function index(Request $request): void
    {
        Validator::required($request->query, ['market_id']);
        $marketId = (int) $request->input('market_id');
        MarketScope::assertOwned($request, $marketId);

        $items = [];
        foreach ((new CategoryRepository())->listDeletedForMarket($marketId) as $row) {
            $items[] = $this->present($row, 'categorie', false);
        }

        $tree = new CategoryTreeRepository();
        foreach (['categorie_sub', 'categorie_sub_sub'] as $level) {
            foreach ($tree->listDeleted($level, $marketId) as $row) {
                $items[] = $this->present($row, $level, false);
            }
        }

        foreach ((new ArticleRepository())->listDeletedForMarket($marketId) as $row) {
            $items[] = $this->present($row, 'article', true);
        }

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
