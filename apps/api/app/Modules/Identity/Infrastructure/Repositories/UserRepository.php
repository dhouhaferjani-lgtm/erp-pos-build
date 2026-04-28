<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Str;

final class UserRepository
{
    public function findById(string $id): ?User
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return User::where('id', $id)->first();
    }
}
