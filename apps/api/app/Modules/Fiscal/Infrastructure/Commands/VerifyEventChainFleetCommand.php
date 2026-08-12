<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Throwable;

/**
 * Manifest-driven fleet wrapper for {@see VerifyEventChainCommand}.
 *
 * The manifest is a JSON object mapping each central-directory tenant UUID to
 * the UUID of an actor inside that tenant. No actor is inferred or shared
 * across tenants. The shared actor gate runs while that tenant is bound and
 * before any `fiscal_events` target query. Authorized targets are enumerated
 * exclusively from the distinct `(terminal_id, chain_context)` pairs present
 * in that tenant's `fiscal_events` table. Each target is then delegated to the
 * existing single-chain command, which re-applies the same shared gate.
 */
final class VerifyEventChainFleetCommand extends AuthorizedFiscalChainCommand
{
    /** @var string */
    protected $signature = 'fiscal:verify-event-chain-fleet
        {--manifest= : JSON file mapping tenant UUIDs to actor UUIDs (required)}';

    /** @var string */
    protected $description = 'Verify every fiscal event chain covered by an explicit tenant-to-actor manifest.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly DatabaseManager $databaseManager,
        private readonly Filesystem $filesystem,
        PermissionRegistrar $permissionRegistrar,
    ) {
        parent::__construct($companyContext, $permissionRegistrar);
    }

    protected function executeCommand(): int
    {
        $manifestPath = $this->option('manifest');
        if (! is_string($manifestPath) || $manifestPath === '') {
            $this->error('Missing --manifest option; supply a JSON tenant-to-actor manifest.');

            return self::FAILURE;
        }

        $manifest = $this->readManifest($manifestPath);
        if ($manifest === null) {
            return self::FAILURE;
        }

        $directoryTenantIds = Tenant::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $directorySet = array_fill_keys($directoryTenantIds, true);
        $manifestTenantIds = array_keys($manifest);

        $hasFailure = false;
        foreach (array_diff($directoryTenantIds, $manifestTenantIds) as $tenantId) {
            $this->error(sprintf('TENANT %s: MISSING FROM MANIFEST — no actor was supplied; nothing was verified.', $tenantId));
            $hasFailure = true;
        }
        foreach (array_diff($manifestTenantIds, $directoryTenantIds) as $tenantId) {
            $this->error(sprintf('TENANT %s: UNKNOWN TENANT — absent from the central tenant directory.', $tenantId));
            $hasFailure = true;
        }

        $tenantsProcessed = 0;
        $chainsProcessed = 0;

        foreach ($manifest as $tenantId => $actorId) {
            if (! isset($directorySet[$tenantId])) {
                continue;
            }

            $tenantsProcessed++;
            /** @var list<array{terminal_id: string, chain_context: string}> $targets */
            $targets = [];
            $authorizationExit = null;

            $enumerationExit = $this->forEachTenantNarrowed(
                $tenantId,
                function (Tenant $tenant) use ($actorId, &$authorizationExit, &$targets): int {
                    $authorizationExit = $this->authorizeBoundTenantActor($actorId, $tenant->id);
                    if ($authorizationExit !== self::SUCCESS) {
                        return $authorizationExit;
                    }

                    $rows = $this->databaseManager->connection()
                        ->table('fiscal_events')
                        ->where('tenant_id', $tenant->id)
                        ->select(['terminal_id', 'chain_context'])
                        ->distinct()
                        ->orderBy('terminal_id')
                        ->orderBy('chain_context')
                        ->get();

                    foreach ($rows as $row) {
                        if (! is_string($row->terminal_id) || ! is_string($row->chain_context)) {
                            $this->error(sprintf(
                                'TENANT %s: malformed fiscal_events chain coordinates; nothing was verified for that row.',
                                $tenant->id,
                            ));

                            return self::FAILURE;
                        }

                        $targets[] = [
                            'terminal_id' => $row->terminal_id,
                            'chain_context' => $row->chain_context,
                        ];
                    }

                    return self::SUCCESS;
                },
            );

            if ($this->failIfTenantFilterUnvisited($tenantId) !== null || $enumerationExit !== self::SUCCESS) {
                $failureReason = match ($authorizationExit) {
                    self::FAILURE => 'actor authorization refused verification before chain target enumeration',
                    2 => 'actor authorization could not be evaluated because of a transient failure',
                    self::SUCCESS => 'chain target enumeration did not complete',
                    default => 'tenant binding failed before actor authorization could run',
                };
                $this->error(sprintf('TENANT %s: FAILED — %s.', $tenantId, $failureReason));
                $hasFailure = true;

                continue;
            }

            $this->info(sprintf(
                'TENANT %s: enumerated %d distinct (terminal_id, chain_context) pair(s) from fiscal_events.',
                $tenantId,
                count($targets),
            ));

            if ($targets === []) {
                $this->error(sprintf(
                    'TENANT %s: NO-DATA — fiscal_events contains no distinct (terminal_id, chain_context) pairs; nothing was verified.',
                    $tenantId,
                ));
                $hasFailure = true;

                continue;
            }

            $tenantFailed = false;
            foreach ($targets as $target) {
                $chainsProcessed++;
                $this->line(sprintf(
                    'TENANT %s: verifying terminal=%s context=%s',
                    $tenantId,
                    $target['terminal_id'],
                    $target['chain_context'],
                ));

                $exit = $this->call('fiscal:verify-event-chain', [
                    '--tenant' => $tenantId,
                    '--terminal' => $target['terminal_id'],
                    '--chain-context' => $target['chain_context'],
                    '--actor-id' => $actorId,
                ]);

                if ($exit !== self::SUCCESS) {
                    $tenantFailed = true;
                }
            }

            if ($tenantFailed) {
                $this->error(sprintf('TENANT %s: FAILED — one or more chain verifications returned non-zero.', $tenantId));
                $hasFailure = true;
            } else {
                $this->info(sprintf('TENANT %s: VERIFIED %d chain(s).', $tenantId, count($targets)));
            }
        }

        if ($hasFailure) {
            $this->error(sprintf(
                'fleet chain verification FAILED: %d manifest tenant(s) processed, %d chain(s) invoked; see per-tenant output.',
                $tenantsProcessed,
                $chainsProcessed,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'fleet chain verification completed: %d tenant(s), %d chain(s), no failures.',
            $tenantsProcessed,
            $chainsProcessed,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function readManifest(string $path): ?array
    {
        if (! $this->filesystem->exists($path) || ! $this->filesystem->isReadable($path)) {
            $this->error(sprintf('Manifest file %s does not exist or is not readable.', $path));

            return null;
        }

        try {
            $decoded = json_decode($this->filesystem->get($path), false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error(sprintf('Malformed manifest JSON: %s', $e->getMessage()));

            return null;
        }

        if (! $decoded instanceof stdClass) {
            $this->error('Malformed manifest JSON: top-level value must be an object mapping tenant UUIDs to actor UUIDs.');

            return null;
        }

        $manifest = [];
        foreach (get_object_vars($decoded) as $tenantId => $actorId) {
            if (! Str::isUuid($tenantId) || ! is_string($actorId) || ! Str::isUuid($actorId)) {
                $this->error(sprintf(
                    'Malformed manifest entry for tenant %s: both tenant and actor must be UUID strings.',
                    $tenantId,
                ));

                return null;
            }

            $manifest[$tenantId] = $actorId;
        }

        ksort($manifest);

        return $manifest;
    }
}
