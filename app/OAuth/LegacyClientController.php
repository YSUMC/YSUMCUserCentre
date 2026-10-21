<?php

namespace App\OAuth;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\ClientController;

/** Preserve the existing first-party OAuth management UI's owner-only contract. */
class LegacyClientController extends ClientController
{
    public function forUser(Request $request): Collection
    {
        return parent::forUser($request)->each->makeVisible('secret');
    }

    public function store(Request $request): Client
    {
        return parent::store($request)->makeVisible('secret');
    }
}
