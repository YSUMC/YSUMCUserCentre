<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class PasswordResetTokens
{
    public function issue(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put($this->key($user), [
            'hash' => hash('sha256', $token),
            'credentials' => $this->credentials($user),
        ], now()->addHour());

        return $token;
    }

    public function valid(User $user, mixed $token): bool
    {
        return $this->matches($user, $token, Cache::get($this->key($user)));
    }

    /** Call while holding the user's database row lock. */
    public function consume(User $user, mixed $token): bool
    {
        if (!$this->valid($user, $token)) {
            return false;
        }

        return $this->matches($user, $token, Cache::pull($this->key($user)));
    }

    public function forget(User $user): void
    {
        Cache::forget($this->key($user));
    }

    private function matches(User $user, mixed $token, mixed $record): bool
    {
        return is_string($token) && strlen($token) === 64 && is_array($record)
            && isset($record['hash'], $record['credentials'])
            && hash_equals($record['hash'], hash('sha256', $token))
            && hash_equals($record['credentials'], $this->credentials($user));
    }

    private function key(User $user): string
    {
        return 'password-reset:'.$user->uid;
    }

    private function credentials(User $user): string
    {
        return hash('sha256', $user->email."\0".$user->getAuthPassword());
    }
}
