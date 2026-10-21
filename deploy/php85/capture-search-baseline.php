<?php

// Run ONCE against the old locked vendor tree, before removing HOA. No database access.
namespace Lorisleiva\LaravelSearchString\Tests {
    class TestCase {}
    class VisitorTest extends TestCase {}
}

namespace {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $package = dirname(__DIR__, 2).'/packages/lorisleiva-laravel-search-string';
    require $package.'/tests/ParserTest.php';
    require $package.'/tests/LexerTest.php';
    $compiler = new \Lorisleiva\LaravelSearchString\Compiler\HoaCompiler(
        (new \ReflectionClass(\Lorisleiva\LaravelSearchString\SearchStringManager::class))->newInstanceWithoutConstructor()
    );
    $parserCases = new \Lorisleiva\LaravelSearchString\Tests\ParserTest();
    $lexerCases = new \Lorisleiva\LaravelSearchString\Tests\LexerTest();
    $inputs = array_merge(array_column($parserCases->success(), 0), array_column($parserCases->failure(), 0));
    $atoms = ['nickname:"燕山 大学"', 'score>=3.5', 'permission:-1', 'verified', 'not verified', 'ip:NULL', 'nickname:""', 'uid in (1,2,3)', 'players.name:Steve', 'players:(name:Alex) >= 2', 'sort:-uid limit:5', 'foo:1,2', "foo:'a\\b'", 'foo:001', 'foo:1.2', 'And', 'andora', 'foo:bar*', 'foo:NULL', 'foo in ("",bar)'];
    foreach ($atoms as $a) {
        foreach ($atoms as $b) {
            $inputs[] = '('.$a.') or not ('.$b.')';
            $inputs[] = $a.' and '.$b;
        }
    }
    $inputs = array_values(array_unique(array_merge($inputs, [
        'foo in ()', 'foo:', 'foo:NULL,bar', 'foo.bar in (1,2)', 'foo.bar:1,2',
        'foo<1,2', 'foo:1.2,3.4', '"unterminated', "'unterminated", '()', 'A or',
        'A and', 'foo: (bar) = -1', 'foo: (bar) = 1.5', 'foo..bar', "foo:\" a\nb \"",
    ])));
    $cases = [];
    foreach ($inputs as $input) {
        try {
            $ast = $compiler->parse($input);
            $cases[] = ['input' => $input, 'ast' => json_decode(json_encode($ast), true),
                'dump' => $ast->accept(new \Lorisleiva\LaravelSearchString\Visitors\InlineDumpVisitor())];
        } catch (\Throwable $e) {
            $cases[] = ['input' => $input, 'invalid' => true];
        }
    }
    $lex = [];
    foreach ($lexerCases->success() as [$input, $expected]) {
        $lex[] = ['input' => $input, 'tokens' => $expected];
    }
    $output = ['source_commit' => '3b580b0cc92337da4159739c271805752b756bd0',
        'source_parser' => 'lorisleiva/laravel-search-string 1.3.0 + hoa/compiler 3.17',
        'cases' => $cases, 'lexer' => $lex];
    file_put_contents($argv[1], json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    echo count($cases).' parser cases, '.count($lex)." lexer cases captured\n";
}
