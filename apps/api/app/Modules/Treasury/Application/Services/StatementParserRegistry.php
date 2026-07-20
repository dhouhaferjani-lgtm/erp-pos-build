<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\Contracts\StatementParserInterface;
use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use App\Modules\Treasury\Domain\StatementImportProfile;
use InvalidArgumentException;

final readonly class StatementParserRegistry
{
    /** @param array<string, StatementParserInterface> $parsers */
    public function __construct(private array $parsers) {}

    public function parser(StatementParserKey $key): StatementParserInterface
    {
        return $this->parsers[$key->value]
            ?? throw new InvalidArgumentException("No statement parser is registered for {$key->value}.");
    }

    public function parse(string $storedFilePath, StatementImportProfile $profile): ParsedStatement
    {
        return $this->parser($profile->parser_key)->parse($storedFilePath, $profile);
    }
}
