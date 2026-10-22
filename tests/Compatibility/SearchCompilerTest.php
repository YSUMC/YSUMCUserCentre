<?php

namespace Tests\Compatibility;

use Lorisleiva\LaravelSearchString\Compiler\NativeCompiler;
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;
use Lorisleiva\LaravelSearchString\Visitors\InlineDumpVisitor;
use PHPUnit\Framework\Attributes\DataProvider;

class SearchCompilerTest extends \PHPUnit\Framework\TestCase
{
    public static function baselineCases(): iterable
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/search-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $index => $case) {
            yield 'legacy-'.$index => [$case];
        }
    }

    #[DataProvider('baselineCases')]
    public function testMatchesCapturedLegacyParser(array $case): void
    {
        if (isset($case['invalid'])) {
            $this->expectException(InvalidSearchStringException::class);
        }
        $ast = (new NativeCompiler())->parse($case['input']);
        $this->assertSame($case['ast'], json_decode(json_encode($ast), true), $case['input']);
        $this->assertSame($case['dump'], $ast->accept(new InlineDumpVisitor()), $case['input']);
    }

    public function testOriginalLexerContract(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/search-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['lexer'] as $case) {
            $tokens = (new NativeCompiler())->lex($case['input'])->pluck('token')->all();
            $this->assertSame('EOF', array_pop($tokens));
            $this->assertSame($case['tokens'], implode(' ', $tokens), $case['input']);
        }
    }

    public function testZeroAndWhitespaceAreHandledExplicitly(): void
    {
        $compiler = new NativeCompiler();
        $dump = new InlineDumpVisitor();
        $this->assertSame('SOLO(0)', $compiler->parse('0')->accept($dump));
        $this->assertSame('EMPTY', $compiler->parse(" \t\n")->accept($dump));
        $this->assertSame('EMPTY', $compiler->parse(null)->accept($dump));
        $this->assertSame('QUERY(foo = )', $compiler->parse('foo:""')->accept($dump));
    }

    public static function excessiveInputs(): iterable
    {
        yield 'bytes' => [str_repeat('a', NativeCompiler::MAX_BYTES + 1)];
        yield 'tokens' => [str_repeat('a ', NativeCompiler::MAX_TOKENS + 1)];
        yield 'parentheses' => [str_repeat('(', 65).'a'.str_repeat(')', 65)];
        yield 'negations' => [str_repeat('not ', 65).'a'];
        yield 'relationships' => [str_repeat('a.', 65).'b'];
        yield 'combined relationships' => [str_repeat('a.', 40).'a:('.str_repeat('b.', 40).'b)'];
        yield 'count overflow' => ['foo:(bar) > 9999999999999999999999999'];
    }

    #[DataProvider('excessiveInputs')]
    public function testRejectsExcessiveWork(string $input): void
    {
        $this->expectException(InvalidSearchStringException::class);
        (new NativeCompiler())->parse($input);
    }
}
