<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Services;

use App\Modules\Accounting\Domain\Account;
use Illuminate\Database\Eloquent\Collection;

/**
 * AccountHierarchyService
 *
 * Domain service responsible for building and managing hierarchical
 * account structures for financial reporting.
 *
 * Accounts in the chart of accounts can have parent-child relationships
 * (e.g., "Cash" is a child of "Current Assets"). This service transforms
 * flat account lists into hierarchical trees and calculates subtotals
 * at each level.
 *
 * Key Responsibilities:
 * - Build tree structure from flat account collections
 * - Calculate subtotals bottom-up through the hierarchy
 * - Flatten trees back to display format with indentation levels
 * - Support multi-level nesting (unlimited depth)
 *
 * Architectural Notes:
 * - Domain Layer service (pure business logic)
 * - No dependencies on infrastructure or application layers
 * - Stateless - each method is a pure function
 * - Immutable operations - does not modify input collections
 *
 * Algorithm Complexity:
 * - buildTree: O(n) where n = number of accounts
 * - calculateSubtotals: O(n) recursive traversal
 * - flattenTree: O(n) depth-first traversal
 *
 * @package App\Modules\Accounting\Domain\Services
 */
class AccountHierarchyService
{
    /**
     * Decimal scale for financial calculations (4 decimal places).
     *
     * This constant defines the precision used in bcmath operations.
     * Financial data typically requires 2-4 decimal places.
     */
    private const DECIMAL_SCALE = 4;

    /**
     * Maximum allowed hierarchy depth to prevent infinite loops.
     *
     * If hierarchy exceeds this depth, a circular reference is likely.
     */
    private const MAX_HIERARCHY_DEPTH = 100;

    /**
     * Build a hierarchical tree structure from a flat collection of accounts.
     *
     * Takes a flat list of accounts (with parent_id relationships) and
     * constructs a nested tree structure. Orphaned accounts (parent_id
     * points to non-existent account) are treated as root-level accounts.
     *
     * Algorithm:
     * 1. Index all accounts by ID for O(1) lookup
     * 2. Build parent-child relationships
     * 3. Identify root accounts (parent_id = null or parent not found)
     * 4. Assign hierarchy levels recursively
     *
     * @param Collection<int, Account> $accounts Flat collection of accounts with balance data
     * @return list<AccountNode> Root-level account nodes (forest structure)
     *
     * @example
     * ```php
     * // Input: [
     * //   {id: 1, code: '100', name: 'Assets', parent_id: null, balance: '1000'},
     * //   {id: 2, code: '110', name: 'Current Assets', parent_id: 1, balance: '600'},
     * //   {id: 3, code: '111', name: 'Cash', parent_id: 2, balance: '300'}
     * // ]
     * //
     * // Output tree:
     * // Assets (balance: 1000, level: 0)
     * //   ├─ Current Assets (balance: 600, level: 1)
     * //   │   └─ Cash (balance: 300, level: 2)
     * ```
     */
    public function buildTree(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        // Step 1: Index accounts by ID for fast lookup
        /** @var array<string, AccountNode> $indexed */
        $indexed = [];

        foreach ($accounts as $account) {
            $node = new AccountNode();
            $node->account = $account;
            $node->level = 0;
            // Ensure balance is always a string (handle null values)
            $node->balance = (string) ($account->balance ?? '0.00');
            $node->isParent = false;
            $node->children = [];

            $indexed[$account->id] = $node;
        }

        // Step 2: Build parent-child relationships
        /** @var list<AccountNode> $roots */
        $roots = [];

        foreach ($indexed as $id => $node) {
            $parentId = $node->account->parent_id;

            if ($parentId === null) {
                // Account with no parent → root level
                $roots[] = $node;
            } elseif (isset($indexed[$parentId])) {
                // Parent exists → add as child
                $parent = $indexed[$parentId];
                $parent->children[] = $node;
                $parent->isParent = true;
            } else {
                // Orphaned account (parent doesn't exist) → treat as root
                $roots[] = $node;
            }
        }

        // Step 3: Assign hierarchy levels recursively
        $this->assignLevels($roots, 0);

        return $roots;
    }

    /**
     * Calculate subtotals for all parent accounts in the tree.
     *
     * Traverses the tree bottom-up, summing child balances into parent
     * accounts. This ensures that parent account balances represent the
     * sum of all their descendants.
     *
     * Mutation Warning: This method MODIFIES the tree nodes in place
     * by updating their balance fields.
     *
     * Uses bcmath for precise decimal arithmetic (required for financial data).
     *
     * @param list<AccountNode> $tree Tree structure from buildTree()
     * @return void Modifies the tree in place
     *
     * @example
     * ```php
     * // Before:
     * // Assets (balance: 0)
     * //   └─ Cash (balance: 500)
     * //
     * // After calculateSubtotals():
     * // Assets (balance: 500)  ← Updated with sum of children
     * //   └─ Cash (balance: 500)
     * ```
     */
    public function calculateSubtotals(array &$tree): void
    {
        foreach ($tree as $node) {
            if ($node->isParent) {
                // Recursively calculate children subtotals first (bottom-up)
                $this->calculateSubtotals($node->children);

                // Sum all children balances using consistent decimal scale
                $subtotal = '0.00';
                foreach ($node->children as $child) {
                    $subtotal = bcadd($subtotal, $child->balance, self::DECIMAL_SCALE);
                }

                // Update parent balance with calculated subtotal
                $node->balance = $subtotal;
            }
        }
    }

    /**
     * Flatten a hierarchical tree into a linear list for display.
     *
     * Performs a depth-first traversal, converting the tree structure
     * into a flat array while preserving hierarchy information via
     * the 'level' field.
     *
     * The level field can be used for indentation in UI:
     * - level 0: No indentation
     * - level 1: Indent 2 spaces
     * - level 2: Indent 4 spaces, etc.
     *
     * Order is preserved (parent before children, siblings in original order).
     *
     * @param list<AccountNode> $tree Tree structure from buildTree()
     * @return list<AccountNode> Flattened list with level indicators
     *
     * @example
     * ```php
     * // Input tree:
     * // Assets (level: 0)
     * //   └─ Cash (level: 1)
     * //
     * // Output flat list:
     * // [
     * //   {account: Assets, level: 0, balance: 500},
     * //   {account: Cash, level: 1, balance: 500}
     * // ]
     * ```
     */
    public function flattenTree(array $tree): array
    {
        $flat = [];

        foreach ($tree as $node) {
            // Add current node
            $flat[] = $node;

            // Recursively add children (depth-first)
            if ($node->isParent && ! empty($node->children)) {
                $childrenFlat = $this->flattenTree($node->children);
                $flat = array_merge($flat, $childrenFlat);
            }
        }

        return $flat;
    }

    /**
     * Recursively assign hierarchy levels to all nodes in the tree.
     *
     * Level indicates the depth of the account in the hierarchy:
     * - 0: Root level (no parent)
     * - 1: Direct child of root
     * - 2: Grandchild of root, etc.
     *
     * This is a private helper method called during tree construction.
     *
     * Includes circular reference detection to prevent infinite loops.
     *
     * @param list<AccountNode> $nodes Array of nodes at current level
     * @param int $level Current hierarchy level (0 = root)
     * @param array<string> $visited Account IDs visited in current branch (for cycle detection)
     * @return void Modifies nodes in place
     * @throws \RuntimeException If circular reference detected or max depth exceeded
     */
    private function assignLevels(array $nodes, int $level, array $visited = []): void
    {
        // Sanity check: prevent infinite recursion
        if ($level > self::MAX_HIERARCHY_DEPTH) {
            throw new \RuntimeException(
                'Account hierarchy exceeds maximum depth of '.self::MAX_HIERARCHY_DEPTH.
                ' (possible circular reference or data quality issue)'
            );
        }

        foreach ($nodes as $node) {
            $accountId = $node->account->id;

            // Detect circular references
            if (in_array($accountId, $visited, true)) {
                throw new \RuntimeException(
                    "Circular reference detected in account hierarchy. ".
                    "Account {$accountId} ({$node->account->code} - {$node->account->name}) ".
                    "is its own ancestor."
                );
            }

            $node->level = $level;

            if (! empty($node->children)) {
                // Add current account to visited list for children
                $this->assignLevels($node->children, $level + 1, [...$visited, $accountId]);
            }
        }
    }

    /**
     * Filter tree nodes by predicate function.
     *
     * Useful for filtering accounts by type, status, or other criteria
     * while maintaining hierarchy structure.
     *
     * By default, parent accounts are kept even if they don't match the predicate,
     * as long as they have matching children. Set $keepEmptyParents to false to
     * exclude parents that don't match.
     *
     * @param list<AccountNode> $tree The tree to filter
     * @param callable(AccountNode): bool $predicate Filter function
     * @param bool $keepEmptyParents Whether to keep parents with matching children (default: true)
     * @return list<AccountNode> Filtered tree
     *
     * @example
     * ```php
     * // Filter to only active accounts (keeps parents with active children)
     * $filtered = $service->filterTree($tree, fn($node) => $node->account->is_active);
     *
     * // Filter to only asset accounts (strict - parents must also be assets)
     * $assets = $service->filterTree($tree, fn($node) => $node->account->type === AccountType::Asset, false);
     * ```
     */
    public function filterTree(array $tree, callable $predicate, bool $keepEmptyParents = true): array
    {
        $filtered = [];

        foreach ($tree as $node) {
            // Recursively filter children first
            if (! empty($node->children)) {
                $node->children = $this->filterTree($node->children, $predicate, $keepEmptyParents);
            }

            $hasMatchingChildren = ! empty($node->children);
            $matchesPredicate = $predicate($node);

            // Include node if it matches OR (keepEmptyParents AND has matching children)
            if ($matchesPredicate || ($keepEmptyParents && $hasMatchingChildren)) {
                $filtered[] = $node;
            }
        }

        return $filtered;
    }

    /**
     * Get the maximum depth of the tree.
     *
     * Useful for UI rendering decisions (e.g., limiting expansion depth).
     *
     * @param list<AccountNode> $tree The tree to analyze
     * @return int Maximum depth (0 for empty tree)
     *
     * @example
     * ```php
     * $maxDepth = $service->getMaxDepth($tree); // Returns 3 for 3-level hierarchy
     * ```
     */
    public function getMaxDepth(array $tree): int
    {
        if (empty($tree)) {
            return 0;
        }

        $maxDepth = 0;

        foreach ($tree as $node) {
            $depth = $node->level;

            if (! empty($node->children)) {
                $childDepth = $this->getMaxDepth($node->children);
                $depth = max($depth, $childDepth);
            }

            $maxDepth = max($maxDepth, $depth);
        }

        return $maxDepth;
    }
}

/**
 * AccountNode
 *
 * Value object representing a node in the account hierarchy tree.
 *
 * This is a lightweight data structure used internally by AccountHierarchyService.
 * It wraps an Account model with hierarchy metadata (level, children, etc.).
 *
 * Properties:
 * - account: The underlying Account domain entity
 * - level: Depth in hierarchy (0 = root)
 * - balance: Account balance (numeric-string for precision)
 * - isParent: Whether this account has children
 * - children: Array of child AccountNodes
 *
 * Design Notes:
 * - Not a full domain entity (no persistence)
 * - Used only for in-memory tree operations
 * - Keeps hierarchy logic separate from Account model
 *
 * @package App\Modules\Accounting\Domain\Services
 */
class AccountNode
{
    /**
     * The account entity this node represents.
     */
    public Account $account;

    /**
     * Hierarchy depth (0 = root, 1 = child of root, etc.).
     */
    public int $level;

    /**
     * Account balance (includes subtotals if calculated).
     *
     * @var numeric-string Decimal string for precision (e.g., "1234.56")
     */
    public string $balance;

    /**
     * Whether this account has children.
     */
    public bool $isParent;

    /**
     * Child account nodes.
     *
     * @var list<AccountNode>
     */
    public array $children;

    /**
     * Create a new account node.
     *
     * Typically created by AccountHierarchyService->buildTree().
     */
    public function __construct()
    {
        $this->level = 0;
        $this->balance = '0.00';
        $this->isParent = false;
        $this->children = [];
    }

    /**
     * Get the account code for display.
     */
    public function getCode(): string
    {
        return $this->account->code;
    }

    /**
     * Get the account name for display.
     */
    public function getName(): string
    {
        return $this->account->name;
    }

    /**
     * Get indentation string for UI display.
     *
     * Generates spaces based on hierarchy level.
     *
     * @param int $spacesPerLevel Number of spaces per level (default: 2)
     * @return string Indentation spaces
     *
     * @example
     * ```php
     * $node->level = 2;
     * echo $node->getIndentation(); // "    " (4 spaces)
     * ```
     */
    public function getIndentation(int $spacesPerLevel = 2): string
    {
        return str_repeat(' ', $this->level * $spacesPerLevel);
    }

    /**
     * Check if this node has any children.
     */
    public function hasChildren(): bool
    {
        return ! empty($this->children);
    }

    /**
     * Get count of direct children.
     */
    public function getChildCount(): int
    {
        return count($this->children);
    }

    /**
     * Get count of all descendants (recursive).
     *
     * @return int Total number of descendants
     */
    public function getDescendantCount(): int
    {
        $count = count($this->children);

        foreach ($this->children as $child) {
            $count += $child->getDescendantCount();
        }

        return $count;
    }
}
