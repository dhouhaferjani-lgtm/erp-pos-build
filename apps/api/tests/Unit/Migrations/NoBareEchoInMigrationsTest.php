<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoBareEchoInMigrationsTest extends TestCase
{
    public function test_token_guard_classifies_direct_output_without_matching_comments_or_strings(): void
    {
        $fixtures = [
            'inline echo in an if' => [
                'source' => '<?php if ($enabled) echo "leak";',
                'writers' => ['echo'],
            ],
            'print expression' => [
                'source' => '<?php print "leak";',
                'writers' => ['print'],
            ],
            'inline html after PHP' => [
                'source' => '<?php $value = 1; ?>leak',
                'writers' => ['inline HTML'],
            ],
            'this output accessor' => [
                'source' => '<?php $this->getOutput()->writeln("leak");',
                'writers' => ['$this->getOutput()'],
            ],
            'direct output functions' => [
                'source' => '<?php printf("%s", $value); vprintf("%s", [$value]); dump($value); dd($value); var_dump($value);',
                'writers' => ['printf', 'vprintf', 'dump', 'dd', 'var_dump'],
            ],
            'printing print_r' => [
                'source' => '<?php print_r($value);',
                'writers' => ['print_r'],
            ],
            'returning print_r' => [
                'source' => '<?php $rendered = print_r($value, true);',
                'writers' => [],
            ],
            'standard streams' => [
                'source' => '<?php fwrite(STDOUT, "out"); fputs(STDERR, "err");',
                'writers' => ['fwrite(STDOUT)', 'fputs(STDERR)'],
            ],
            'non-standard stream' => [
                'source' => '<?php fwrite($stream, "not direct stdout");',
                'writers' => [],
            ],
            'comment and string literals' => [
                'source' => '<?php // echo "not executable";'."\n".'$message = "dump($value)";',
                'writers' => [],
            ],
        ];

        foreach ($fixtures as $name => $fixture) {
            self::assertSame(
                $fixture['writers'],
                array_column($this->prohibitedWrites($fixture['source']), 'writer'),
                $name,
            );
        }
    }

    public function test_migrations_do_not_write_directly_to_stdout(): void
    {
        $root = database_path('migrations');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, 'Unable to read migration '.$file->getPathname());

            foreach ($this->prohibitedWrites($contents) as $offender) {
                $offenders[] = str_replace($root.'/', '', $file->getPathname())
                    .':'.$offender['line'].': '.$offender['writer'];
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Migrations must route output through MigrationOutput; direct stdout corrupts synchronous HTTP responses:\n"
                .implode("\n", $offenders),
        );
    }

    /** @return list<array{line: int, writer: string}> */
    private function prohibitedWrites(string $contents): array
    {
        $tokens = token_get_all($contents);
        $offenders = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            $writer = null;

            if ($id === T_ECHO) {
                $writer = 'echo';
            } elseif ($id === T_PRINT) {
                $writer = 'print';
            } elseif ($id === T_INLINE_HTML) {
                $writer = 'inline HTML';
            } elseif ($id === T_STRING) {
                $writer = $this->prohibitedStringCall($tokens, $index, $text);
            }

            if ($writer === null) {
                continue;
            }

            $offenders[] = [
                'line' => $line,
                'writer' => $writer,
            ];
        }

        return $offenders;
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function prohibitedStringCall(array $tokens, int $index, string $text): ?string
    {
        $name = strtolower($text);

        if ($name === 'getoutput' && $this->isThisMethodCall($tokens, $index)) {
            return '$this->getOutput()';
        }

        if (! $this->isFunctionCall($tokens, $index)) {
            return null;
        }

        if (in_array($name, ['printf', 'vprintf', 'dump', 'dd', 'var_dump'], true)) {
            return $name;
        }

        $arguments = $this->functionArguments($tokens, $index);

        if ($name === 'print_r') {
            return isset($arguments[1]) && $this->argumentIsDefinitelyTrue($arguments[1])
                ? null
                : 'print_r';
        }

        if (in_array($name, ['fwrite', 'fputs'], true)
            && isset($arguments[0])
            && ($stream = $this->standardOutputStream($arguments[0])) !== null) {
            return $name.'('.$stream.')';
        }

        return null;
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function isFunctionCall(array $tokens, int $index): bool
    {
        $next = $this->significantTokenIndex($tokens, $index + 1, 1);
        if ($next === null || $tokens[$next] !== '(') {
            return false;
        }

        $previous = $this->significantTokenIndex($tokens, $index - 1, -1);
        if ($previous === null || ! is_array($tokens[$previous])) {
            return true;
        }

        return ! in_array(
            $tokens[$previous][0],
            [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_FN, T_NEW],
            true,
        );
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function isThisMethodCall(array $tokens, int $index): bool
    {
        $next = $this->significantTokenIndex($tokens, $index + 1, 1);
        if ($next === null || $tokens[$next] !== '(') {
            return false;
        }

        $operator = $this->significantTokenIndex($tokens, $index - 1, -1);
        if ($operator === null
            || ! is_array($tokens[$operator])
            || $tokens[$operator][0] !== T_OBJECT_OPERATOR) {
            return false;
        }

        $receiver = $this->significantTokenIndex($tokens, $operator - 1, -1);

        return $receiver !== null
            && is_array($tokens[$receiver])
            && $tokens[$receiver][0] === T_VARIABLE
            && strtolower($tokens[$receiver][1]) === '$this';
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     * @return list<list<array{int, string, int}|string>>
     */
    private function functionArguments(array $tokens, int $functionIndex): array
    {
        $opening = $this->significantTokenIndex($tokens, $functionIndex + 1, 1);
        if ($opening === null || $tokens[$opening] !== '(') {
            return [];
        }

        $arguments = [];
        $argument = [];
        $depth = 0;

        for ($index = $opening + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    if ($token === ')' && $depth === 0) {
                        if ($argument !== [] || $arguments !== []) {
                            $arguments[] = $argument;
                        }

                        return $arguments;
                    }

                    $depth--;
                } elseif ($token === ',' && $depth === 0) {
                    $arguments[] = $argument;
                    $argument = [];

                    continue;
                }
            }

            $argument[] = $token;
        }

        return $arguments;
    }

    /**
     * @param  list<array{int, string, int}|string>  $argument
     */
    private function argumentIsDefinitelyTrue(array $argument): bool
    {
        $text = strtolower(trim($this->significantTokenText($argument)));

        return $text === 'true' || $text === '1';
    }

    /**
     * @param  list<array{int, string, int}|string>  $argument
     */
    private function standardOutputStream(array $argument): ?string
    {
        $stream = strtoupper(ltrim(trim($this->significantTokenText($argument)), '\\'));

        return in_array($stream, ['STDOUT', 'STDERR'], true) ? $stream : null;
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function significantTokenIndex(array $tokens, int $index, int $step): ?int
    {
        for ($count = count($tokens); $index >= 0 && $index < $count; $index += $step) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private function significantTokenText(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $text .= $token[1];
            } else {
                $text .= $token;
            }
        }

        return $text;
    }
}
