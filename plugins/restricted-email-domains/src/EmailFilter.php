<?php

namespace Blessing\RestrictedEmailDomains;

use Blessing\Rejection;
use Illuminate\Support\Str;

class EmailFilter
{
    public function filter($can)
    {
        if ($can instanceof Rejection) {
            return $can;
        }
        $allowList = DomainList::read('allow');
        $denyList = DomainList::read('deny');

        $request = request();
        if (!$request->has('email')) {
            return $can;
        }

        $email = $request->input('email', '');
        if (!is_string($email) || substr_count($email, '@') !== 1) {
            return $can;
        }

        [$head, $domain] = explode('@', $email);
        $domain = DomainList::normalize($domain);
        if ($domain === null) {
            return $can;
        }

        if (count($allowList) > 0 && !in_array($domain, $allowList, true)) {
            return new Rejection(
                trans('Blessing\RestrictedEmailDomains::general.rejection', compact('domain'))
            );
        }
        if (count($denyList) > 0 && in_array($domain, $denyList, true)) {
            return new Rejection(
                trans('Blessing\RestrictedEmailDomains::general.rejection', compact('domain'))
            );
        }

        return $can;
    }
}
