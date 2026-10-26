<?php

namespace MUTMC\Launcher\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ConfigController
{
    public function render(Request $request): RedirectResponse
    {
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 405);

        return redirect()->route('mutmc-launcher.admin.index');
    }
}
