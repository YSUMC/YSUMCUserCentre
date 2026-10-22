<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use App\Http\Controllers\SkinlibController;
use App\Http\Controllers\ClosetController;
use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Auth\TokenVerifier;

/**
 * The skin endpoints the launcher uses on behalf of a signed-in player.
 *
 * Blessing Skin's own JSON API is guarded by Laravel Passport, while the launcher
 * holds the OpenID token it signed in with; those are two different credentials,
 * so the launcher cannot call `/api/players/...` directly. Everything here
 * therefore authenticates the launcher token itself and then performs the same
 * work the web controllers do, through the same models and extension events, so
 * texture files, closet rows and equipped textures stay in one shape no matter
 * which surface changed them.
 *
 * The endpoint writes only to textures the caller uploaded, and only to a
 * character the caller owns.
 */
final class SkinController
{
    private const MAX_DIMENSION_PIXELS = 1024;

    public function __construct(private Filter $filter) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $playerName = trim((string) $request->query('player'));
        if ($playerName === '') {
            return $this->failure('missing_player', 'A character name is required.');
        }

        $player = Player::where('uid', $owner->uid)->where('name', $playerName)->first();
        if ($player === null) {
            return $this->failure('unknown_player', 'That character does not belong to this account.');
        }

        return response()->json([
            'player' => [
                'name' => $player->name,
                'skin' => $this->textureView($player->skin),
                'cape' => $this->textureView($player->cape),
            ],
            'textures' => $owner->closet()
                ->orderBy('user_closet.texture_tid')
                ->get()
                ->map(fn (Texture $texture): array => $this->textureView($texture))
                ->all(),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $playerName = trim((string) $request->input('player', ''));
        if ($playerName === '') {
            return $this->failure('missing_player', 'A character name is required.');
        }
        if (Player::where('uid', $owner->uid)->where('name', $playerName)->doesntExist()) {
            return $this->failure('unknown_player', 'That character does not belong to this account.');
        }

        $type = (string) $request->input('type', '');
        if (!in_array($type, ['steve', 'alex', 'cape'], true)) {
            return $this->failure('invalid_type', 'Type must be steve, alex or cape.');
        }

        $bytes = $request->getContent();
        if (!is_string($bytes) || $bytes === '') {
            return $this->failure('missing_file', 'The request body must be the PNG texture.');
        }
        if (strlen($bytes) > max(1, (int) option('max_upload_file_size')) * 1024) {
            return $this->failure('file_too_large', 'The texture exceeds the size this site accepts.');
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[2] !== IMAGETYPE_PNG) {
            return $this->failure('invalid_image', 'The texture must be a PNG image.');
        }

        [$width, $height] = $size;
        $problem = $this->dimensionProblem($type, $width, $height);
        if ($problem !== null) {
            return $this->failure('invalid_size', $problem);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->failure('missing_name', 'A texture name is required.');
        }

        $isPublic = filter_var(
            $request->input('public', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($isPublic === null) {
            return $this->failure('invalid_privacy', 'Public must be a boolean.');
        }

        $path = tempnam(sys_get_temp_dir(), 'launcher-texture-');
        if ($path === false) {
            throw new \RuntimeException('Unable to stage launcher texture.');
        }
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Unable to stage launcher texture.');
            }
            $upload = Request::create('/texture', 'POST', [
                'name' => $name, 'type' => $type, 'public' => $isPublic,
            ], [], ['file' => new UploadedFile($path, 'texture.png', 'image/png', null, true)]);

            return DB::transaction(function () use ($owner, $upload) {
                $lockedOwner = User::whereKey($owner->uid)->lockForUpdate()->firstOrFail();
                abort_if($lockedOwner->isBanned() || $lockedOwner->isAuthOnly(), 403);
                abort_if(option('require_verification') && !$lockedOwner->verified, 403);
                $upload->setUserResolver(fn () => $lockedOwner);
                $guard = Auth::guard();
                $previous = $guard->user();
                $guard->setUser($lockedOwner);
                try {
                    $dispatcher = app(Dispatcher::class);
                    // Reuse the website's validation, plugin policy, accounting
                    // and events. The launcher only adapts the wire format.
                    $result = app(SkinlibController::class)->handleUpload($upload, $this->filter, $dispatcher)->getData(true);
                    if (!in_array($result['code'], [0, 2], true)) {
                        return $this->failure('upload_rejected', $result['message']);
                    }
                    $texture = Texture::findOrFail($result['data']['tid']);
                    if ($result['code'] === 2 && !$lockedOwner->closet()->where('texture_tid', $texture->tid)->exists()) {
                        $add = Request::create('/user/closet', 'POST', ['tid' => $texture->tid, 'name' => $texture->name]);
                        $add->setUserResolver(fn () => $lockedOwner);
                        $added = app(ClosetController::class)->add($add, $dispatcher, $this->filter)->getData(true);
                        if ($added['code'] !== 0) {
                            return $this->failure('closet_rejected', $added['message']);
                        }
                    }
                    return response()->json(['texture' => $this->textureView($texture), 'created' => $result['code'] === 0]);
                } finally {
                    $previous ? $guard->setUser($previous) : $guard->forgetUser();
                }
            });
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function equip(Request $request, Dispatcher $dispatcher): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $playerName = trim((string) $request->input('player', ''));
        if ($playerName === '') {
            return $this->failure('missing_player', 'A character name is required.');
        }

        $player = Player::where('uid', $owner->uid)->where('name', $playerName)->first();
        if ($player === null) {
            return $this->failure('unknown_player', 'That character does not belong to this account.');
        }

        $changed = false;
        foreach (['skin', 'cape'] as $slot) {
            if (!$request->has($slot)) {
                continue;
            }

            $tid = (int) $request->input($slot);
            if ($tid === 0) {
                $player->{'tid_'.$slot} = 0;
                $player->save();
                $dispatcher->dispatch('player.texture.reset', [$player, $slot]);
                $changed = true;

                continue;
            }

            $texture = Texture::find($tid);
            if ($texture === null) {
                return $this->failure('unknown_texture', 'That texture does not exist.');
            }
            if ($texture->type !== $slot && !($slot === 'skin' && in_array($texture->type, ['steve', 'alex'], true))) {
                return $this->failure('wrong_texture_type', "Texture {$tid} cannot be used as a {$slot}.");
            }
            if ($owner->closet()->where('texture_tid', $tid)->doesntExist()) {
                return $this->failure('not_in_closet', 'That texture is not in this account\'s closet.');
            }

            $dispatcher->dispatch('player.texture.updating', [$player, $texture]);
            $player->{'tid_'.$slot} = $tid;
            $player->save();
            $dispatcher->dispatch('player.texture.updated', [$player, $texture]);
            $changed = true;
        }

        if (!$changed) {
            return $this->failure('nothing_to_change', 'Provide a skin or cape texture id.');
        }

        return response()->json([
            'player' => [
                'name' => $player->name,
                'skin' => $this->textureView($player->skin),
                'cape' => $this->textureView($player->cape),
            ],
        ]);
    }

    /**
     * Takes a texture out of the caller's closet, unequipping it first when the
     * character is wearing it.
     *
     * The texture row and its file stay on the site, exactly as the website's own
     * closet behaves: this only ends the account's use of it.
     */
    public function destroy(Request $request, Dispatcher $dispatcher): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $playerName = trim((string) $request->input('player', ''));
        if ($playerName === '') {
            return $this->failure('missing_player', 'A character name is required.');
        }

        $player = Player::where('uid', $owner->uid)->where('name', $playerName)->first();
        if ($player === null) {
            return $this->failure('unknown_player', 'That character does not belong to this account.');
        }

        $tid = (int) $request->input('tid');
        if ($tid <= 0) {
            return $this->failure('missing_texture', 'A texture id is required.');
        }
        if ($owner->closet()->where('texture_tid', $tid)->doesntExist()) {
            return $this->failure('not_in_closet', 'That texture is not in this account\'s closet.');
        }

        foreach (['skin', 'cape'] as $slot) {
            if ((int) $player->{'tid_'.$slot} !== $tid) {
                continue;
            }

            $player->{'tid_'.$slot} = 0;
            $player->save();
            $dispatcher->dispatch('player.texture.reset', [$player, $slot]);
        }

        $owner->closet()->detach($tid);

        return response()->json([
            'player' => [
                'name' => $player->name,
                'skin' => $this->textureView($player->skin),
                'cape' => $this->textureView($player->cape),
            ],
        ]);
    }

    /**
     * Resolves the account that owns the presented token, or the failure response
     * to send back.
     */
    private function resolveOwner(Request $request): User|JsonResponse
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->failure('missing_token', 'A Bearer token is required.', 401);
        }

        $verifier = app(TokenVerifier::class);
        $playerName = $verifier->verifiedPlayer($token);
        if ($playerName === null) {
            return $this->failure('invalid_token', 'The launcher session is no longer valid.', 401);
        }

        $player = Player::where('name', $playerName)->first();
        if ($player === null || $player->user === null) {
            return $this->failure('unknown_player', 'No character on this site matches the token.');
        }

        return $player->user;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    /**
     * The reason a texture of this type cannot have these dimensions, mirroring the
     * size rules the upload page enforces.
     */
    private function dimensionProblem(string $type, int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0
            || $width > self::MAX_DIMENSION_PIXELS || $height > self::MAX_DIMENSION_PIXELS) {
            return "A texture cannot be {$width}x{$height}.";
        }
        if ($width % 64 !== 0 || $height % 32 !== 0) {
            return "A texture must be a multiple of 64x32; got {$width}x{$height}.";
        }

        $ratio = $width / $height;
        if ($type === 'cape') {
            return $ratio == 2.0 ? null : "A cape must be twice as wide as it is tall; got {$width}x{$height}.";
        }
        if ($ratio == 1.0) {
            return null; // Modern classic and slim skins both support square textures.
        }
        if ($ratio == 2.0) {
            return $type === 'alex' ? "The slim model needs a square texture; got {$width}x{$height}." : null;
        }

        return "A skin must be square or twice as wide as it is tall; got {$width}x{$height}.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function textureView(?Texture $texture): ?array
    {
        if ($texture === null || $texture->tid === 0) {
            return null;
        }

        return [
            'tid' => $texture->tid,
            'name' => $texture->name,
            'type' => $texture->type,
            'model' => $texture->model,
            'public' => (bool) $texture->public,
            // Served by the site's own static texture route, so a private
            // texture stays as unreachable as the site already makes it.
            'url' => url("/textures/{$texture->hash}"),
        ];
    }

    private function failure(string $code, string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}
