<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Aggregate counts for the client dashboard, scoped to one or more markets.
 * Mirrors the counting logic already used by the existing web dashboard
 * (config.php: CountAccess, countClients-style patterns) rather than
 * inventing new business logic.
 */
class StatsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function forMarkets(array $marketIds): array
    {
        if (empty($marketIds)) {
            return [
                'total_categories' => 0,
                'total_menu_items' => 0,
                'total_scans' => 0,
                'feedback_count' => 0,
                'feedback_average' => null,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($marketIds), '?'));

        return [
            'total_categories' => $this->count("SELECT COUNT(*) FROM categorie WHERE market IN ($placeholders) AND etat = '0'", $marketIds),
            'total_menu_items' => $this->count("SELECT COUNT(*) FROM article WHERE market IN ($placeholders) AND etat = '0'", $marketIds),
            'total_scans' => $this->count("SELECT COUNT(*) FROM acces_liste WHERE market IN ($placeholders)", $marketIds),
            'feedback_count' => $this->count("SELECT COUNT(*) FROM feedback WHERE market IN ($placeholders)", $marketIds),
            'feedback_average' => $this->average("SELECT AVG(rating) FROM feedback WHERE market IN ($placeholders) AND rating IS NOT NULL AND rating != ''", $marketIds),
        ];
    }

    /** Platform-wide stats for the Admin dashboard — mirrors config.php's countClients/CountMarkets/totalRevenue. */
    public function global(): array
    {
        return [
            'total_clients' => $this->count("SELECT COUNT(*) FROM user WHERE type = 'gestionnaire' AND statut = 'Activer'", []),
            'total_markets' => $this->count(
                "SELECT COUNT(*) FROM market WHERE gestionnaire IN (SELECT id FROM user WHERE statut = 'Activer')",
                []
            ),
            'total_categories' => $this->count("SELECT COUNT(*) FROM categorie WHERE etat = '0'", []),
            'total_menu_items' => $this->count("SELECT COUNT(*) FROM article WHERE etat = '0'", []),
            'total_qr_codes' => $this->count("SELECT COUNT(*) FROM qrcode WHERE statut = '0'", []),
            'feedback_count' => $this->count('SELECT COUNT(*) FROM feedback', []),
            'total_revenue' => $this->average('SELECT SUM(montant) FROM paiement', []),
        ];
    }

    private function count(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function average(string $sql, array $params): ?float
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value !== null ? round((float) $value, 2) : null;
    }
}
