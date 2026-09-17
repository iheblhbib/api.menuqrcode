<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** Admin-side transverse view over `qrcode` (all clients). */
class AdminQrCodeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function list(?int $clientId = null): array
    {
        $sql = "SELECT q.id, q.libelle, q.gestionnaire, u.name AS gestionnaire_name, q.link, q.created
                FROM qrcode q
                LEFT JOIN user u ON u.id = q.gestionnaire
                WHERE q.statut = '0'";
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND q.gestionnaire = ?';
            $params[] = $clientId;
        }
        $sql .= ' ORDER BY q.created DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
