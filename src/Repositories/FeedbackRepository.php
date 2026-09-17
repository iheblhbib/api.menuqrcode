<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Read-only: diner-submitted reviews (`feedback`), left via the public QR
 * menu — nothing in this app creates or edits them. `email`/`ip`/`browser`
 * are deliberately excluded from what's returned to the client app (no use
 * to a gérant, and mildly sensitive visitor data).
 */
class FeedbackRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function forMarket(int $marketId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, rating, commentaire, name, created
             FROM feedback
             WHERE market = ?
             ORDER BY created DESC"
        );
        $stmt->execute([$marketId]);
        return $stmt->fetchAll();
    }
}
