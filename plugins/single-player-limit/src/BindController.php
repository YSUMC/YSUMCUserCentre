<?php

namespace SinglePlayerLimit;

use App\Events\PlayerWasAdded;
use App\Events\PlayerWillBeAdded;
use App\Events\PlayerWillBeDeleted;
use App\Events\PlayerWasDeleted;
use App\Models\Player;
use App\Models\User;
use App\Rules\PlayerName;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BindController extends Controller
{
    public function show(Request $request)
    {
        return view('SinglePlayerLimit::bind', [
            'players' => $request->user()->players()->orderBy('pid')->get(),
        ]);
    }

    public function bind(Request $request, Dispatcher $dispatcher)
    {
        $name = $request->validate([
            'player' => [
                'required',
                'string',
                new PlayerName(),
                'min:'.option('player_name_length_min'),
                'max:'.option('player_name_length_max'),
            ],
        ])['player'];
        /** @var User */
        $user = Auth::user();

        return DB::transaction(function () use ($name, $dispatcher, $user) {
            $user = User::where('uid', $user->uid)->lockForUpdate()->firstOrFail();
            $player = Player::where('name', $name)->first();
            if (empty($player)) {
                $dispatcher->dispatch('player.adding', [$name, $user]);
                event(new PlayerWillBeAdded($name));

                $player = new Player();
                $player->uid = $user->uid;
                $player->name = $name;
                $player->tid_skin = 0;
                $player->tid_cape = 0;
                $player->save();

                $dispatcher->dispatch('player.added', [$player, $user]);
                event(new PlayerWasAdded($player));
            } elseif ($player->uid != $user->uid) {
                return json(trans('user.player.rename.repeated'), 1);
            }

            foreach ($user->players()->where('pid', '<>', $player->pid)->get() as $other) {
                $dispatcher->dispatch('player.deleting', [$other, $user]);
                event(new PlayerWillBeDeleted($other));
                $playerName = $other->name;
                $other->delete();
                $dispatcher->dispatch('player.deleted', [$other, $user]);
                event(new PlayerWasDeleted($playerName));
            }
            $user->nickname = $name;
            $user->save();

            return json(trans('SinglePlayerLimit::bind.success'), 0);
        });
    }
}
