<?php

namespace Lorisleiva\LaravelSearchString\Compiler;

use Illuminate\Support\Enumerable;
use Lorisleiva\LaravelSearchString\AST\{AndSymbol, EmptySymbol, ListSymbol, NotSymbol, OrSymbol, QuerySymbol, RelationshipSymbol, SoloSymbol, Symbol};
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;

/** Recursive descent implementation of Grammar.pp, without the abandoned HOA runtime. */
class NativeCompiler implements CompilerInterface
{
    public const MAX_BYTES = 16384;
    public const MAX_TOKENS = 2048;
    public const MAX_DEPTH = 64;

    private array $tokens = [];
    private int $position = 0;
    private int $depth = 0;

    public function lex(?string $input): Enumerable
    {
        $input ??= '';
        if (strlen($input) > self::MAX_BYTES) {
            throw InvalidSearchStringException::fromParser('Search exceeds the 16384 byte limit.');
        }
        // Order and boundary rules match the original grammar (including case sensitivity).
        $patterns = [
            'T_ASSIGNMENT' => ':|=', 'T_COMPARATOR' => '>=?|<=?',
            'T_AND' => '(?:and|AND)(?=$|[()\s])', 'T_OR' => '(?:or|OR)(?=$|[()\s])',
            'T_NOT' => '(?:not|NOT)(?=$|[()\s])', 'T_IN' => '(?:in|IN)(?=$|[()\s])',
            'T_NULL' => 'NULL(?=$|[()\s])',
            'T_LPARENTHESIS' => '\(', 'T_RPARENTHESIS' => '\)',
            'T_DOT' => '\.', 'T_COMMA' => ',',
            'T_INTEGER' => '\d+(?=$|[()\s])', 'T_DECIMAL' => '\d+\.\d+(?=$|[()\s])',
            'T_TERM' => '[^\s:><="\x27()\.,]+',
        ];
        $tokens = [];
        $append = static function (string $type, string $value, int $offset) use (&$tokens): void {
            if (count($tokens) >= self::MAX_TOKENS) {
                throw InvalidSearchStringException::fromParser('Search exceeds the 2048 token limit.');
            }
            $tokens[] = ['token' => $type, 'value' => $value, 'offset' => $offset];
        };
        for ($offset = 0, $length = strlen($input); $offset < $length;) {
            if (preg_match('~\G\s+~u', $input, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                continue;
            }
            $char = $input[$offset];
            if ($char === '"' || $char === "'") {
                $prefix = $char === '"' ? 'T_DOUBLE_' : 'T_SINGLE_';
                $append($prefix.'LQUOTE', $char, $offset++);
                $end = strpos($input, $char, $offset);
                if ($end === false) {
                    throw InvalidSearchStringException::fromLexer('Unclosed search string.', 'EOF');
                }
                if ($end > $offset) {
                    $append('T_STRING', substr($input, $offset, $end - $offset), $offset);
                }
                $append($prefix.'RQUOTE', $char, $end);
                $offset = $end + 1;
                continue;
            }
            foreach ($patterns as $type => $pattern) {
                if (preg_match('~\G(?:'.$pattern.')~u', $input, $match, 0, $offset)) {
                    $append($type, $match[0], $offset);
                    $offset += strlen($match[0]);
                    continue 2;
                }
            }
            throw InvalidSearchStringException::fromLexer('Invalid search token at byte '.$offset.'.', 'UNKNOWN');
        }
        $tokens[] = ['token' => 'EOF', 'value' => '', 'offset' => strlen($input)];
        return collect($tokens);
    }

    public function parse(?string $input): Symbol
    {
        $this->tokens = $this->lex($input)->all();
        $this->position = $this->depth = 0;
        if ($this->is('EOF')) {
            return new EmptySymbol();
        }
        $result = $this->orExpression();
        $this->expect('EOF');
        // Dotted relationship chains also add AST depth, without parser recursion.
        $pending = [[$result, 1]];
        while ($pending) {
            [$node, $depth] = array_pop($pending);
            if ($depth > self::MAX_DEPTH) {
                throw InvalidSearchStringException::fromParser('Search AST exceeds the 64 level nesting limit.');
            }
            if ($node instanceof AndSymbol || $node instanceof OrSymbol) {
                foreach ($node->expressions as $child) {
                    $pending[] = [$child, $depth + 1];
                }
            } elseif ($node instanceof NotSymbol || $node instanceof RelationshipSymbol) {
                $pending[] = [$node->expression, $depth + 1];
            }
        }
        return $result;
    }

    private function orExpression(): Symbol
    {
        $children = [$this->andExpression()];
        while ($this->take('T_OR')) {
            $children[] = $this->andExpression();
        }
        return count($children) === 1 ? $children[0] : new OrSymbol($children);
    }

    private function andExpression(): Symbol
    {
        $children = [$this->terminal()];
        while (! $this->is('EOF', 'T_OR', 'T_RPARENTHESIS')) {
            $this->take('T_AND');
            $children[] = $this->terminal();
        }
        return count($children) === 1 ? $children[0] : new AndSymbol($children);
    }

    private function terminal(): Symbol
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw InvalidSearchStringException::fromParser('Search exceeds the 64 level nesting limit.');
        }
        try {
            if ($this->take('T_NOT')) {
                return new NotSymbol($this->terminal());
            }
            if ($this->take('T_LPARENTHESIS')) {
                $node = $this->orExpression();
                $this->expect('T_RPARENTHESIS');
                return $node;
            }
            if (! $this->is('T_TERM')) {
                return new SoloSymbol($this->scalar());
            }
            $terms = [$this->expect('T_TERM')['value']];
            while ($this->take('T_DOT')) {
                if (count($terms) >= self::MAX_DEPTH) {
                    throw InvalidSearchStringException::fromParser('Too many relationship levels.');
                }
                $terms[] = $this->expect('T_TERM')['value'];
            }
            if ($this->take('T_IN')) {
                if (count($terms) !== 1) {
                    $this->invalid();
                }
                $this->expect('T_LPARENTHESIS');
                $values = $this->scalarList();
                $this->expect('T_RPARENTHESIS');
                return new ListSymbol($terms[0], $values);
            }
            if ($this->is('T_ASSIGNMENT', 'T_COMPARATOR')) {
                $assignment = $this->is('T_ASSIGNMENT');
                $operator = $this->operator();
                if ($assignment && $this->take('T_LPARENTHESIS')) {
                    $node = $this->orExpression();
                    $this->expect('T_RPARENTHESIS');
                    $expectedOperator = '>';
                    $expectedCount = 0;
                    if ($this->is('T_ASSIGNMENT', 'T_COMPARATOR')) {
                        $expectedOperator = $this->operator();
                        $count = $this->expect('T_INTEGER')['value'];
                        if (strlen(ltrim($count, '0')) > strlen((string) PHP_INT_MAX)
                            || (strlen(ltrim($count, '0')) === strlen((string) PHP_INT_MAX)
                                && strcmp(ltrim($count, '0'), (string) PHP_INT_MAX) > 0)) {
                            throw InvalidSearchStringException::fromParser('Relationship count exceeds integer range.');
                        }
                        $expectedCount = (int) $count;
                    }
                    $head = array_shift($terms);
                    return new RelationshipSymbol($head, $this->wrapRelationships($terms, $node), $expectedOperator, $expectedCount);
                }
                $value = $this->scalar(true);
                if ($assignment && count($terms) === 1 && $this->take('T_COMMA')) {
                    if ($value === null) {
                        $this->invalid();
                    }
                    return new ListSymbol($terms[0], $this->legacyList(array_merge([$value], $this->scalarList())));
                }
                $key = array_pop($terms);
                return $this->wrapRelationships($terms, new QuerySymbol($key, $operator, $value));
            }
            $key = array_pop($terms);
            return $this->wrapRelationships($terms, new SoloSymbol($key));
        } finally {
            --$this->depth;
        }
    }

    private function wrapRelationships(array $terms, Symbol $node): Symbol
    {
        foreach (array_reverse($terms) as $term) {
            $node = new RelationshipSymbol($term, $node);
        }
        return $node;
    }

    private function scalarList(): array
    {
        $values = [$this->scalar()];
        while ($this->take('T_COMMA')) {
            $values[] = $this->scalar();
        }
        return $this->legacyList($values);
    }

    private function legacyList(array $values): array
    {
        // HOA's scalar-list AST omitted empty quoted values. Keep existing list semantics.
        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    private function scalar(bool $nullable = false): ?string
    {
        if ($nullable && $this->take('T_NULL')) {
            return null;
        }
        if ($this->is('T_SINGLE_LQUOTE', 'T_DOUBLE_LQUOTE')) {
            $closing = str_replace('LQUOTE', 'RQUOTE', $this->tokens[$this->position++]['token']);
            $value = $this->is('T_STRING') ? $this->tokens[$this->position++]['value'] : '';
            $this->expect($closing);
            return $value;
        }
        if ($this->is('T_TERM', 'T_INTEGER', 'T_DECIMAL')) {
            return $this->tokens[$this->position++]['value'];
        }
        $this->invalid();
    }

    private function operator(): string
    {
        $token = $this->tokens[$this->position++];
        return $token['token'] === 'T_ASSIGNMENT' ? '=' : $token['value'];
    }

    private function is(string ...$types): bool
    {
        return in_array($this->tokens[$this->position]['token'], $types, true);
    }

    private function take(string $type): bool
    {
        if (! $this->is($type)) {
            return false;
        }
        ++$this->position;
        return true;
    }

    private function expect(string $type): array
    {
        if (! $this->is($type)) {
            $this->invalid();
        }
        return $this->tokens[$this->position++];
    }

    private function invalid(): never
    {
        $token = $this->tokens[$this->position];
        throw InvalidSearchStringException::fromLexer('Unexpected '.$token['token'].' at byte '.$token['offset'].'.', $token['token']);
    }
}
