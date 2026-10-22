<?php

use App\Services\Hook;
use Blessing\Filter;
use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher;

return function (Dispatcher $events, Filter $filter) {
    Hook::addScriptFileToPage(
        plugin('invitation-codes')->assets('register.js'),
        ['auth/register']
    );
    Hook::addScriptFileToPage(
        plugin('invitation-codes')->assets('admin.js'),
        ['admin/invitation-codes']
    );

    Hook::addRoute(function () {
        Route::namespace('InvitationCodes')
            ->middleware(['web', 'authorize', 'role:admin'])
            ->prefix('admin/invitation-codes')
            ->group(function () {
                Route::get('', 'InvitationCodeController@list');
                Route::post('generate', 'InvitationCodeController@generate');
                Route::put('', 'InvitationCodeController@edit');
            });
    });

    Hook::addMenuItem('admin', 4, [
        'title' => 'InvitationCodes::general.code',
        'link' => 'admin/invitation-codes',
        'icon' => 'fa-inbox',
    ]);

    $filter->add('can_register', InvitationCodes\CheckInvitationCode::class, 30);
    $filter->add('auth_page_rows:register', function ($rows) {
        $index = array_search('auth.rows.register.form', $rows, true);
        array_splice($rows, $index === false ? count($rows) : $index, 0, ['InvitationCodes::register']);
        return $rows;
    });

    $events->listen('auth.registration.completed', function ($user) {
        $code = session()->pull('using_invitation_code');
        if (!is_string($code) || $code === '') {
            return;
        }
        DB::table('invitation_codes')
            ->where('code', $code)
            ->where('used_by', 0)
            ->update([
                'used_by' => $user->uid,
                'used_at' => Carbon::now(),
            ]);
    });
};
