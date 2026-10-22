<?php

namespace App\OAuth;

use Laravel\Passport\Bridge\ClientRepository;

class JanusClientRepository extends ClientRepository
{
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = $this->clients->findActive($clientIdentifier);
        if (!$client || $clientSecret === null || $clientSecret === '' || !$client->confidential()) {
            return false;
        }

        $stored = $client->secret;
        if (password_get_info($stored)['algo'] !== null) {
            return password_verify($clientSecret, $stored);
        }

        return hash_equals($stored, $clientSecret);
    }
}
