<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Wraps the "categorie" table (top-level menu categories only for the MVP —
 * the legacy categorie_sub/categorie_sub_sub/categorie_sub_sub_sub tables are
 * Phase 2+, see docs/db-reconciliation.md). "etat" is a soft-delete flag
 * ('0' = active, '1' = deleted), matching the existing web app's convention.
 */
class CategoryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function listForMarket(int $marketId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, libelle, statut, image, icon, display_image, order_categorie, created
             FROM categorie
             WHERE market = ? AND etat = '0'
             ORDER BY order_categorie ASC, id ASC"
        );
        $stmt->execute([$marketId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, market, gestionnaire, libelle, statut, image, icon, display_image, order_categorie, created
             FROM categorie WHERE id = ? AND etat = '0' LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $nextOrder = $this->nextOrder((int) $data['market']);

        // "categorie" here is this row's own parent (a categorie_sub id, if
        // nested) — null for a top-level category, matching the legacy schema.
        $stmt = $this->db->prepare(
            "INSERT INTO categorie (gestionnaire, market, libelle, statut, image, icon, display_image, order_categorie, categorie, etat, created)
             VALUES (:gestionnaire, :market, :libelle, :statut, :image, :icon, :display_image, :order_categorie, :parent_id, '0', NOW())"
        );
        $stmt->execute([
            ':gestionnaire' => $data['gestionnaire'],
            ':market' => $data['market'],
            ':libelle' => $data['libelle'],
            ':statut' => $data['statut'] ?? 'Activer',
            ':image' => $data['image'] ?? null,
            ':icon' => $data['icon'] ?? null,
            ':display_image' => $data['display_image'] ?? '2',
            ':order_categorie' => $nextOrder,
            ':parent_id' => $data['parent_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];

        foreach (['libelle', 'statut', 'image', 'icon', 'display_image'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE categorie SET ' . implode(', ', $fields) . " WHERE id = :id AND etat = '0'");
        $stmt->execute($params);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE categorie SET etat = '1' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /** Which market this row belongs to, regardless of etat (deleted or not) — for restore's ownership check. */
    public function marketOfAny(int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT market FROM categorie WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (int) $row['market'] : null;
    }

    public function restore(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE categorie SET etat = '0' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * `$orderedIds` may be a filtered subset (e.g. just the "Actifs" tab) —
     * merge its new relative order into the full sibling list so items
     * outside the subset (hidden by the filter) keep their relative slot
     * instead of being pushed to the end or renumbered on top of each other.
     */
    public function reorder(int $marketId, array $orderedIds): void
    {
        $all = $this->db->prepare(
            "SELECT id FROM categorie WHERE market = ? AND etat = '0' ORDER BY order_categorie ASC, id ASC"
        );
        $all->execute([$marketId]);
        $allIds = array_map('intval', array_column($all->fetchAll(), 'id'));

        $subset = array_map('intval', $orderedIds);
        $subsetSet = array_flip($subset);
        $slots = [];
        foreach ($allIds as $index => $id) {
            if (isset($subsetSet[$id])) {
                $slots[] = $index;
            }
        }

        $merged = $allIds;
        foreach ($slots as $i => $slotIndex) {
            $merged[$slotIndex] = $subset[$i];
        }

        $stmt = $this->db->prepare("UPDATE categorie SET order_categorie = ? WHERE id = ? AND market = ? AND etat = '0'");
        foreach ($merged as $position => $id) {
            $stmt->execute([$position + 1, $id, $marketId]);
        }
    }

    private function nextOrder(int $marketId): int
    {
        $stmt = $this->db->prepare("SELECT MAX(order_categorie) AS max_order FROM categorie WHERE market = ? AND etat = '0'");
        $stmt->execute([$marketId]);
        $max = $stmt->fetch()['max_order'] ?? 0;
        return ((int) $max) + 1;
    }
}
