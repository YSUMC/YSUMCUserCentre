<?php

namespace LittleSkin\YggdrasilConnect\Middleware;

use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use LittleSkin\YggdrasilConnect\Exceptions\Yggdrasil\ForbiddenOperationException;

class CheckIfAuthServerDisabled
{
    private ClientRepository $clientRepository;

    public function __construct(ClientRepository $clientRepository)
    {
        $this->clientRepository = $clientRepository;
    }

    public function handle(Request $request, \Closure $next)
    {
        if (option('ygg_disable_authserver')) {
            throw new ForbiddenOperationException(trans('LittleSkin\\YggdrasilConnect::exceptions.yggc.authserver-disabled'));
        }

        $id = config('passport.personal_access_client.id');
        $client = $id ? $this->clientRepository->findActive($id) : null;

        if (empty($client) || !$client->hasGrantType('personal_access')) {
            throw new ForbiddenOperationException(trans('LittleSkin\\YggdrasilConnect::exceptions.yggc.invalid-client-id'));
        }

        return $next($request);
    }
}
