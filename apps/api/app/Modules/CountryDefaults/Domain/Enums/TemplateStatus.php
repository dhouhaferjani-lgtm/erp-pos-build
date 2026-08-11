<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Enums;

enum TemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
