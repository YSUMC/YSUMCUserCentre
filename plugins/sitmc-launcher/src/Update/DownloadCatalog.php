<?php

namespace SITMinecraft\Launcher\Update;

use SITMinecraft\Launcher\Access\Audience;
use SITMinecraft\Launcher\Support\Timestamps;

final class DownloadCatalog
{
    /** The website currently distributes the Windows x64 installer. */
    public function latest(iterable $rows, array $identity): ?array
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (($row['distribution_brand'] ?? 'ysumc') !== 'ysumc'
                || !($row['enabled'] ?? false) || ($row['channel'] ?? '') !== 'release'
                || ReleaseRules::metadataProblem($row) !== null
                || str_contains($row['version'], '-')
                || !in_array($row['target'] ?? '', ['', 'windows'], true)
                || !in_array($row['arch'] ?? '', ['', 'x86_64'], true)
                || ($row['artifact_kind'] ?? 'nsis') !== 'nsis'
                || !Audience::permits($row, $identity)) {
                continue;
            }
            $candidates[] = $row;
        }
        usort($candidates, static fn (array $a, array $b): int =>
            SemanticVersion::compare($b['version'], $a['version'])
                ?: (Timestamps::value($b['pub_date']) <=> Timestamps::value($a['pub_date']))
                ?: (($b['id'] ?? 0) <=> ($a['id'] ?? 0)));
        if (!$candidates) return null;

        $row = $candidates[0];
        $url = trim((string) ($row['url'] ?? ''));
        // Never replace an unavailable latest release with an older installer.
        $downloadable = ReleaseRules::artifactProblem($row) === null
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && !preg_match('/[\x00-\x20\x7f]/', $url)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;

        return [
            'version' => $row['version'],
            'date' => date('Y-m-d', Timestamps::value($row['pub_date'])),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'downloadable' => $downloadable,
            'url' => $downloadable ? $url : null,
            'host' => $downloadable ? parse_url($url, PHP_URL_HOST) : null,
            'size' => ($row['size'] ?? 0) > 0 ? number_format($row['size'] / 1000000, 1).' MB' : null,
            'sha256' => preg_match('/^[a-f0-9]{64}$/iD', $row['sha256'] ?? '') ? strtolower($row['sha256']) : null,
        ];
    }
}
