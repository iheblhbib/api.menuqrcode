<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * `event` has no `etat` soft-delete column (unlike categorie/article/qrcode),
 * only `statut` (Activer/Desactiver) — so destroy() here is a genuine SQL
 * DELETE, not a soft-delete, matching what the schema actually supports.
 */
class EventRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function listForMarket(int $marketId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, market, titre, texte, debut, fin, banner, statut, order_event, created
             FROM event WHERE market = ? ORDER BY order_event ASC, id DESC'
        );
        $stmt->execute([$marketId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, market, titre, texte, debut, fin, banner, statut, order_event, created FROM event WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $marketId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO event (market, titre, texte, debut, fin, statut, order_event, created)
             VALUES (:market, :titre, :texte, :debut, :fin, :statut, :order_event, NOW())'
        );
        $stmt->execute([
            ':market' => $marketId,
            ':titre' => $data['titre'] ?? null,
            ':texte' => $data['texte'] ?? null,
            ':debut' => $data['debut'] ?? null,
            ':fin' => $data['fin'] ?? null,
            ':statut' => $data['statut'] ?? 'Activer',
            ':order_event' => $this->nextOrder($marketId),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['titre', 'texte', 'debut', 'fin', 'statut', 'banner'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (empty($fields)) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE event SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM event WHERE id = ?')->execute([$id]);
    }

    private function nextOrder(int $marketId): int
    {
        $stmt = $this->db->prepare('SELECT MAX(order_event) AS max_order FROM event WHERE market = ?');
        $stmt->execute([$marketId]);
        $max = $stmt->fetch()['max_order'] ?? 0;
        return ((int) $max) + 1;
    }
}
