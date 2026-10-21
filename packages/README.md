# Laravel 13 compatibility packages

These are vendored source forks, installed by Composer path repositories with `symlink: false`:

| Package | Original version | Change |
| --- | --- | --- |
| blessing/filter | 1.4.0 | Illuminate constraint changed to Laravel 13 |
| blessing/rejection | 1.4.0 | Illuminate constraint changed to Laravel 13 |
| lorisleiva/laravel-search-string | 1.3.0 | Local version 1.3.1: native parser replaces HOA; Laravel 13 query grouping fixes |

Each package retains its license and `YSUMC-UPSTREAM.json` with the original commit and downloaded archive SHA-256. Filter/rejection runtime logic is unchanged; one upstream trailing-whitespace line is normalized. The search fork replaces the compiler and adapts multi-column search/date grouping to Laravel 13. The application regression suite exercises these under actual Laravel 13 / PHP 8.5.12.

This is local compatibility validation, not an upstream support claim. The 13 abandoned HOA/sanmai packages have been removed from both the manifest and lock file; Composer audit reports zero abandoned packages and zero advisories. Local version 1.3.1 is pinned to this path fork, not an upstream release claim. Do not remove these directories or resolve against upstream packages with the old constraints while deploying this lock file.

`NativeCompiler` implements the existing grammar with no parser dependency. The old generated parser, HOA adapter and converter are deleted. The AST classes and visitors remain, with explicit nested `where` calls for Laravel 13 and correct AND/OR date-range grouping. `Grammar.pp` and its accessor remain as syntax documentation; changing that file does not generate or change the native parser. Custom compiler consumers must implement `CompilerInterface`.

899 frozen old-parser cases (from the previous migration commit) compare AST structure and values, plus 31 lexer cases. Database tests cover existing user scope, bindings, Chinese text, booleans, null, aliases, sort/limit, dates, relationship counts, and failure strategies. The list parser retains the upstream behavior of omitting empty quoted list elements. Bare `0` now correctly parses as a search term instead of an empty query, and whitespace-only input is empty. Error diagnostics identify the current unexpected token rather than HOA's backtracking position. Limits are 16 KiB input, 2,048 tokens and 64 AST levels, with normal `InvalidSearchStringException` handling.

`tests/Compatibility/SearchCompilerTest.php` and `SearchQueryTest.php` are the active PHP 8.5/Laravel 13 regression suite. The package's historical upstream tests are retained as reference; its old PHPUnit/Testbench development manifest is not used by the application. `deploy/php85/capture-search-baseline.php` reproduces the fixture only when copied into the **old** 3b580b0 candidate with its locked HOA vendor tree; do not regenerate expected results from the new parser.
