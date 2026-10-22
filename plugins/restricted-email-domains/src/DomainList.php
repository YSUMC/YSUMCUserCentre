<?php

namespace Blessing\RestrictedEmailDomains;

final class DomainList
{
    public static function normalize(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = ltrim($domain, '@');
        if (function_exists('idn_to_ascii')) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }
        return preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $domain) ? $domain : null;
    }

    public static function read(string $list): array
    {
        $values = json_decode((string) option('restricted-email-domains.'.$list, '[]'), true);
        if (!is_array($values)) {
            return [];
        }
        $domains = [];
        foreach ($values as $value) {
            if (is_string($value) && ($domain = self::normalize($value)) !== null) {
                $domains[] = $domain;
            }
        }
        return array_values(array_unique($domains));
    }
}
