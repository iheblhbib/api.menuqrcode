<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Per-language translations for the three category levels above an article
 * (`langue_categorie`, `langue_categorie_sub`, `langue_categorie_sub_sub`).
 * All three tables share an identical shape and, like the base
 * `categorie_sub`/`categorie_sub_sub` tables, use a column literally named
 * `categorie` for the parent id regardless of level (historical naming
 * quirk — see CategoryTreeRepository).
 */
class CategoryTranslationRepository
{
    private const TABLES = [
        'categorie' => 'langue_categorie',
        'categorie_sub' => 'langue_categorie_sub',
        'categorie_sub_sub' => 'langue_categorie_sub_sub',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public static function isValidLevel(string $level): bool
    {
        return isset(self::TABLES[$level]);
    }

    /** Existing translations for this entity, one row per language that has one. */
    public function listFor(string $level, int $entityId): array
    {
        $table = self::TABLES[$level];
        $stmt = $this->db->prepare(
            "SELECT t.langue AS langue_id, l.code, l.libelle AS language_libelle, t.libelle
             FROM $table t
             JOIN langue l ON l.id = t.langue
             WHERE t.categorie = ?
             ORDER BY l.libelle ASC"
        );
        $stmt->execute([$entityId]);
        return $stmt->fetchAll();
    }

    public function upsert(string $level, int $entityId, int $langueId, ?string $libelle): void
    {
        $table = self::TABLES[$level];
        $code = $this->codeFor($langueId);

        $stmt = $this->db->prepare(
            "INSERT INTO $table (langue, categorie, libelle, code) VALUES (:langue, :categorie, :libelle, :code)
             ON DUPLICATE KEY UPDATE libelle = VALUES(libelle), code = VALUES(code)"
        );
        $stmt->execute([':langue' => $langueId, ':categorie' => $entityId, ':libelle' => $libelle, ':code' => $code]);
    }

    public function delete(string $level, int $entityId, int $langueId): void
    {
        $table = self::TABLES[$level];
        $stmt = $this->db->prepare("DELETE FROM $table WHERE categorie = ? AND langue = ?");
        $stmt->execute([$entityId, $langueId]);
    }

    private function codeFor(int $langueId): ?string
    {
        $stmt = $this->db->prepare('SELECT code FROM langue WHERE id = ?');
        $stmt->execute([$langueId]);
        $code = $stmt->fetchColumn();
        return $code !== false ? $code : null;
    }
}
