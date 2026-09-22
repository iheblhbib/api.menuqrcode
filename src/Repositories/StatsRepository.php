<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Aggregate counts/series for the client dashboard, scoped to one or more
 * markets. Mirrors the counting logic already used by the existing web
 * dashboard (config.php: CountAccess, countClients-style patterns) rather
 * than inventing new business logic.
 *
 * Schema notes confirmed against the real DB (not the assumptions in the
 * legacy dashboard-section.php reference, which suggested `market` stores a
 * name string — it's actually an int id, same as everywhere else in this
 * API): `acces_liste`/`feedback`.`market` = market.id (int). `login` has no
 * market column at all — a login is an account-level event (`login`.`user`
 * = user.id), not tied to one établissement, so "connexions"/"logins series"
 * are scoped by client_id, independent of which market is selected.
 */
class StatsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @param int[] $marketIds
     * @param int $clientId gestionnaire's own user.id (for connexions, which aren't market-scoped)
     * @param int|null $days null = all-time; otherwise restricts acces/feedback/connexions counts
     *   to the last N days (category/article counts are never period-filtered — they're a
     *   current-state total, not an activity metric).
     */
    public function forMarkets(array $marketIds, int $clientId, ?int $days = null): array
    {
        if (empty($marketIds)) {
            return [
                'total_categories' => 0,
                'total_menu_items' => 0,
                'total_scans' => 0,
                'feedback_count' => 0,
                'feedback_average' => null,
                'connexions' => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
        $period = $days !== null ? "AND created >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : '';

        return [
            'total_categories' => $this->count("SELECT COUNT(*) FROM categorie WHERE market IN ($placeholders) AND etat = '0'", $marketIds),
            'total_menu_items' => $this->count("SELECT COUNT(*) FROM article WHERE market IN ($placeholders) AND etat = '0'", $marketIds),
            'total_scans' => $this->count("SELECT COUNT(*) FROM acces_liste WHERE market IN ($placeholders) $period", $marketIds),
            'feedback_count' => $this->count("SELECT COUNT(*) FROM feedback WHERE market IN ($placeholders) $period", $marketIds),
            'feedback_average' => $this->average("SELECT AVG(rating) FROM feedback WHERE market IN ($placeholders) AND rating IS NOT NULL AND rating != ''", $marketIds),
            'connexions' => $this->count(
                "SELECT COUNT(*) FROM login WHERE user = ? " . ($days !== null ? "AND created >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : ''),
                [$clientId]
            ),
        ];
    }

    /** Daily or monthly access counts for the "Accès par jour" chart. @param int[] $marketIds */
    public function accessSeries(array $marketIds, int $days, string $granularity): array
    {
        if (empty($marketIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($marketIds), '?'));

        if ($granularity === 'month') {
            $stmt = $this->db->prepare(
                "SELECT DATE_FORMAT(created, '%Y-%m') AS periode, COUNT(*) AS total
                 FROM acces_liste
                 WHERE market IN ($placeholders) AND created >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY DATE_FORMAT(created, '%Y-%m')
                 ORDER BY periode ASC"
            );
        } else {
            $stmt = $this->db->prepare(
                "SELECT DATE(created) AS periode, COUNT(*) AS total
                 FROM acces_liste
                 WHERE market IN ($placeholders) AND created >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY DATE(created)
                 ORDER BY periode ASC"
            );
        }
        $stmt->execute($marketIds);
        return $stmt->fetchAll();
    }

    /** Daily login counts for this account (not market-scoped — see class docblock). */
    public function loginsSeries(int $clientId, int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created) AS periode, COUNT(*) AS total
             FROM login
             WHERE user = ? AND created >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
             GROUP BY DATE(created)
             ORDER BY periode ASC"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    /** Distribution of feedback ratings (1-5) for the donut chart. @param int[] $marketIds */
    public function ratingsDistribution(array $marketIds, int $days): array
    {
        if (empty($marketIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT rating, COUNT(*) AS total
             FROM feedback
             WHERE market IN ($placeholders)
               AND rating IS NOT NULL AND rating != ''
               AND created >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
             GROUP BY rating
             ORDER BY rating ASC"
        );
        $stmt->execute($marketIds);
        return $stmt->fetchAll();
    }

    /** Top browsers among QR scans, for the "Navigateurs" bar chart. @param int[] $marketIds */
    public function browserStats(array $marketIds, int $days, int $limit): array
    {
        if (empty($marketIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT browser, COUNT(*) AS total
             FROM acces_liste
             WHERE market IN ($placeholders)
               AND browser IS NOT NULL AND browser != ''
               AND created >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
             GROUP BY browser
             ORDER BY total DESC
             LIMIT {$limit}"
        );
        $stmt->execute($marketIds);
        return $stmt->fetchAll();
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
