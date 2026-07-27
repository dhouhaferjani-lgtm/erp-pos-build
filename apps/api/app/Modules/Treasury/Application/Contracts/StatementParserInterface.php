<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Contracts;

use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Domain\StatementImportProfile;

interface StatementParserInterface
{
    public function parse(string $storedFilePath, StatementImportProfile $profile): ParsedStatement;
}
