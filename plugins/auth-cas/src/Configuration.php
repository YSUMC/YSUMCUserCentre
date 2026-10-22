<?php

namespace AuthCAS;

use Option;

class Configuration
{
    public function render()
    {
        $form = Option::form(
            'auth_cas',
            trans('AuthCAS::config.title'),
            function ($form) {
                $form
                    ->text('cas_title', trans('AuthCAS::config.cas.title'))
                    ->placeholder(trans('AuthCAS::auth.title'));
                $form
                    ->text('cas_login', trans('AuthCAS::config.cas.login'))
                    ->placeholder(trans('AuthCAS::auth.cas.login'));
            }
        )->handle(function () {
            Option::set('cas_title', request('cas_title', 'null'));
            Option::set('cas_login', request('cas_login', 'null'));
            Option::set('cas_title_' . config('app.locale'), request('cas_title', 'null'));
            Option::set('cas_login_' . config('app.locale'), request('cas_login', 'null'));
        });

        return view('AuthCAS::config', compact('form'));
    }
}
