<?php

namespace App\Core;

class AuthMiddleware
{
    /**
     * Returns a middleware closure that requires a valid JWT whose "role" claim
     * matches one of the given roles (e.g. "client" or "super_admin"), keeping
     * the client and admin APIs cleanly separated as required by the plan.
     */
    public static function role(string ...$roles): callable
    {
        return function (Request $request) use ($roles): void {
            $token = $request->bearerToken();
            if (!$token) {
                throw new AuthException('Missing bearer token');
            }

            $payload = Jwt::decode($token);

            if (!in_array($payload['role'] ?? null, $roles, true)) {
                throw new ForbiddenException('This account role cannot access this resource');
            }

            $request->auth = $payload;
        };
    }
}
