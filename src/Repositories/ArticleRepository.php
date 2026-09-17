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

    public function create(array $data): int
    {
        $nextOrder = $this->nextOrder((int) $data['categorie']);

        $stmt = $this->db->prepare(
            "INSERT INTO article (gestionnaire, market, categorie, libelle, prix, description, image, statut, display_image, icon, order_categorie, type, etat, created)
             VALUES (:gestionnaire, :market, :categorie, :libelle, :prix, :description, :image, :statut, :display_image, :icon, :order_categorie, :type, '0', NOW())"
        );
        $stmt->execute([
            ':gestionnaire' => $data['gestionnaire'],
            ':market' => $data['market'],
            ':categorie' => $data['categorie'],
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

    private function nextOrder(int $categoryId): int
    {
        $stmt = $this->db->prepare("SELECT MAX(order_categorie) AS max_order FROM article WHERE categorie = ? AND etat = '0'");
        $stmt->execute([$categoryId]);
        $max = $stmt->fetch()['max_order'] ?? 0;
        return ((int) $max) + 1;
    }
}
