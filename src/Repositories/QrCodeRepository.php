<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * "Standard" QR entries: the `qrcode` table (owned by a gestionnaire/Client),
 * linked to one or more markets via `market_qrcode` (etat = '1' active link).
 * `statut` on `qrcode` is repurposed as a soft-delete flag ('0' active, '1'
 * deleted) for consistency with categorie/article — not yet confirmed against
 * real usage, see docs/db-reconciliation.md.
 */
class QrCodeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function listForGestionnaire(int $gestionnaireId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, libelle, url, link, created FROM qrcode WHERE gestionnaire = ? AND statut = '0' ORDER BY id DESC"
        );
        $stmt->execute([$gestionnaireId]);
        $qrcodes = $stmt->fetchAll();

        foreach ($qrcodes as &$qr) {
            $qr['market_ids'] = $this->marketIdsForQrCode((int) $qr['id']);
        }

        return $qrcodes;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, libelle, gestionnaire, url, link, created FROM qrcode WHERE id = ? AND statut = '0' LIMIT 1"
        );
        $stmt->execute([$id]);
        $qr = $stmt->fetch();
        if (!$qr) {
            return null;
        }
        $qr['market_ids'] = $this->marketIdsForQrCode($id);
        return $qr;
    }

    public function create(int $gestionnaireId, string $libelle, ?string $url, ?string $link, array $marketIds): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO qrcode (libelle, gestionnaire, url, link, statut, created) VALUES (?, ?, ?, ?, '0', NOW())"
        );
        $stmt->execute([$libelle, $gestionnaireId, $url, $link]);
        $id = (int) $this->db->lastInsertId();

        $this->linkMarkets($id, $marketIds);

        return $id;
    }

    public function update(int $id, array $data, ?array $marketIds): void
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['libelle', 'url', 'link'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (!empty($fields)) {
            $stmt = $this->db->prepare('UPDATE qrcode SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $stmt->execute($params);
        }

        if ($marketIds !== null) {
            $this->db->prepare('DELETE FROM market_qrcode WHERE qrcode = ?')->execute([$id]);
            $this->linkMarkets($id, $marketIds);
        }
    }

    public function softDelete(int $id): void
    {
        $this->db->prepare("UPDATE qrcode SET statut = '1' WHERE id = ?")->execute([$id]);
    }

    public function marketIdsForQrCode(int $qrcodeId): array
    {
        $stmt = $this->db->prepare("SELECT market FROM market_qrcode WHERE qrcode = ? AND etat = '1'");
        $stmt->execute([$qrcodeId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'market'));
    }

    private function linkMarkets(int $qrcodeId, array $marketIds): void
    {
        $stmt = $this->db->prepare("INSERT INTO market_qrcode (market, qrcode, etat) VALUES (?, ?, '1')");
        foreach ($marketIds as $marketId) {
            $stmt->execute([(int) $marketId, $qrcodeId]);
        }
    }

    // --- Custom QR style (table "qrcodes", one row per market) ---

    public function customStyleForMarket(int $marketId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM qrcodes WHERE market = ? LIMIT 1');
        $stmt->execute([$marketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private const CUSTOM_STYLE_FIELDS = [
        'data', 'width', 'height', 'margin', 'qr_error_correction_level',
        'dots_type', 'dots_color_type', 'dots_color',
        'dots_gradient_type', 'dots_gradient_color1', 'dots_gradient_color2', 'dots_gradient_rotation',
        'corners_square_type', 'corners_square_color_type', 'corners_square_color',
        'corners_square_gradient_type', 'corners_square_gradient_color1', 'corners_square_gradient_color2', 'corners_square_gradient_rotation',
        'corners_dot_type', 'corners_dot_color_type', 'corners_dot_color',
        'corners_dot_gradient_type', 'corners_dot_gradient_color1', 'corners_dot_gradient_color2', 'corners_dot_gradient_rotation',
        'background_color_type', 'background_color',
        'background_gradient_type', 'background_gradient_color1', 'background_gradient_color2', 'background_gradient_rotation',
        'image_size', 'image_margin',
    ];

    public function upsertCustomStyle(int $marketId, array $data): void
    {
        $fields = array_intersect_key($data, array_flip(self::CUSTOM_STYLE_FIELDS));

        if ($this->customStyleForMarket($marketId)) {
            if (empty($fields)) {
                return;
            }
            $set = implode(', ', array_map(fn ($f) => "$f = :$f", array_keys($fields)));
            $params = [];
            foreach ($fields as $k => $v) {
                $params[":$k"] = $v;
            }
            $params[':market'] = $marketId;
            $this->db->prepare("UPDATE qrcodes SET $set WHERE market = :market")->execute($params);
            return;
        }

        $columns = array_merge(['market'], array_keys($fields));
        $placeholders = array_merge([':market'], array_map(fn ($f) => ":$f", array_keys($fields)));
        $params = [':market' => $marketId];
        foreach ($fields as $k => $v) {
            $params[":$k"] = $v;
        }

        $sql = 'INSERT INTO qrcodes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->db->prepare($sql)->execute($params);
    }
}
