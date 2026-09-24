<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Wraps the "article" table (menu items). Same soft-delete ("etat") and
 * visibility ("statut") conventions as CategoryRepository.
 */
class ArticleRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function listForCategory(int $categoryId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, created
             FROM article
             WHERE categorie = ? AND etat = '0'
             ORDER BY order_categorie ASC, id ASC"
        );
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    }

    /** Niveau-1 markets only: articles directly at the market root, no category at all. */
    public function listForMarket(int $marketId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, created
             FROM article
             WHERE market = ? AND categorie IS NULL AND etat = '0'
             ORDER BY order_categorie ASC, id ASC"
        );
        $stmt->execute([$marketId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, gestionnaire, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, created
             FROM article WHERE id = ? AND etat = '0' LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** `$data['categorie']` may be null — a niveau-1 market's article sits directly at the market root. */
    public function create(array $data): int
    {
        $categoryId = $data['categorie'] ?? null;
        $marketId = (int) $data['market'];
        $nextOrder = $categoryId !== null
            ? $this->nextOrder((int) $categoryId)
            : $this->nextOrderForMarket($marketId);

        $stmt = $this->db->prepare(
            "INSERT INTO article (gestionnaire, market, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, etat, created)
             VALUES (:gestionnaire, :market, :categorie, :libelle, :prix, :description, :image, :statut, :display_image, :icon, :order_categorie, :type, '0', NOW())"
        );
        $stmt->execute([
            ':gestionnaire' => $data['gestionnaire'],
            ':market' => $marketId,
            ':categorie' => $categoryId,
            ':libelle' => $data['libelle'],
            ':prix' => $data['prix'] ?? null,
            ':description' => $data['description'] ?? null,
            ':image' => $data['image'] ?? null,
            ':statut' => $data['statut'] ?? 'Activer',
            ':display_image' => $data['display_image'] ?? '1',
            ':icon' => $data['icon'] ?? null,
            ':order_categorie' => $nextOrder,
            ':type' => $data['type'] ?? 'product',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];

        foreach (['libelle', 'prix', 'description', 'image', 'statut', 'display_image', 'icon', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE article SET ' . implode(', ', $fields) . " WHERE id = :id AND etat = '0'");
        $stmt->execute($params);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE article SET etat = '1' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /** For the article list screen's own Archive icon — deleted articles in this category only. */
    public function listDeletedForCategory(int $categoryId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, created
             FROM article
             WHERE categorie = ? AND etat = '1'
             ORDER BY id DESC"
        );
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    }

    /** Niveau-1 markets only: the market-root article list's own Archive icon. */
    public function listDeletedForMarketRoot(int $marketId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, created
             FROM article
             WHERE market = ? AND categorie IS NULL AND etat = '1'
             ORDER BY id DESC"
        );
        $stmt->execute([$marketId]);
        return $stmt->fetchAll();
    }

    /** Which market this row belongs to, regardless of etat (deleted or not) — for restore's ownership check. */
    public function marketOfAny(int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT market FROM article WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (int) $row['market'] : null;
    }

    public function restore(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE article SET etat = '0' WHERE id = ?");
        $stmt->execute([$id]);
    }

    private function nextOrder(int $categoryId): int
    {
        $stmt = $this->db->prepare("SELECT MAX(order_categorie) AS max_order FROM article WHERE categorie = ? AND etat = '0'");
        $stmt->execute([$categoryId]);
        $max = $stmt->fetch()['max_order'] ?? 0;
        return ((int) $max) + 1;
    }

    private function nextOrderForMarket(int $marketId): int
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(order_categorie) AS max_order FROM article WHERE market = ? AND categorie IS NULL AND etat = '0'"
        );
        $stmt->execute([$marketId]);
        $max = $stmt->fetch()['max_order'] ?? 0;
        return ((int) $max) + 1;
    }
}
