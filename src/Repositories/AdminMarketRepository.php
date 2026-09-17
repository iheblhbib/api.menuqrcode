<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** Admin-side transverse view over `market` (all clients), unlike the client-scoped MarketRepository. */
class AdminMarketRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function list(?int $clientId = null): array
    {
        $sql = "SELECT m.id, m.market, m.gestionnaire, u.name AS gestionnaire_name, m.statut, m.created
                FROM market m
                LEFT JOIN user u ON u.id = m.gestionnaire";
        $params = [];
        if ($clientId !== null) {
            $sql .= ' WHERE m.gestionnaire = ?';
            $params[] = $clientId;
        }
        $sql .= ' ORDER BY m.created DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castGestionnaire'], $stmt->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.market, m.description, m.gestionnaire, u.name AS gestionnaire_name, m.statut, m.address, m.tel, m.email, m.created
             FROM market m LEFT JOIN user u ON u.id = m.gestionnaire
             WHERE m.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->castGestionnaire($row) : null;
    }

    /**
     * `market.gestionnaire` is a legacy `varchar(255)` column (unlike the
     * proper `int` used everywhere else for this same concept), so PDO
     * returns it as a string — cast it here so the API always exposes a
     * consistent int (or null), matching client_id/market_ids elsewhere.
     */
    private function castGestionnaire(array $row): array
    {
        $row['gestionnaire'] = $row['gestionnaire'] !== null && $row['gestionnaire'] !== ''
            ? (int) $row['gestionnaire']
            : null;
        return $row;
    }
}
