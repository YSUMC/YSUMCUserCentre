<?php

namespace SITMinecraft\Launcher\Manifest;

use Illuminate\Http\Request;

final class InstanceTags
{
    public const KEYS = ['permanent', 'event', 'minigame', 'modded'];

    public static function normalize(mixed $tags): array
    {
        if (is_string($tags)) $tags = json_decode($tags, true);
        if (!is_array($tags) || !array_is_list($tags)) return [];
        return array_values(array_filter(self::KEYS, static fn ($key) => in_array($key, $tags, true)));
    }

    /** Omitted fields from older admin forms must not erase existing tags. */
    public static function input(Request $request): array
    {
        if (!$request->has('tags_present') && !$request->has('tags')) return [];
        $values = $request->validate([
            'tags' => ['nullable', 'array', 'max:4'],
            'tags.*' => ['string', 'distinct', 'in:'.implode(',', self::KEYS)],
        ]);
        return ['tags' => json_encode(self::normalize($values['tags'] ?? []), JSON_THROW_ON_ERROR)];
    }
}
