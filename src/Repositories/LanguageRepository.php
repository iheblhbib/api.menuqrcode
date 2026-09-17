<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Global language catalog (`langue`) and per-market enablement
 * (`market_langue`).
 *
 * `market_langue.etat` is `enum('1','2')` — NOT the usual '0'/'1' soft-delete
 * convention used elsewhere in this schema. Inferred from real data (not
 * confirmed against the web app's own source, same caveat as
 * `qrcode.statut` — see api-contract.md): every market is pre-seeded with
 * one row per catalog language, all defaulting to '2'; markets that were
 * actually configured by their gérant have their chosen languages flipped
 * to '1'. So '1' = enabled for this market, '2' = available but not enabled.
 */
class LanguageRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** Every active catalog language, each flagged whether [marketId] has it enabled. */
    public function forMarket(int $marketId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.id, l.libelle, l.code, l.drapeau, l.img,
                    (ml.etat = '1') AS enabled
             FROM langue l
             LEFT JOIN market_langue ml ON ml.langue = l.id AND ml.market = :market
             WHERE l.statut = 'Activer'
             ORDER BY l.libelle ASC"
        );
        $stmt->execute([':market' => $marketId]);

        return array_map(static function (array $row): array {
            $row['enabled'] = (bool) $row['enabled'];
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * `market_langue` has no unique constraint on (market, langue) — check
     * for an existing row rather than relying on ON DUPLICATE KEY UPDATE.
     */
    public function setEnabled(int $marketId, int $langueId, bool $enabled): void
    {
        $etat = $enabled ? '1' : '2';

        $find = $this->db->prepare('SELECT id FROM market_langue WHERE market = ? AND langue = ? LIMIT 1');
        $find->execute([$marketId, $langueId]);
        $existingId = $find->fetchColumn();

        if ($existingId !== false) {
            $update = $this->db->prepare('UPDATE market_langue SET etat = ? WHERE id = ?');
            $update->execute([$etat, $existingId]);
            return;
        }

        $insert = $this->db->prepare('INSERT INTO market_langue (market, langue, etat) VALUES (?, ?, ?)');
        $insert->execute([$marketId, $langueId, $etat]);
    }
}
