<?php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Admin-side CRUD over "Client" accounts (user.type = 'gestionnaire'). This
 * is a distinct repository from UserRepository (which only ever reads, for
 * login) since the admin app can create/edit/deactivate accounts.
 */
class AdminUserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function list(?string $search = null): array
    {
        $sql = "SELECT id, name, societe, email, num, statut, pack, montant, echeance, pro, created
                FROM user WHERE type = 'gestionnaire'";
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' AND (name LIKE ? OR email LIKE ? OR societe LIKE ?)';
            $like = "%$search%";
            $params = [$like, $like, $like];
        }
        $sql .= ' ORDER BY created DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, societe, email, num, num_mobile, statut, pack, montant, echeance, pro, created
             FROM user WHERE id = ? AND type = 'gestionnaire' LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM user WHERE email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO user (name, societe, email, num, password, type, statut, created)
             VALUES (:name, :societe, :email, :num, :password, 'gestionnaire', :statut, NOW())"
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':societe' => $data['societe'] ?? null,
            ':email' => $data['email'],
            ':num' => $data['num'] ?? null,
            // Legacy scheme (see AuthController) — unsalted MD5, matching the
            // existing web login, not something this API introduces.
            ':password' => md5($data['password']),
            ':statut' => $data['statut'] ?? 'Activer',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];

        foreach (['name', 'societe', 'email', 'num', 'statut'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $fields[] = 'password = :password';
            $params[':password'] = md5($data['password']);
        }

        if (empty($fields)) {
            return;
        }

        $stmt = $this->db->prepare("UPDATE user SET " . implode(', ', $fields) . " WHERE id = :id AND type = 'gestionnaire'");
        $stmt->execute($params);
    }

    /** No hard delete for accounts — deactivate instead, matching the existing web app's convention. */
    public function setStatus(int $id, string $statut): void
    {
        $stmt = $this->db->prepare("UPDATE user SET statut = ? WHERE id = ? AND type = 'gestionnaire'");
        $stmt->execute([$statut, $id]);
    }

    public function marketCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM market WHERE gestionnaire = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
