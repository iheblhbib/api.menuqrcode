<?php

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Helpers\Validator;
use App\Repositories\AdminUserRepository;

/**
 * Admin-side CRUD for "Client" accounts (restaurant owners), matching the
 * back-office's "Clients" section (2nd sidebar in the plan). Distinct from
 * /client/* — this is super_admin-only (see AuthMiddleware::role in index.php).
 */
class AdminClientController
{
    public function index(Request $request): void
    {
        $items = (new AdminUserRepository())->list($request->input('search'));
        Response::success($items);
    }

    public function show(Request $request): void
    {
        Response::success($this->findOrFail((int) $request->params['id']));
    }

    public function store(Request $request): void
    {
        Validator::required($request->body, ['name', 'email', 'password']);

        $repo = new AdminUserRepository();
        if ($repo->emailExists((string) $request->input('email'))) {
            throw new ValidationException(['email' => 'This email is already in use']);
        }

        $id = $repo->create([
            'name' => $request->input('name'),
            'societe' => $request->input('societe'),
            'email' => $request->input('email'),
            'num' => $request->input('num'),
            'password' => $request->input('password'),
            'statut' => $request->input('statut', 'Activer'),
        ]);

        Response::success($repo->find($id), 201);
    }

    public function update(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($id);

        $repo = new AdminUserRepository();
        $repo->update($id, array_intersect_key(
            $request->body,
            array_flip(['name', 'societe', 'email', 'num', 'statut', 'password'])
        ));

        Response::success($repo->find($id));
    }

    /** "Delete" = deactivate (statut='Desactiver') — no hard delete for accounts, matching the web app. */
    public function destroy(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($id);

        (new AdminUserRepository())->setStatus($id, 'Desactiver');
        Response::success(['message' => 'Client deactivated']);
    }

    public function reactivate(Request $request): void
    {
        $id = (int) $request->params['id'];
        $this->findOrFail($id);

        (new AdminUserRepository())->setStatus($id, 'Activer');
        Response::success(['message' => 'Client reactivated']);
    }

    private function findOrFail(int $id): array
    {
        $client = (new AdminUserRepository())->find($id);
        if (!$client) {
            throw new NotFoundException('Client not found');
        }
        return $client;
    }
}
