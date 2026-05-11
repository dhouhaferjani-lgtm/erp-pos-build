<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

/**
 * Generates the content-addressed `stable_key` for a callsite (master plan
 * Section 4 — "Stable identity"). Two callsites with the same stable key are
 * considered the same callsite across regenerations even if the file moved
 * or the line number changed.
 *
 * Hash inputs (joined by NUL bytes to avoid prefix-collision ambiguity):
 *   - surface             "api" | "web" | "tauri"
 *   - scanner             scanner name (e.g. "php_presentation_exists")
 *   - normalized_relative_path    path from repo root, forward-slashes only
 *   - symbol_fqn          fully-qualified symbol (e.g. "App\\Modules\\X\\Foo::bar")
 *   - ast_node_kind       node kind (e.g. "inline_string_rule", "rule_exists_builder")
 *   - model_or_table      table/model short name; "" if not applicable
 *   - field_or_method     field/method label; "" if not applicable
 *   - normalized_argument_name  argument name; "" if not applicable
 *   - statement_fingerprint     scanner-specific extra discriminator (e.g. "tenant_and_company"); "" if none
 *
 * Inputs are coerced to strings; nulls become the empty string. The stable
 * key is rendered as `sha256:<64hex>`.
 *
 * Manual callsites use a different prefix (`manual:<cluster>:<slug>`) and do
 * NOT go through this helper — see {@see ManualScanner}. The prefix split
 * means scanner output and manual output never collide.
 */
final class StableKey
{
    /**
     * @param  array{
     *   surface: string,
     *   scanner: string,
     *   normalized_relative_path: string,
     *   symbol_fqn: string,
     *   ast_node_kind: string,
     *   model_or_table?: string|null,
     *   field_or_method?: string|null,
     *   normalized_argument_name?: string|null,
     *   statement_fingerprint?: string|null,
     * }  $parts
     */
    public static function fromScannerOutput(array $parts): string
    {
        $ordered = [
            (string) $parts['surface'],
            (string) $parts['scanner'],
            (string) $parts['normalized_relative_path'],
            (string) $parts['symbol_fqn'],
            (string) $parts['ast_node_kind'],
            (string) ($parts['model_or_table'] ?? ''),
            (string) ($parts['field_or_method'] ?? ''),
            (string) ($parts['normalized_argument_name'] ?? ''),
            (string) ($parts['statement_fingerprint'] ?? ''),
        ];

        return 'sha256:'.hash('sha256', implode("\0", $ordered));
    }
}
