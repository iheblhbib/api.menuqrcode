<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Navigates the legacy 3-level category hierarchy confirmed against real
 * production data (see docs/db-reconciliation.md):
 *
 *   categorie_sub_sub  ->  categorie_sub  ->  categorie  ->  article
 *
 * Each level's row links to its parent via a plain `categorie` int column
 * (poorly named after itself, not the parent table — a legacy quirk, not a
 * bug). categorie_sub_sub sits directly under `market` with no parent column
 * of its own. In practice a market uses either the flat form (categorie rows
 * with categorie IS NULL, no sub/sub_sub rows at all — the common case) or
 * the fully nested form; roots() auto-detects which by trying each level in
 * turn. `categorie_sub_sub_sub` (a 4th, market-level "nav tabs" concept with
 * external_link/pdf/market_link) is confirmed real but only ~6 rows across
 * the whole production dataset and its link to categorie_sub_sub is not
 * empirically confirmed — deliberately out of scope here, see
 * docs/db-reconciliation.md.
 */
class CategoryTreeRepository
{
    private const LEVELS = ['categorie_sub_sub', 'categorie_sub', 'categorie'];

    /** What table holds the children of a node at this level. */
    private const CHILD_LEVEL = [
        'categorie_sub_sub' => 'categorie_sub',
        'categorie_sub' => 'categorie',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** Auto-detects the top of this market's category tree. */
    public function roots(int $marketId): array
    {
        $subSub = $this->queryLevel('categorie_sub_sub', 'market = ?', [$marketId]);
        if (!empty($subSub)) {
            return ['level' => 'categorie_sub_sub', 'items' => $subSub];
        }

        $sub = $this->queryLevel('categorie_sub', 'market = ? AND categorie IS NULL', [$marketId]);
        if (!empty($sub)) {
            return ['level' => 'categorie_sub', 'items' => $sub];
        }

        $cat = $this->queryLevel('categorie', 'market = ? AND categorie IS NULL', [$marketId]);
        return ['level' => 'categorie', 'items' => $cat];
    }

    public function children(string $parentLevel, int $parentId): array
    {
        $childLevel = self::CHILD_LEVEL[$parentLevel] ?? null;
        if ($childLevel === null) {
            return ['level' => null, 'items' => []];
        }

        $items = $this->queryLevel($childLevel, 'categorie = ?', [$parentId]);
        return ['level' => $childLevel, 'items' => $items];
    }

    /** For auth checks: which market does this node (at any level) belong to? */
    public function marketOf(string $level, int $id): ?int
    {
        if (!in_array($level, self::LEVELS, true)) {
            return null;
        }
        // $level is whitelisted above, safe to interpolate into the query.
        $stmt = $this->db->prepare("SELECT market FROM $level WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (int) $row['market'] : null;
    }

    public function createSubSub(int $marketId, string $libelle): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO categorie_sub_sub (market, libelle, statut, etat, order_categorie, created)
             VALUES (?, ?, 'Activer', '0', ?, NOW())"
        );
        $stmt->execute([$marketId, $libelle, $this->nextOrder('categorie_sub_sub', 'market = ?', [$marketId])]);
        return (int) $this->db->lastInsertId();
    }

    public function createSub(int $marketId, string $libelle, ?int $parentId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO categorie_sub (market, libelle, categorie, statut, etat, order_categorie, created)
             VALUES (?, ?, ?, 'Activer', '0', ?, NOW())"
        );
        $stmt->execute([
            $marketId,
            $libelle,
            $parentId,
            $this->nextOrder('categorie_sub', 'market = ?', [$marketId]),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function softDelete(string $level, int $id): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            return;
        }
        $this->db->prepare("UPDATE $level SET etat = '1' WHERE id = ?")->execute([$id]);
    }

    /** Renames (or otherwise edits) a categorie_sub_sub / categorie_sub node. */
    public function update(string $level, int $id, array $data): void
    {
        if (!in_array($level, ['categorie_sub_sub', 'categorie_sub'], true)) {
            return;
        }

        $fields = [];
        $params = [':id' => $id];
        foreach (['libelle', 'statut', 'image', 'display_image'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return;
        }

        // $level is whitelisted above, safe to interpolate into the query.
        $stmt = $this->db->prepare("UPDATE $level SET " . implode(', ', $fields) . " WHERE id = :id AND etat = '0'");
        $stmt->execute($params);
    }

    /**
     * Reorders sibling nodes at one level under the same parent (or market
     * root). `$orderedIds` may be a filtered subset (e.g. just "Actifs") —
     * the first id's own parent scope is used to fetch every sibling and
     * merge the subset's new relative order into the right slots, so items
     * hidden by the filter keep their position instead of being scrambled.
     */
    public function reorder(string $level, array $orderedIds): void
    {
        if (!in_array($level, ['categorie_sub_sub', 'categorie_sub'], true) || empty($orderedIds)) {
            return;
        }
        // $level is whitelisted above, safe to interpolate into the query.
        $anchor = $this->db->prepare("SELECT market, categorie FROM $level WHERE id = ? AND etat = '0'");
        $anchor->execute([(int) $orderedIds[0]]);
        $row = $anchor->fetch();
        if (!$row) {
            return;
        }

        if ($level === 'categorie_sub_sub') {
            // No parent column of its own — sibling scope is the whole market.
            $siblings = $this->db->prepare(
                "SELECT id FROM categorie_sub_sub WHERE market = ? AND etat = '0' ORDER BY order_categorie ASC, id ASC"
            );
            $siblings->execute([$row['market']]);
        } elseif ($row['categorie'] === null) {
            $siblings = $this->db->prepare(
                "SELECT id FROM categorie_sub WHERE market = ? AND categorie IS NULL AND etat = '0' ORDER BY order_categorie ASC, id ASC"
            );
            $siblings->execute([$row['market']]);
        } else {
            $siblings = $this->db->prepare(
                "SELECT id FROM categorie_sub WHERE categorie = ? AND etat = '0' ORDER BY order_categorie ASC, id ASC"
            );
            $siblings->execute([$row['categorie']]);
        }
        $allIds = array_map('intval', array_column($siblings->fetchAll(), 'id'));

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

        $update = $this->db->prepare("UPDATE $level SET order_categorie = ? WHERE id = ? AND etat = '0'");
        foreach ($merged as $position => $id) {
            $update->execute([$position + 1, $id]);
        }
    }

    /** Fetches a single node at any level (used to return the fresh row after an update). */
    public function find(string $level, int $id): ?array
    {
        if (!in_array($level, self::LEVELS, true)) {
            return null;
        }
        $rows = $this->queryLevel($level, 'id = ?', [$id]);
        return $rows[0] ?? null;
    }

    private function queryLevel(string $table, string $where, array $params): array
    {
        // $table is only ever one of the literal strings above, never request input.
        $stmt = $this->db->prepare(
            "SELECT id, market, libelle, statut, image, icon, display_image, order_categorie, created
             FROM $table WHERE $where AND etat = '0'
             ORDER BY order_categorie ASC, id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['level'] = $table;
        }
        return $rows;
    }

    private function nextOrder(string $table, string $where, array $params): int
    {
        $stmt = $this->db->prepare("SELECT MAX(order_categorie) AS max_order FROM $table WHERE $where");
        $stmt->execute($params);
        $max = $stmt->fetch()['max_order'] ?? 0;
        return ((int) $max) + 1;
    }
}
