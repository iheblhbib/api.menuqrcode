<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Reads/writes the existing "user" table (types: SuperAdmin, gestionnaire,
 * moderateur, employee) reconciled from the real schema in M0. "gestionnaire"
 * is a restaurant-owner ("Client") account; "SuperAdmin"/"moderateur" are
 * platform-admin accounts. "employee" (staff scoped to specific markets via
 * user_market/user_route) is out of MVP scope.
 */
class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * The real site's login form asks for "pseudo" (username), not email —
     * confirmed live (input name="pseudo" on https://app.menuqrcode.tn) and
     * by the data: only 217/255 gestionnaire accounts have an email set,
     * vs. 254/255 for pseudo. Matches either column so an account that does
     * have a real email attached keeps working if someone types that instead.
     */
    public function findByIdentifierAndTypes(string $identifier, array $types): ?array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, name, email, password, statut, type FROM user WHERE (pseudo = ? OR email = ?) AND type IN ($placeholders) LIMIT 1"
        );
        $stmt->execute(array_merge([$identifier, $identifier], $types));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByIdAndTypes(int $id, array $types): ?array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, name, email, statut, type FROM user WHERE id = ? AND type IN ($placeholders) LIMIT 1"
        );
        $stmt->execute(array_merge([$id], $types));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Market (= point de vente) ids owned by a "gestionnaire" (Client) account. */
    public function marketIdsForGestionnaire(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM market WHERE gestionnaire = ? ORDER BY id ASC');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
}
