<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Identity\Infrastructure\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;

final class PinVerifier
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function verify(string $userId, string $pin): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return false;
        }

        if ($user->pos_pin === null) {
            return false;
        }

        return Hash::check($pin, $user->pos_pin);
    }
}
