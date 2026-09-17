<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

class MarketRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** Columns a gérant may self-service edit (Paramètres screen) — never gestionnaire/statut/theme/etc. */
    private const SETTINGS_FIELDS = [
        'description', 'address', 'tel', 'email', 'siteweb',
        'wifi', 'wifi_ssid', 'wifi_password', 'wifi_type',
        'facebook', 'instagram', 'tiktok', 'linkedin', 'youtube', 'twitter', 'whatsapp', 'google', 'tripadvisor',
    ];

    public function listByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, market, description, logo, address, statut, tel, email, created
             FROM market WHERE id IN ($placeholders) ORDER BY market ASC"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $columns = implode(', ', ['id', 'market', 'description', 'logo', 'address', 'statut', 'created', ...self::SETTINGS_FIELDS]);
        $stmt = $this->db->prepare("SELECT $columns FROM market WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (self::SETTINGS_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (empty($fields)) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE market SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
    }
}
