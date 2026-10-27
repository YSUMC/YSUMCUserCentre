<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use SITMinecraft\Launcher\Access\Membership;
use SITMinecraft\Launcher\Update\DownloadCatalog;
use SITMinecraft\Launcher\Update\ReleaseRepository;

final class DownloadController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(private ReleaseRepository $releases, private Membership $memberships) {}

    public function index(Request $request): Response
    {
        return response()->view('sitmc-launcher::download', ['release' => $this->latest($request)])
            ->withHeaders(self::HEADERS);
    }

    public function download(Request $request): RedirectResponse
    {
        $release = $this->latest($request);
        if (!($release['downloadable'] ?? false)) {
            return redirect()->route('sitmc-launcher.download.index')
                ->with('launcher_download_unavailable', true)->withHeaders(self::HEADERS);
        }

        // Resolve the admin's current artifact on each click; never accept a URL from the request.
        return redirect()->away($release['url'])->withHeaders(self::HEADERS);
    }

    private function latest(Request $request): ?array
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        abort_if($user->permission < User::NORMAL, 403);

        return (new DownloadCatalog)->latest($this->releases->rows('ysumc'), $this->memberships->describe($user));
    }
}
