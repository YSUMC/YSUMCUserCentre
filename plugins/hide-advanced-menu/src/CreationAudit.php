<?php

namespace Blessing\HideAdvancedMenu;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

final class CreationAudit
{
    public const TABLE = 'oauth_client_creations';

    public static function install(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->primary();
            $table->unsignedInteger('actor_uid')->nullable()->index();
            $table->string('actor_nickname')->nullable();
            $table->string('source', 16);
            $table->timestamp('recorded_at');
        });
    }

    public static function boot(): void
    {
        self::install();
        $model = Passport::clientModel();
        $model::created(function (Client $client) {
            $actor = request()->user();
            // user_id is the application owner, not necessarily its creator.
            // Request input cannot override the authenticated actor.
            $source = PHP_SAPI === 'cli' ? 'cli' : ($actor instanceof User ? 'web' : 'system');
            DB::connection($client->getConnectionName())->table(self::TABLE)->insert([
                'client_id' => $client->getKey(),
                'actor_uid' => $actor instanceof User ? $actor->uid : null,
                'actor_nickname' => $actor instanceof User ? $actor->nickname : null,
                'source' => $source,
                'recorded_at' => now(),
            ]);
        });
    }
}
