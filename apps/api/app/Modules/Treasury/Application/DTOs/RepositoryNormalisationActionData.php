<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\RepositoryNormalisationAction;

final readonly class RepositoryNormalisationActionData
{
    public function __construct(
        public RepositoryNormalisationAction $action,
        public string $repositoryId,
        public ?string $glAccountId = null,
    ) {}

    /**
     * @return array{action: string, repository_id: string, gl_account_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'repository_id' => $this->repositoryId,
            'gl_account_id' => $this->glAccountId,
        ];
    }
}
