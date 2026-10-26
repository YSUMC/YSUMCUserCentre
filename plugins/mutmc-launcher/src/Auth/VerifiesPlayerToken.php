<?php

namespace MUTMC\Launcher\Auth;

interface VerifiesPlayerToken
{
    /** @return array{uid: int, player: string}|null */
    public function verifiedIdentity(string $token): ?array;
}
