<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** Per-language translations for menu items (`langue_article`). */
class ArticleTranslationRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** Existing translations for this article, one row per language that has one. */
    public function listFor(int $articleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.langue AS langue_id, l.code, l.libelle AS language_libelle, t.libelle, t.prix, t.description
             FROM langue_article t
             JOIN langue l ON l.id = t.langue
             WHERE t.article = ?
             ORDER BY l.libelle ASC"
        );
        $stmt->execute([$articleId]);
        return $stmt->fetchAll();
    }

    public function upsert(int $articleId, int $langueId, ?string $libelle, ?string $prix, ?string $description): void
    {
        $code = $this->codeFor($langueId);

        $stmt = $this->db->prepare(
            "INSERT INTO langue_article (langue, article, libelle, code, prix, description)
             VALUES (:langue, :article, :libelle, :code, :prix, :description)
             ON DUPLICATE KEY UPDATE libelle = VALUES(libelle), code = VALUES(code), prix = VALUES(prix), description = VALUES(description)"
        );
        $stmt->execute([
            ':langue' => $langueId,
            ':article' => $articleId,
            ':libelle' => $libelle,
            ':code' => $code,
            ':prix' => $prix,
            ':description' => $description,
        ]);
    }

    public function delete(int $articleId, int $langueId): void
    {
        $stmt = $this->db->prepare('DELETE FROM langue_article WHERE article = ? AND langue = ?');
        $stmt->execute([$articleId, $langueId]);
    }

    private function codeFor(int $langueId): ?string
    {
        $stmt = $this->db->prepare('SELECT code FROM langue WHERE id = ?');
        $stmt->execute([$langueId]);
        $code = $stmt->fetchColumn();
        return $code !== false ? $code : null;
    }
}
