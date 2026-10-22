<?php

namespace Blessing\RestrictedEmailDomains;

use App\Services\Hook;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigController extends Controller
{
    public function render()
    {
        Hook::addScriptFileToPage(plugin('restricted-email-domains')->assets('config.js'));

        return view('Blessing\RestrictedEmailDomains::config', [
            'allow' => DomainList::read('allow'),
            'deny' => DomainList::read('deny'),
        ]);
    }

    public function list()
    {
        $allowList = DomainList::read('allow');
        $denyList = DomainList::read('deny');

        return [
            'allow' => array_values($allowList),
            'deny' => array_values($denyList),
        ];
    }

    public function save(Request $request, $list)
    {
        abort_unless(in_array($list, ['allow', 'deny'], true), 404);
        $values = $request->json()->all();
        if (!array_is_list($values)) {
            throw ValidationException::withMessages(['domains' => 'Expected a list of email domains.']);
        }
        Validator::make(['domains' => $values], [
            'domains' => ['array', 'max:200'],
            'domains.*' => ['required', 'string', 'max:253'],
        ])->validate();
        $domains = [];
        foreach ($values as $value) {
            $domain = DomainList::normalize($value);
            if ($domain === null) {
                throw ValidationException::withMessages(['domains' => 'Invalid email domain.']);
            }
            $domains[] = $domain;
        }
        $json = json_encode(array_values(array_unique($domains)), JSON_THROW_ON_ERROR);
        option(["restricted-email-domains.$list" => $json]);

        return response()->noContent();
    }
}
