<?php

namespace MUTMC\Launcher\Http;

final class CurseForgeRequest
{
    public static function allowed(string $method, string $path, array $query, ?array $body): bool
    {
        if ($method === 'GET') {
            if (!preg_match('~\Av1/(?:categories|mods/search|mods/[1-9][0-9]*(?:/description|/files(?:/[1-9][0-9]*(?:/changelog|/download-url)?)?)?)\z~D', $path)) {
                return false;
            }
            $allowed = ['gameId', 'classId', 'categoryId', 'categoryIds', 'searchFilter', 'gameVersion',
                'modLoaderType', 'modLoaderTypes', 'gameVersionTypeId', 'index', 'pageSize', 'sortField', 'sortOrder', 'slug'];
            foreach ($query as $name => $value) {
                // Laravel converts an empty searchFilter to null before this controller runs.
                if (!in_array($name, $allowed, true) || ($value !== null && !is_scalar($value)) || strlen((string) $value) > 2048) {
                    return false;
                }
            }
            if (isset($query['gameId']) && (string) $query['gameId'] !== '432') {
                return false;
            }
            return true;
        }
        $fields = ['v1/mods' => 'modIds', 'v1/mods/files' => 'fileIds',
            'v1/fingerprints' => 'fingerprints', 'v1/fingerprints/432' => 'fingerprints'];
        $field = $fields[$path] ?? null;
        if ($method !== 'POST' || $field === null || $query !== [] || $body === null
            || !isset($body[$field]) || !is_array($body[$field]) || !array_is_list($body[$field])
            || count($body[$field]) > ($field === 'fingerprints' ? 1000 : 50)) {
            return false;
        }
        $allowedFields = $path === 'v1/mods' ? [$field, 'filterPcOnly'] : [$field];
        if (array_diff(array_keys($body), $allowedFields) !== []
            || (array_key_exists('filterPcOnly', $body) && !is_bool($body['filterPcOnly']))) {
            return false;
        }
        foreach ($body[$field] as $id) {
            if (!is_int($id) || $id < 0 || $id > 4294967295) {
                return false;
            }
        }
        return true;
    }

    public static function query(array $query): array
    {
        return array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    }
}
