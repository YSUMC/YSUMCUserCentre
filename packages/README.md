# Laravel 13 compatibility packages

These are vendored source forks, installed by Composer path repositories with `symlink: false`:

| Package | Original version | Change |
| --- | --- | --- |
| blessing/filter | 1.4.0 | Illuminate constraint changed to Laravel 13 |
| blessing/rejection | 1.4.0 | Illuminate constraint changed to Laravel 13 |
| lorisleiva/laravel-search-string | 1.3.0 | Illuminate constraint changed to Laravel 13 |

Each package retains its upstream source, license and `YSUMC-UPSTREAM.json` with the original commit and downloaded archive SHA-256. Runtime PHP logic is unchanged; one upstream trailing-whitespace line is normalized. The application regression suite exercises filter ordering, rejection payloads, translated pages, authenticated query filtering, search parsing and the controllers that use them under actual Laravel 13 / PHP 8.5.12.

This is local compatibility validation, not an upstream support claim. The search package still depends on 13 abandoned HOA/sanmai packages reported by Composer audit. Keep that finding visible; replacing the parser is separate work. Do not remove these directories or resolve against upstream packages with the old constraints while deploying this lock file.
