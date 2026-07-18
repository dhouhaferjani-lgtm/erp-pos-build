<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Console;

use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

final class BackfillMembershipsCommand extends Command
{
    protected $signature = 'users:backfill-memberships
                            {--company= : Company UUID to map memberless active users into}
                            {--user=* : User UUID(s) to map (auditable per-user path)}
                            {--all-memberless : Bulk-map every memberless active user}
                            {--force-multi : Required with --all-memberless in a multi-company tenant}';

    protected $description = 'Map memberless active users into a company on the current tenant connection.';

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyOption = $this->option('company');
        if (! is_string($companyOption) || $companyOption === '') {
            $this->error('The --company option is required.');

            return self::FAILURE;
        }

        $companyId = $companyOption;
        $db = $this->connections->connection();
        if (! $db->table('companies')->where('id', $companyId)->exists()) {
            $this->error("Company {$companyId} does not exist on the current tenant connection.");

            return self::FAILURE;
        }

        $userIds = $this->userIds();
        $allMemberless = (bool) $this->option('all-memberless');

        if ($userIds !== [] && $allMemberless) {
            $this->error('Use either --user or --all-memberless, not both.');

            return self::FAILURE;
        }

        if ($userIds === [] && ! $allMemberless) {
            $this->error('Provide at least one --user or explicitly use --all-memberless.');

            return self::FAILURE;
        }

        if ($userIds !== []) {
            return $this->mapNamedUsers($db, $userIds, $companyId);
        }

        return $this->mapAllMemberlessUsers($db, $companyId);
    }

    /**
     * @return list<string>
     */
    private function userIds(): array
    {
        $userOption = $this->input->getOption('user');
        $rawUserOptions = is_array($userOption) ? $userOption : [$userOption];
        $userIds = [];
        foreach ($rawUserOptions as $rawUserOption) {
            if (! is_string($rawUserOption)) {
                continue;
            }

            foreach (explode(',', $rawUserOption) as $userId) {
                $userId = trim($userId);
                if ($userId !== '') {
                    $userIds[$userId] = $userId;
                }
            }
        }

        return array_values($userIds);
    }

    /**
     * @param  list<string>  $userIds
     */
    private function mapNamedUsers(ConnectionInterface $db, array $userIds, string $companyId): int
    {
        foreach ($userIds as $userId) {
            $status = $db->table('users')->where('id', $userId)->value('status');
            if ($status === null) {
                $this->error("User {$userId} does not exist on the current tenant connection.");

                return self::FAILURE;
            }

            if ($status !== UserStatus::Active->value) {
                $this->error("User {$userId} is not active.");

                return self::FAILURE;
            }
        }

        foreach ($userIds as $userId) {
            $inserted = $this->insertMembership($db, $userId, $companyId);
            $this->info("User {$userId}: inserted {$inserted} membership(s).");
        }

        return self::SUCCESS;
    }

    private function mapAllMemberlessUsers(ConnectionInterface $db, string $companyId): int
    {
        $companyCount = $db->table('companies')->count();
        if ($companyCount > 1 && ! (bool) $this->option('force-multi')) {
            $this->error('Multi-company bulk mapping requires --force-multi.');

            return self::FAILURE;
        }

        $memberlessUserIds = $db->table('users')
            ->where('status', UserStatus::Active->value)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('user_company_memberships')
                    ->whereColumn('user_company_memberships.user_id', 'users.id');
            })
            ->pluck('id')
            ->map(static fn ($userId): string => (string) $userId)
            ->all();

        foreach ($memberlessUserIds as $userId) {
            $inserted = $this->insertMembership($db, $userId, $companyId);
            $this->info("User {$userId}: inserted {$inserted} membership(s).");
        }

        return self::SUCCESS;
    }

    private function insertMembership(ConnectionInterface $db, string $userId, string $companyId): int
    {
        if ($db->table('user_company_memberships')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->exists()) {
            return 0;
        }

        $now = now();

        return $db->table('user_company_memberships')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'company_id' => $companyId,
            'role' => MembershipRole::Viewer->value,
            'allowed_location_ids' => null,
            'is_primary' => false,
            'status' => MembershipStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
