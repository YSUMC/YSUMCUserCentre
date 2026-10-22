<?php

namespace InvitationCodes;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class InvitationCodeController extends Controller
{
    public function list()
    {
        $free = DB::table('invitation_codes')->where('used_by', 0)->get();
        $used = DB::table('invitation_codes')->where('used_by', '<>', 0)->get();

        return view('InvitationCodes::codes', compact('free', 'used'));
    }

    public function generate(Request $request)
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:255']]);
        DB::table('invitation_codes')->insert([
            'code' => bin2hex(random_bytes(16)),
            'generated_at' => Carbon::now(),
            'used_by' => 0,
            'description' => $data['description'],
        ]);

        return back();
    }

    public function edit(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:invitation_codes,id'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        DB::table('invitation_codes')->where('id', $data['id'])->update([
            'description' => $data['description'],
        ]);

        return ['success' => true];
    }
}
