<?php

namespace App\OAuth;

use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use RuntimeException;

class ConfiguredClientRepository extends ClientRepository
{
    public function personalAccessClient(string $provider): Client
    {
        $id = config('passport.personal_access_client.id');
        if ($id === null || $id === '') {
            return parent::personalAccessClient($provider);
        }

        $client = $this->findActive($id);
        if (!$client || !$client->hasGrantType('personal_access')
            || ($client->provider !== null && $client->provider !== $provider)) {
            throw new RuntimeException('Configured personal access client is unavailable for this provider.');
        }

        return $client;
    }
}
