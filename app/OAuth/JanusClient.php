<?php

namespace App\OAuth;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Laravel\Passport\Client;

/**
 * Janus and Passport share the legacy oauth_clients table. Janus needs the
 * original secret for its OIDC client authentication. Keep that existing
 * storage contract until both services migrate together; never hash it in place.
 */
class JanusClient extends Client
{
    protected function secret(): Attribute
    {
        return Attribute::make(set: function (?string $value): ?string {
            $this->plainSecret = $value;

            return $value;
        });
    }
}
