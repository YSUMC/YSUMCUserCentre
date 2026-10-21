<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\AuthCode;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

final class RevokeUserTokens
{
    public function revoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Include expired access tokens: their refresh tokens can outlive them.
            RefreshToken::whereIn('access_token_id', Token::where('user_id', $user->uid)->select('id'))
                ->update(['revoked' => true]);
            Token::where('user_id', $user->uid)->update(['revoked' => true]);

            // Janus uses Passport code IDs as grant IDs. Removing both stops
            // pending authorization codes/device grants as well as refreshes.
            if (Schema::hasTable('yggc_grants')) {
                DB::table('yggc_grants')->whereIn('id', AuthCode::where('user_id', $user->uid)->select('id'))->delete();
            }
            AuthCode::where('user_id', $user->uid)->delete();
        });
    }
}
