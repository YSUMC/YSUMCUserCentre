<?php

namespace SITMinecraft\Launcher\Interop;

use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;
use SITMinecraft\Launcher\Manifest\InstanceTags;

/** Local presentation preferences never modify a peer's cached source document. */
final class PeerPresentation
{
    public function decorate(array $sites): array
    {
        $peers = DB::table(LauncherSchema::INTEROP_PEERS)->pluck('id', 'site_id');
        $preferences = DB::table(LauncherSchema::PEER_PRESENTATION)->get()->keyBy(
            static fn ($row) => $row->peer_id.':'.$row->entry_id
        );
        foreach ($sites as &$site) {
            foreach ($site['instances'] as &$instance) {
                $entryId = self::entryId($site['site_id'], $instance['id']);
                $stored = $preferences->get(($peers[$site['site_id']] ?? '').':'.$entryId);
                $instance['pinned'] = (bool) ($stored->pinned ?? false);
                $instance['tags'] = InstanceTags::normalize($stored->tags ?? null);
            }
            unset($instance);
        }
        unset($site);
        return $sites;
    }

    public function updatedAt(): ?int
    {
        $value = DB::table(LauncherSchema::PEER_PRESENTATION)->max('updated_at');
        return $value ? strtotime($value) : null;
    }

    public function save(string $siteId, string $instanceId, bool $pinned, array $tags): void
    {
        DB::transaction(function () use ($siteId, $instanceId, $pinned, $tags): void {
            $peer = DB::table(LauncherSchema::INTEROP_PEERS)->where('site_id', $siteId)->lockForUpdate()->first();
            abort_unless($peer && $peer->enabled, 404);
            $key = ['peer_id' => $peer->id, 'entry_id' => self::entryId($siteId, $instanceId)];
            DB::table(LauncherSchema::PEER_PRESENTATION)->updateOrInsert($key, [
                'pinned' => $pinned,
                'tags' => json_encode(InstanceTags::normalize($tags), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    private static function entryId(string $siteId, string $instanceId): string
    {
        abort_unless(str_starts_with($instanceId, $siteId.':'), 422);
        return substr($instanceId, strlen($siteId) + 1);
    }
}
