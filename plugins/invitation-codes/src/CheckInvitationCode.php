<?php

namespace InvitationCodes;

use Blessing\Rejection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckInvitationCode
{
    /** @var Request */
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function filter($can)
    {
        session()->forget(['using_invitation_code', 'invitation_codes_original_can']);
        if ($can instanceof Rejection) {
            return $can;
        }
        $code = $this->request->input('invitationCode');
        if (!is_string($code) || trim($code) === '') {
            return new Rejection(trans('InvitationCodes::messages.empty'));
        }
        $code = trim($code);
        if (strlen($code) > 255) {
            return new Rejection(trans('InvitationCodes::messages.invalid'));
        }

        $result = DB::table('invitation_codes')->where('code', $code)->first();

        if ($result && $result->used_by == 0) {
            session(['using_invitation_code' => $code]);

            return $can;
        }

        return new Rejection(trans('InvitationCodes::messages.invalid'));
    }
}
