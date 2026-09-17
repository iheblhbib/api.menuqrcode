<?php

namespace App\Core;

/**
 * Enforces that an authenticated "client" (gestionnaire) JWT only touches
 * markets (= points de vente) it actually owns, per the market_ids claim.
 */
class MarketScope
{
    public static function assertOwned(Request $request, int $marketId): void
    {
        $ownedIds = $request->auth['market_ids'] ?? [];
        if (!in_array($marketId, $ownedIds, true)) {
            throw new ForbiddenException('This market does not belong to your account');
        }
    }
}
