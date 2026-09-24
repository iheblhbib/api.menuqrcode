<?php

namespace App\Controllers;

use App\Config\Env;
use App\Core\AuthException;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Repositories\UserRepository;

class AuthController
{
    private const CLIENT_TYPES = ['gestionnaire'];
    private const ADMIN_TYPES = ['SuperAdmin', 'moderateur'];

    public function clientLogin(Request $request): void
    {
        $this->login($request, self::CLIENT_TYPES, 'client');
    }

    public function adminLogin(Request $request): void
    {
        $this->login($request, self::ADMIN_TYPES, 'super_admin');
    }

    private function login(Request $request, array $types, string $role): void
    {
        Validator::required($request->body, ['pseudo', 'password']);

        $identifier = trim((string) $request->input('pseudo'));
        $password = (string) $request->input('password');

        $users = new UserRepository();
        $account = $users->findByIdentifierAndTypes($identifier, $types);

        // Legacy scheme confirmed by the user: unsalted MD5, matching the existing web login.
        if (!$account || !hash_equals($account['password'], md5($password))) {
            throw new AuthException('Invalid username or password', 'INVALID_CREDENTIALS');
        }

        if ($account['statut'] !== 'Activer') {
            throw new AuthException('This account is disabled', 'ACCOUNT_DISABLED');
        }

        $this->respondWithTokens($account, $role);
    }

    public function refresh(Request $request): void
    {
        Validator::required($request->body, ['refresh_token']);

        $payload = Jwt::decode((string) $request->input('refresh_token'));
        if (($payload['type'] ?? null) !== 'refresh') {
            throw new AuthException('Not a refresh token', 'TOKEN_INVALID');
        }

        $role = $payload['role'];
        $types = $role === 'super_admin' ? self::ADMIN_TYPES : self::CLIENT_TYPES;

        $users = new UserRepository();
        $account = $users->findByIdAndTypes((int) $payload['sub'], $types);

        if (!$account) {
            throw new AuthException('Account no longer exists', 'ACCOUNT_NOT_FOUND');
        }

        if ($account['statut'] !== 'Activer') {
            throw new AuthException('This account is disabled', 'ACCOUNT_DISABLED');
        }

        $this->respondWithTokens($account, $role);
    }

    public function logout(Request $request): void
    {
        // Stateless JWT: the client discards its tokens. Server-side revocation
        // (e.g. a token_version column bumped here) is deferred to Phase 2.
        Response::success(['message' => 'Logged out']);
    }

    public function me(Request $request): void
    {
        $auth = $request->auth;
        // The JWT's own subject claim is "sub" (standard claim name), but the
        // client's AuthAccount model expects "id" — same shape login() already
        // returns in its "account" object.
        $auth['id'] = $auth['sub'];
        unset($auth['sub'], $auth['iat'], $auth['exp'], $auth['type']);
        Response::success($auth);
    }

    private function respondWithTokens(array $account, string $role): void
    {
        $extraClaims = [];
        if ($role === 'client') {
            $users = new UserRepository();
            $extraClaims = [
                'client_id' => (int) $account['id'],
                'market_ids' => $users->marketIdsForGestionnaire((int) $account['id']),
            ];
        }

        $baseClaims = array_merge([
            'sub' => (int) $account['id'],
            'role' => $role,
            'email' => $account['email'],
            'name' => $account['name'],
        ], $extraClaims);

        $accessToken = Jwt::encode(
            array_merge($baseClaims, ['type' => 'access']),
            (int) Env::get('JWT_ACCESS_TTL', '3600')
        );
        $refreshToken = Jwt::encode(
            ['sub' => (int) $account['id'], 'role' => $role, 'type' => 'refresh'],
            (int) Env::get('JWT_REFRESH_TTL', '2592000')
        );

        Response::success([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'account' => array_merge(
                ['id' => (int) $account['id'], 'email' => $account['email'], 'name' => $account['name']],
                $extraClaims
            ),
        ]);
    }
}
