# Request-hygiene Phase A — whole-suite CI evidence (2026-09-05)

Three `workflow_dispatch` runs of `.github/workflows/ci.yml` (the only way the Unit suite, the PG-invariant jobs and the 'Full backend suite (manual security gate)' step execute — PR→dev runs skip them). The 8 self-hosted Feature lanes are skipped on every run (`vars.SELF_HOSTED_RUNNER_READY` unset, O-29).

| Run | Ref | Tip | Purpose | Status |
|---|---|---|---|---|
| A | `ci/rh-phase-a-2026-09-05` | `f75aa5023` (dev at session start = Phase A complete, before today's reconciliation) | first whole-suite pass | completed 15:31Z, failure (see §A) |
| B | `ci/rh-phase-a-2026-09-05-b` | `189fe7d8a` (dev after reconciliation + Dhouha PRs #208/#209/#211-#216) | promotion candidate | 33972668125, in progress |
| baseline | `ci/origin-dev-baseline-2026-09-05` | `4d5b8812e` (= origin/dev, what staging runs today) | pre-existing-red baseline | 33975221815, in progress |

Promotion rule: a test is a Phase A/PR regression only if it fails in B and passes in the baseline. Everything failing in both is pre-existing debt on origin/dev and is filed, not fixed here.

## §A Run A (33960261334, `f75aa5023`)

Jobs red: Deptrac (feature-lane manifest step), Route Manifest, PHPStan, Chokepoint, Types Drift, Pint, PG invariants, Unit, Frontend Lint, Treasury spine, POS Vitest, Frontend Vitest. All non-test jobs were reproduced locally and reconciled on dev during the day (commits `3df25b94f`, `fa000edc3`, `c01d12a1a`, `0873fbec5`, `189fe7d8a`).

'Full backend suite (manual security gate)' step (10:39Z→15:31Z): 228 distinct failing tests. Per class:

```
  16 Tests\Unit\POS\ReceiptReturnServiceDispositionTest
  15 Tests\Feature\Inventory\CogsRelocationCharacterisationTest
  13 Tests\Unit\POS\CashCountValidationServiceTest
  12 Tests\Feature\Service\Api\ServiceCategoryApiTest
  11 Tests\Feature\Service\Api\ServiceApiTest
  11 Tests\Feature\POS\PosReturnScrapWriteOffTest
  10 Tests\Feature\Treasury\OutboundInstrumentServiceTest
  10 Tests\Feature\Service\IngressPrecisionTest
   9 Tests\Feature\Service\ServiceTenantIsolationTest
   8 Tests\Unit\POS\ZReportHashServiceTest
   8 Tests\Feature\Treasury\OutboundCancelReopenTest
   8 Tests\Feature\Expense\ExpensePayByInstrumentTest
   7 Tests\Architecture\FeatureLaneManifestCheckerTest
   6 Tests\Feature\Treasury\DeferredSupplierPaymentTest
   3 Tests\Unit\CountryDefaults\FrozenSeederDocblockTest
   3 Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterDesignationTest
   3 Tests\Feature\Treasury\StatementCreationActionTest
   3 Tests\Feature\Procurement\SupplierInvoiceApiTest
   3 Tests\Feature\POS\ServerSideZReportOverTenderTest
   3 Tests\Feature\Console\Sweep\SweepInventoryDeferCommandTest
   3 Tests\Feature\Catalog\ProductVariantRepositoryTest
   2 Tests\Unit\POS\ReceiptReturnServiceTest
   2 Tests\Unit\Inventory\GoodsReceiptDataTest
   2 Tests\Feature\Workshop\WorkOrder\DocumentVehicleContextWrittenOnInvoiceTest
   2 Tests\Feature\Treasury\InstrumentClearTest
   2 Tests\Feature\Treasury\AcquirerFeeServiceTest
   2 Tests\Feature\Import\ProductsImportPipelineTest
   2 Tests\Feature\Import\ColumnMappingTest
   2 Tests\Feature\Fiscal\ChokepointCompletenessTest
   2 Tests\Feature\Document\InventoryGlCompositeRootTest
   1 Tests\Unit\Product\ProductServiceUpsertTest
   1 Tests\Unit\Import\ProductPriceResolverTest
   1 Tests\Unit\Fiscal\StrictCanonicalParserTest
   1 Tests\Unit\Fiscal\SaleReceiptV3KeySetTest
   1 Tests\Unit\Fiscal\FiscalEventPayloadRegistryTest
   1 Tests\Unit\CountryDefaults\ProvisioningRequiredPurposesV1ConformanceTest
   1 Tests\Unit\CountryDefaults\ProvisioningRequiredPurposesRegistrationRatchetTest
   1 Tests\Feature\Workshop\WorkOrder\InvoiceZeroLineWorkOrderFailsTest
   1 Tests\Feature\Workshop\WorkOrder\DocumentLineWorkOrderLineIdRetentionTest
   1 Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterStripsSubToleranceDiscountTest
   1 Tests\Feature\Treasury\StatementActionHandlerTest
   1 Tests\Feature\Treasury\ReconcilePortfolioCheckTest
   1 Tests\Feature\Treasury\PaymentTest
   1 Tests\Feature\Treasury\OutboundInstrumentEndpointsTest
   1 Tests\Feature\Tenant\TenantCreationTest
   1 Tests\Feature\Taxation\FranceTaxConfigurationSeederTest
   1 Tests\Feature\Seeders\DemoPharmacySeederTest
   1 Tests\Feature\Seeders\DemoPharmacySeederExpensesTest
   1 Tests\Feature\Seeders\DemoPharmacyBatchSeedingTest
   1 Tests\Feature\Product\ProductPricingIntentServiceTest
   1 Tests\Feature\Product\ProductMarginIntentEndToEndTest
   1 Tests\Feature\Product\DiscountPolicySubjectProviderTest
   1 Tests\Feature\Partner\PartnerReferenceSchemaSweepTest
   1 Tests\Feature\Inventory\ZoneScopedCountingTest
   1 Tests\Feature\Inventory\PosMovementCostSnapshotTest
   1 Tests\Feature\Inventory\GoodsReceiptLedgerSchemaTest
   1 Tests\Feature\Inventory\ExitMovementPrecisionTest
   1 Tests\Feature\Import\RoundTrip\CompositeItemsRoundTripTest
   1 Tests\Feature\Import\ProductIdentityResolutionTest
   1 Tests\Feature\Import\ImportTypesTest
   1 Tests\Feature\Import\ImportPreviewTest
   1 Tests\Feature\Identity\UserManagement\CreateUserTest
   1 Tests\Feature\Fiscal\ZReportServerAuthoringChokepointTest
   1 Tests\Feature\Document\DeliveryNoteBillingMarkerMigrationTest
   1 Tests\Feature\Document\CreditNoteAllocationTest
   1 Tests\Feature\CountryDefaults\FrozenSeederProvisioningIsolationTest
   1 Tests\Feature\Console\ExportFrontendPermissionsMapCommandTest
   1 Tests\Feature\Catalog\CatalogTenantIsolationTest
   1 Tests\Feature\Catalog\AttributeValueRepositoryTest
   1 Tests\Feature\Admin\CentralModelPinningTest
   1 Tests\Architecture\QueueJobTenantContextTest
   1 Tests\Architecture\DocumentPerActionBaselineRatchetTest
   1 Tests\Architecture\ControllerTenantContextTest
   1 Tests\Architecture\ConsoleCommandTenantContextTest
   1 Tests\Architecture\AuthLifecycleTest
```

Full list (class::method):

```
Tests\Architecture\AuthLifecycleTest::test_every_auth_sanctum_route_group_includes_set_permissions_team
Tests\Architecture\ConsoleCommandTenantContextTest::test_every_concrete_artisan_command_is_tenant_classified
Tests\Architecture\ControllerTenantContextTest::test_every_controller_method_is_classified
Tests\Architecture\DocumentPerActionBaselineRatchetTest::the_working_baseline_never_grows_against_the_owner_pinned_blob
Tests\Architecture\FeatureLaneManifestCheckerTest::test_a_mis_cased_event_context_is_still_allowed
Tests\Architecture\FeatureLaneManifestCheckerTest::test_it_accepts_a_lane_carrying_only_neutral_flags
Tests\Architecture\FeatureLaneManifestCheckerTest::test_it_accepts_an_always_true_step_if
Tests\Architecture\FeatureLaneManifestCheckerTest::test_it_does_not_flag_a_pnpm_workspace_filter
Tests\Architecture\FeatureLaneManifestCheckerTest::test_it_does_not_flag_env_prefixed_pnpm_filters
Tests\Architecture\FeatureLaneManifestCheckerTest::test_it_passes_on_the_real_tree
Tests\Architecture\FeatureLaneManifestCheckerTest::test_relabelling_deferred_as_excluded_does_not_erase_the_debt
Tests\Architecture\QueueJobTenantContextTest::test_every_concrete_queue_job_is_tenant_classified
Tests\Feature\Admin\CentralModelPinningTest::test_no_billing_table_migrations_remain_in_the_tenant_set
Tests\Feature\Catalog\AttributeValueRepositoryTest::test_invalid_hex_color_rejected_by_db
Tests\Feature\Catalog\CatalogTenantIsolationTest::test_reject_enrichment_result_rejects_cross_tenant_id
Tests\Feature\Catalog\ProductVariantRepositoryTest::test_barcode_unique_when_present
Tests\Feature\Catalog\ProductVariantRepositoryTest::test_only_one_default_variant_per_product
Tests\Feature\Catalog\ProductVariantRepositoryTest::test_sku_unique_per_tenant
Tests\Feature\Console\ExportFrontendPermissionsMapCommandTest::test_the_committed_frontend_map_is_fresh_against_the_seeder
Tests\Feature\Console\Sweep\SweepInventoryDeferCommandTest::test_defer_callsite_transitions_claimed_to_deferred
Tests\Feature\Console\Sweep\SweepInventoryDeferCommandTest::test_defer_callsite_transitions_pending_to_deferred_with_revisit_date_in_note
Tests\Feature\Console\Sweep\SweepInventoryDeferCommandTest::test_defer_cluster_transitions_cluster_and_every_eligible_callsite_in_one_mutation
Tests\Feature\CountryDefaults\FrozenSeederProvisioningIsolationTest::test_frozen_seeder_tests_use_non_excludable_fixture_marker_or_true_historical_label
Tests\Feature\Document\CreditNoteAllocationTest::it_creates_credit_note_allocation_when_posting
Tests\Feature\Document\DeliveryNoteBillingMarkerMigrationTest::test_it_backfills_every_stamped_delivery_note_with_safe_attribution_and_auditable_counts
Tests\Feature\Document\InventoryGlCompositeRootTest::test_c3_and_c1_production_roots_flush_their_nested_writers
Tests\Feature\Document\InventoryGlCompositeRootTest::test_c5_guided_delivery_root_flushes_its_nested_writer
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_cancel_event_unlinks_instrument_and_resets_payment_fields
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_cancelled_instrument_can_be_replaced_without_reusing_its_row_key
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_cash_mode_regression_still_records_one_settlement_movement
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_clear_event_marks_expense_paid_from_the_instrument_and_clear_movement
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_clear_listener_failure_rolls_back_gl_movement_instrument_and_expense_metadata
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_effet_settlement_uses_effets_payable_and_preserves_maturity
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_instrument_settlement_posts_issue_entry_without_moving_cash_and_links_unpaid_expense
Tests\Feature\Expense\ExpensePayByInstrumentTest::test_retry_and_cash_settlement_are_rejected_while_linked_instrument_is_pending
Tests\Feature\Fiscal\ChokepointCompletenessTest::test_every_create_receipt_callsite_is_reconciled_in_the_manifest
Tests\Feature\Fiscal\ChokepointCompletenessTest::test_every_finalize_callsite_is_reconciled_with_receiver_type
Tests\Feature\Fiscal\ZReportServerAuthoringChokepointTest::test_z_report_generation_call_sites_are_known_and_cutover_guarded
Tests\Feature\Identity\UserManagement\CreateUserTest::test_store_creates_null_membership_for_new_staff
Tests\Feature\Import\ColumnMappingTest::test_apply_column_mapping_drops_unmapped_columns
Tests\Feature\Import\ColumnMappingTest::test_null_mapping_returns_rows_unchanged
Tests\Feature\Import\ImportPreviewTest::test_preview_requires_authentication
Tests\Feature\Import\ImportTypesTest::test_opening_balance_import_marks_missing_account_row_invalid_without_aborting
Tests\Feature\Import\ProductIdentityResolutionTest::test_two_company_local_barcode_matches_are_reported_as_ambiguous
Tests\Feature\Import\ProductsImportPipelineTest::test_real_no_barcode_fixture_imports_856_rows_and_refuses_only_three_negative_quantities
Tests\Feature\Import\ProductsImportPipelineTest::test_real_products_workbook_reports_all_859_piece_rows_and_preserves_the_three_quantity_errors
Tests\Feature\Import\RoundTrip\CompositeItemsRoundTripTest::test_today_over_precision_money_is_accepted_and_stored_as_is
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_a_delivery_note_confirm_creates_a_classified_stock_movement
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_a_posted_invoice_with_physical_lines_creates_no_invoice_keyed_cogs_entry
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_a_return_note_confirm_creates_a_movement_keyed_inventory_entry
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_a_service_only_invoice_creates_no_cogs_entry
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_a_zero_cost_product_is_silently_skipped
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_delivery_third_line_gl_failure_rolls_back_movements_seal_and_chain_sequence
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_delivery_without_inventory_accounts_still_confirms_and_warns
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_invoice_posted_has_no_legacy_cogs_listener_and_revenue_still_posts
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_new_company_cutover_watermark_matches_its_creation_instant
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_pre_cutover_device_event_replayed_after_cutover_posts_cogs_by_server_creation_time
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_return_entry_uses_the_delivery_time_cost_after_live_wac_moves
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_t11c_pair_one_delivery_writer_posts_only_after_its_inventory_loop
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_t11c_pair_three_pos_writer_posts_only_after_its_inventory_loop
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_t11c_pair_two_return_writer_posts_only_after_its_inventory_loop
Tests\Feature\Inventory\CogsRelocationCharacterisationTest::test_the_two_live_pos_writers_create_costed_movements
Tests\Feature\Inventory\ExitMovementPrecisionTest::test_a_return_note_original_cost_keeps_all_six_decimals
Tests\Feature\Inventory\GoodsReceiptLedgerSchemaTest::goods_receipt_ledger_tables_match_the_receipt_grain_contract
Tests\Feature\Inventory\PosMovementCostSnapshotTest::test_the_renamed_resolver_returns_the_same_value_for_every_shape
Tests\Feature\Inventory\ZoneScopedCountingTest::test_location_hierarchy_counting_flow_keeps_variant_stock_at_location_grain
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_non_scrap_restock_has_no_write_off_and_reverses_the_original_sale_cost
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_pos_scrap_write_off_cannot_be_reversed
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_pair_leaves_the_running_average_cost_correct_for_a_later_receive
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_with_over_reserved_stock_is_contained_and_the_refund_still_completes
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_with_soft_deleted_product_rolls_back_both_legs_and_still_refunds
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_write_off_journal_entry_reaches_posted_and_moves_both_accounts
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_write_off_movement_carries_unit_cost_and_total_cost
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_write_off_movement_uses_the_s0_reference_type_enum
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_scrap_write_off_posts_movement_keyed_shrinkage_inventory_entry
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_t11c_scrap_company_advisory_is_terminal_to_the_full_inventory_loop with data set "pair 7 — POS refund scrap x DN confirm" ('pos-refund-scrap_x_dn-confirm')
Tests\Feature\POS\PosReturnScrapWriteOffTest::test_t11c_scrap_company_advisory_is_terminal_to_the_full_inventory_loop with data set "pair 8 — POS refund scrap x POS sale" ('pos-refund-scrap_x_pos-sale')
Tests\Feature\POS\ServerSideZReportOverTenderTest::test_server_side_z_report_cash_expected_subtracts_receipt_change_due_once
Tests\Feature\POS\ServerSideZReportOverTenderTest::test_server_side_z_report_does_not_subtract_change_due_from_legacy_net_cash_rows
Tests\Feature\POS\ServerSideZReportOverTenderTest::test_server_side_z_report_treats_lowercase_cash_code_as_cash
Tests\Feature\Partner\PartnerReferenceSchemaSweepTest::test_every_partner_shaped_column_is_either_guarded_or_explicitly_excluded
Tests\Feature\Procurement\SupplierInvoiceApiTest::test_pending_link_receipts_then_post_consumes_receipt_and_clears_gr_ir
Tests\Feature\Procurement\SupplierInvoiceApiTest::test_post_invoice_first_delivered_posts_zero_ppv_gl_legs
Tests\Feature\Procurement\SupplierInvoiceApiTest::test_post_invoice_first_supplier_invoice_requires_approval_permission_when_policy_requires_it
Tests\Feature\Product\DiscountPolicySubjectProviderTest::test_subject_resolves_tax_configuration_before_tax_rate
Tests\Feature\Product\ProductMarginIntentEndToEndTest::test_editing_margin_via_api_keeps_auto_and_recomputes_price
Tests\Feature\Product\ProductPricingIntentServiceTest::test_margin_edit_keeps_auto_and_persists_override_when_different
Tests\Feature\Seeders\DemoPharmacyBatchSeedingTest::test_reseed_after_demo_usage_reconciles_and_degrades_consumed_fixture
Tests\Feature\Seeders\DemoPharmacySeederExpensesTest::test_seed_tunisia_expenses_posts_paid_expenses_and_decrements_the_till_balance
Tests\Feature\Seeders\DemoPharmacySeederTest::test_seeds_gl_consistent_partner_balances
Tests\Feature\Service\Api\ServiceApiTest::it_creates_a_service
Tests\Feature\Service\Api\ServiceApiTest::it_deletes_a_service
Tests\Feature\Service\Api\ServiceApiTest::it_filters_services_by_active_status
Tests\Feature\Service\Api\ServiceApiTest::it_filters_services_by_pricing_type
Tests\Feature\Service\Api\ServiceApiTest::it_lists_services_for_company
Tests\Feature\Service\Api\ServiceApiTest::it_prevents_duplicate_service_codes
Tests\Feature\Service\Api\ServiceApiTest::it_returns_404_for_non_existent_service
Tests\Feature\Service\Api\ServiceApiTest::it_searches_services_by_name_or_code
Tests\Feature\Service\Api\ServiceApiTest::it_shows_a_single_service
Tests\Feature\Service\Api\ServiceApiTest::it_updates_a_service
Tests\Feature\Service\Api\ServiceApiTest::it_validates_required_fields_when_creating
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_creates_a_category
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_creates_a_nested_category
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_deletes_a_category_without_services
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_lists_categories_for_company
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_lists_only_root_categories
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_prevents_deleting_category_with_children
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_prevents_deleting_category_with_services
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_prevents_duplicate_category_names
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_returns_category_tree
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_shows_a_single_category
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_updates_a_category
Tests\Feature\Service\Api\ServiceCategoryApiTest::it_validates_required_fields_when_creating_category
Tests\Feature\Service\IngressPrecisionTest::test_create_partner_accepts_2_decimal_discount_percentage
Tests\Feature\Service\IngressPrecisionTest::test_create_partner_accepts_3_decimal_credit_limit
Tests\Feature\Service\IngressPrecisionTest::test_create_partner_rejects_3_decimal_discount_percentage
Tests\Feature\Service\IngressPrecisionTest::test_create_partner_rejects_4_decimal_credit_limit
Tests\Feature\Service\IngressPrecisionTest::test_create_product_accepts_2_decimal_sale_price
Tests\Feature\Service\IngressPrecisionTest::test_create_product_rejects_3_decimal_purchase_price
Tests\Feature\Service\IngressPrecisionTest::test_create_product_rejects_3_decimal_sale_price
Tests\Feature\Service\IngressPrecisionTest::test_create_product_rejects_3_decimal_tax_rate
Tests\Feature\Service\IngressPrecisionTest::test_update_partner_rejects_4_decimal_credit_limit
Tests\Feature\Service\IngressPrecisionTest::test_update_product_rejects_3_decimal_sale_price
Tests\Feature\Service\ServiceTenantIsolationTest::test_category_destroy_rejects_cross_tenant_id
Tests\Feature\Service\ServiceTenantIsolationTest::test_category_show_query_includes_tenant_and_company_predicates
Tests\Feature\Service\ServiceTenantIsolationTest::test_category_show_rejects_cross_tenant_id
Tests\Feature\Service\ServiceTenantIsolationTest::test_category_update_rejects_cross_tenant_id
Tests\Feature\Service\ServiceTenantIsolationTest::test_service_catalog_update_service_query_includes_tenant_and_company_predicates
Tests\Feature\Service\ServiceTenantIsolationTest::test_service_destroy_rejects_cross_tenant_id
Tests\Feature\Service\ServiceTenantIsolationTest::test_service_show_query_includes_tenant_and_company_predicates
Tests\Feature\Service\ServiceTenantIsolationTest::test_service_show_rejects_cross_tenant_id
Tests\Feature\Service\ServiceTenantIsolationTest::test_service_update_rejects_cross_tenant_id
Tests\Feature\Taxation\FranceTaxConfigurationSeederTest::test_seeds_five_french_vat_configs_with_20pct_default
Tests\Feature\Tenant\TenantCreationTest::test_tenant_get_database_name_returns_correct_schema
Tests\Feature\Treasury\AcquirerFeeServiceTest::test_acquirer_fee_posts_exact_accounts_and_closes_signed_group
Tests\Feature\Treasury\AcquirerFeeServiceTest::test_unmatch_and_reconfirm_reuses_one_fee_execution_movement_and_journal
Tests\Feature\Treasury\DeferredSupplierPaymentTest::test_backfill_retypes_a_deferred_supplier_payment_and_spares_a_deferred_customer_payment
Tests\Feature\Treasury\DeferredSupplierPaymentTest::test_deferred_customer_and_immediate_supplier_regressions_keep_existing_shapes
Tests\Feature\Treasury\DeferredSupplierPaymentTest::test_deferred_supplier_cheque_posts_one_issue_entry_no_bank_line_and_no_movement
Tests\Feature\Treasury\DeferredSupplierPaymentTest::test_deferred_supplier_effet_credits_effets_payable
Tests\Feature\Treasury\DeferredSupplierPaymentTest::test_deferred_supplier_rejects_a_non_bank_repository
Tests\Feature\Treasury\DeferredSupplierPaymentTest::test_same_idempotency_header_does_not_create_a_second_issue_entry_or_instrument
Tests\Feature\Treasury\InstrumentClearTest::test_clear_with_fee_and_vat_moves_net_equal_to_bank_je_line
Tests\Feature\Treasury\InstrumentClearTest::test_inbound_clear_value_date_rejects_a_reconciled_period
Tests\Feature\Treasury\OutboundCancelReopenTest::test_allocation_failure_after_gl_rolls_back_every_cancel_effect
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_fails_loud_when_linked_payment_has_no_durable_issue_event
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_from_bounced_is_allowed_but_cancel_from_cleared_is_rejected
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_from_received_reopens_document_and_reverses_payment_atomically
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_rejects_an_unresolvable_non_null_payment_link
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_rejects_payment_amount_and_document_currency_drift
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_reopens_multiple_documents_including_partial_allocations
Tests\Feature\Treasury\OutboundCancelReopenTest::test_cancel_replay_returns_original_entry_without_duplicate_reopen
Tests\Feature\Treasury\OutboundInstrumentEndpointsTest::test_admin_can_drive_all_four_outbound_actions
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_bounce_posts_dishonor_and_compensating_in_movement_without_touching_401
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_clear_posts_payable_to_exact_bank_records_out_movement_and_dispatches_event
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_exact_replay_returns_original_artifacts_before_transition_validation
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_identical_bounce_retry_returns_the_original_artifacts
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_movement_port_failure_rolls_back_the_preceding_gl_post_and_clear_state
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_replay_with_different_semantics_throws_conflict_before_transition_validation
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_represent_increments_cycle_and_clears_on_new_keys_without_mutating_cycle_one
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_represent_on_a_never_bounced_cleared_instrument_is_an_invalid_transition
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_representation_gl_failure_rolls_back_cycle_increment
Tests\Feature\Treasury\OutboundInstrumentServiceTest::test_value_dated_clear_and_bounce_reject_writes_inside_a_reconciled_period
Tests\Feature\Treasury\PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance
Tests\Feature\Treasury\ReconcilePortfolioCheckTest::test_pre_cutover_linked_instrument_with_post_cutover_remittance_is_one_excluded_circuit
Tests\Feature\Treasury\StatementActionHandlerTest::test_outbound_handler_clears_received_and_represents_bounced_instrument
Tests\Feature\Treasury\StatementCreationActionTest::test_create_expense_uses_line_value_date_location_and_event_owned_expense_flow
Tests\Feature\Treasury\StatementCreationActionTest::test_create_income_requires_and_uses_explicit_income_account_override
Tests\Feature\Treasury\StatementCreationActionTest::test_value_dated_create_from_line_rejects_a_reconciled_period_atomically
Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterDesignationTest::test_designation_snapshot_is_truncated_to_500_chars_for_very_long_display_names
Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterDesignationTest::test_invoice_line_description_is_display_name_only
Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterDesignationTest::test_invoice_line_notes_is_null_when_wo_line_description_is_null
Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterStripsSubToleranceDiscountTest::test_wo_invoice_preserves_above_tolerance_line_discount
Tests\Feature\Workshop\WorkOrder\DocumentLineWorkOrderLineIdRetentionTest::test_wo_invoice_dl_persists_work_order_line_id
Tests\Feature\Workshop\WorkOrder\DocumentVehicleContextWrittenOnInvoiceTest::test_invoice_posted_from_wo_with_vehicle_writes_context_with_snapshot
Tests\Feature\Workshop\WorkOrder\DocumentVehicleContextWrittenOnInvoiceTest::test_listener_is_idempotent_under_event_replay
Tests\Feature\Workshop\WorkOrder\InvoiceZeroLineWorkOrderFailsTest::test_service_allows_invoicing_a_work_order_that_has_at_least_one_line
Tests\Unit\CountryDefaults\FrozenSeederDocblockTest::test_frozen_seeder_has_marker_and_no_content_drift with data set "France" ('Database\Seeders\FranceChartO...Seeder', '44d1d7216fe7aa2b4f279d664bb78...318634')
Tests\Unit\CountryDefaults\FrozenSeederDocblockTest::test_frozen_seeder_has_marker_and_no_content_drift with data set "Generic" ('Database\Seeders\GenericChart...Seeder', 'd0f13078944e002527c77ef9e8c4f...cbf836')
Tests\Unit\CountryDefaults\FrozenSeederDocblockTest::test_frozen_seeder_has_marker_and_no_content_drift with data set "Tunisia" ('Database\Seeders\TunisiaChart...Seeder', '4e5dae6271dfd835a1bd269936423...4624ad')
Tests\Unit\CountryDefaults\ProvisioningRequiredPurposesRegistrationRatchetTest::test_every_production_throwing_purpose_resolution_site_is_registered
Tests\Unit\CountryDefaults\ProvisioningRequiredPurposesV1ConformanceTest::test_every_evidence_citation_resolves_to_current_source_semantics
Tests\Unit\Fiscal\FiscalEventPayloadRegistryTest::test_returns_event_version_one_for_implemented_types
Tests\Unit\Fiscal\SaleReceiptV3KeySetTest::test_registry_supports_versions_one_two_and_three_and_authors_three
Tests\Unit\Fiscal\StrictCanonicalParserTest::test_rejects_envelope_with_event_version_mismatch_to_registry
Tests\Unit\Import\ProductPriceResolverTest::test_tax_default_contract_can_be_faked_without_database
Tests\Unit\Inventory\GoodsReceiptDataTest::from_model_can_omit_lines_for_receive_response_meta
Tests\Unit\Inventory\GoodsReceiptDataTest::from_model_serializes_receipt_and_lines_with_decimal_strings
Tests\Unit\POS\CashCountValidationServiceTest::test_critical_without_require_manager_pin_does_not_need_pin
Tests\Unit\POS\CashCountValidationServiceTest::test_currency_mismatch_produces_validation_error
Tests\Unit\POS\CashCountValidationServiceTest::test_duplicate_payment_method_id_produces_validation_error
Tests\Unit\POS\CashCountValidationServiceTest::test_happy_path_balanced_returns_info_no_flags
Tests\Unit\POS\CashCountValidationServiceTest::test_malformed_amount_non_numeric_produces_validation_error
Tests\Unit\POS\CashCountValidationServiceTest::test_malformed_amount_scale5_produces_validation_error
Tests\Unit\POS\CashCountValidationServiceTest::test_non_physical_payment_method_produces_validation_error
Tests\Unit\POS\CashCountValidationServiceTest::test_over_by_hard_plus_one_returns_critical_with_pin
Tests\Unit\POS\CashCountValidationServiceTest::test_over_by_soft_plus_one_unit_returns_warning
Tests\Unit\POS\CashCountValidationServiceTest::test_over_by_soft_threshold_returns_info
Tests\Unit\POS\CashCountValidationServiceTest::test_tnd_scale_precision_small_variance_is_info
Tests\Unit\POS\CashCountValidationServiceTest::test_under_by_hard_plus_one_returns_critical
Tests\Unit\POS\CashCountValidationServiceTest::test_unknown_payment_method_id_produces_validation_error
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_absent_disposition_defaults_to_restock_and_increases_stock
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_default_no_disposition_return_line_persists_restock
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_explicit_restock_increases_stock_and_runs_batch_restitution
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_never_policy_product_default_disposition_restock_throws
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_never_policy_product_not_received_disposition_succeeds
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_never_policy_product_restock_disposition_throws
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_never_policy_product_scrap_disposition_succeeds
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_normal_product_no_policy_restock_still_works
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_not_received_return_line_persists_disposition_and_physical_receipt_false
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_not_received_with_physical_receipt_true_throws_invalid_argument_exception
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_not_received_writes_zero_movements
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_restock_with_physical_receipt_false_throws_invalid_argument_exception
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_scrap_return_line_persists_disposition_physical_receipt_resalable
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_scrap_writes_two_movements_and_skips_batch
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_unknown_disposition_string_throws_invalid_argument_exception
Tests\Unit\POS\ReceiptReturnServiceDispositionTest::test_variant_aware_scrap_both_movements_target_variant_row
Tests\Unit\POS\ReceiptReturnServiceTest::test_full_return_after_partial_caps_cumulative_batch_restitution
Tests\Unit\POS\ReceiptReturnServiceTest::test_partial_return_restores_batch_stock_proportionally
Tests\Unit\POS\ZReportHashServiceTest::test_calculate_hash_changes_with_different_previous_hash
Tests\Unit\POS\ZReportHashServiceTest::test_calculate_hash_is_deterministic
Tests\Unit\POS\ZReportHashServiceTest::test_calculate_hash_returns_sha256
Tests\Unit\POS\ZReportHashServiceTest::test_calculate_hash_uses_genesis_when_no_previous_hash
Tests\Unit\POS\ZReportHashServiceTest::test_find_chain_break_returns_null_for_valid_chain
Tests\Unit\POS\ZReportHashServiceTest::test_get_next_z_number_increments
Tests\Unit\POS\ZReportHashServiceTest::test_verify_chain_returns_false_for_tampered_hash
Tests\Unit\POS\ZReportHashServiceTest::test_verify_chain_returns_true_for_valid_chain
Tests\Unit\Product\ProductServiceUpsertTest::test_upsert_ignores_nonexistent_category_name
```

Known at a glance: FeatureLaneManifestCheckerTest ×7 and ChokepointCompletenessTest ×2 are the manifest/chokepoint reds fixed in `3df25b94f`; ProductsImportPipelineTest ×2 depend on owner-local xlsx fixtures; ReceiptReturnServiceTest ×2 are the documented batch-restitution reds (night handover §4). The rest await the baseline diff (§B/§C to be appended when runs B and baseline complete).

## §B Run B vs baseline

Jobs compared: B = run `33972668125` on `189fe7d8a`; baseline = run `33975221815` on `4d5b8812e` (= origin/dev). Job ids below are each run's `databaseId` for that job name (`gh run view <run> --json jobs`). Promotion rule: REGRESSION = fails in B, passes/absent in baseline. FIXED = fails in baseline, absent in B. SHARED = fails in both (pre-existing origin/dev debt, not created by this promotion).

### B.1 Backend Tests (PHPUnit) — job B `101323832949` / baseline `101330624672`

Scope: the "Full backend suite (manual security gate)" step only (`./vendor/bin/phpunit <dir>` per top-level `tests/*` directory, classic PHPUnit output, 78 directory groups incl. `tests/Unit` re-run, `tests/Integration`, `tests/Architecture`, `tests/PHPStan`). The earlier "Run unit tests" (Pest pretty-printer, `--testsuite=Unit`) step's failures are a strict subset of this step's `tests/Unit` group and are not counted twice.

**Totals**: B failing = 218 distinct tests (221 occurrences incl. dataset variants) · baseline failing = 236 distinct (239 occurrences).

**REGRESSIONS (1)** — fails in B, absent in baseline:

- `Tests\Feature\Treasury\ReconcileTreasuryTest::test_one_millime_scale_mismatch_is_tolerated_and_does_not_false_freeze`
  First assertion line: `A 1-millime scale mismatch must be tolerated, not frozen. Failed asserting that 1 is identical to 0.` (`tests/Feature/Treasury/ReconcileTreasuryTest.php:808`)

**FIXED (19)** — fails in baseline, absent in B (per-class):

```
  7 Tests\Architecture\FeatureLaneManifestCheckerTest
  9 Tests\Unit\POS\ReceiptReturnServiceTest
  2 Tests\Feature\Fiscal\QuarantineBestEffortParseControllerTest
  1 Tests\Feature\Console\ExportFrontendPermissionsMapCommandTest
```

**SHARED (217 methods / 73 classes)** — pre-existing origin/dev debt, per-class counts:

```
  16 Tests\Unit\POS\ReceiptReturnServiceDispositionTest
  15 Tests\Feature\Inventory\CogsRelocationCharacterisationTest
  13 Tests\Unit\POS\CashCountValidationServiceTest
  12 Tests\Feature\Service\Api\ServiceCategoryApiTest
  11 Tests\Feature\Service\Api\ServiceApiTest
  10 Tests\Feature\POS\PosReturnScrapWriteOffTest
  10 Tests\Feature\Service\IngressPrecisionTest
  10 Tests\Feature\Treasury\OutboundInstrumentServiceTest
   9 Tests\Feature\Service\ServiceTenantIsolationTest
   8 Tests\Feature\Expense\ExpensePayByInstrumentTest
   8 Tests\Feature\Treasury\OutboundCancelReopenTest
   8 Tests\Unit\POS\ZReportHashServiceTest
   6 Tests\Feature\Treasury\DeferredSupplierPaymentTest
   3 Tests\Feature\Catalog\ProductVariantRepositoryTest
   3 Tests\Feature\Console\Sweep\SweepInventoryDeferCommandTest
   3 Tests\Feature\POS\ServerSideZReportOverTenderTest
   3 Tests\Feature\Procurement\SupplierInvoiceApiTest
   3 Tests\Feature\Treasury\StatementCreationActionTest
   3 Tests\Feature\Workshop\WorkOrder\DocumentGenerationAdapterDesignationTest
   2 Tests\Feature\Document\InventoryGlCompositeRootTest
   2 Tests\Feature\Fiscal\ChokepointCompletenessTest
   2 Tests\Feature\Import\ColumnMappingTest
   2 Tests\Feature\Import\ProductsImportPipelineTest
   2 Tests\Feature\Treasury\AcquirerFeeServiceTest
   2 Tests\Feature\Treasury\InstrumentClearTest
   2 Tests\Feature\Workshop\WorkOrder\DocumentVehicleContextWrittenOnInvoiceTest
   2 Tests\Unit\Inventory\GoodsReceiptDataTest
   2 Tests\Unit\POS\ReceiptReturnServiceTest
   1 each: Architecture\AuthLifecycleTest, Architecture\ConsoleCommandTenantContextTest, Architecture\ControllerTenantContextTest,
          Architecture\DocumentPerActionBaselineRatchetTest, Architecture\QueueJobTenantContextTest, Admin\CentralModelPinningTest,
          Catalog\AttributeValueRepositoryTest, Catalog\CatalogTenantIsolationTest, CountryDefaults\FrozenSeederProvisioningIsolationTest,
          Document\CreditNoteAllocationTest, Document\DeliveryNoteBillingMarkerMigrationTest, Fiscal\ZReportServerAuthoringChokepointTest,
          Identity\UserManagement\CreateUserTest, Import\ImportPreviewTest, Import\ImportTypesTest, Import\ProductIdentityResolutionTest,
          Import\RoundTrip\CompositeItemsRoundTripTest, Inventory\ExitMovementPrecisionTest, Inventory\GoodsReceiptLedgerSchemaTest,
          Inventory\PosMovementCostSnapshotTest, Inventory\ZoneScopedCountingTest, Partner\PartnerReferenceSchemaSweepTest,
          Product\DiscountPolicySubjectProviderTest, Product\ProductMarginIntentEndToEndTest, Product\ProductPricingIntentServiceTest,
          Seeders\DemoPharmacyBatchSeedingTest, Seeders\DemoPharmacySeederExpensesTest, Seeders\DemoPharmacySeederTest,
          Taxation\FranceTaxConfigurationSeederTest, Tenant\TenantCreationTest, Treasury\OutboundInstrumentEndpointsTest,
          Treasury\PaymentTest, Treasury\ReconcilePortfolioCheckTest, Treasury\StatementActionHandlerTest,
          Workshop\WorkOrder\DocumentGenerationAdapterStripsSubToleranceDiscountTest, Workshop\WorkOrder\DocumentLineWorkOrderLineIdRetentionTest,
          Workshop\WorkOrder\InvoiceZeroLineWorkOrderFailsTest, Unit\CountryDefaults\FrozenSeederDocblockTest,
          Unit\CountryDefaults\ProvisioningRequiredPurposesRegistrationRatchetTest, Unit\CountryDefaults\ProvisioningRequiredPurposesV1ConformanceTest,
          Unit\Fiscal\FiscalEventPayloadRegistryTest, Unit\Fiscal\SaleReceiptV3KeySetTest, Unit\Fiscal\StrictCanonicalParserTest,
          Unit\Import\ProductPriceResolverTest, Unit\Product\ProductServiceUpsertTest  (39 classes × 1)
```

**Sanity — per-directory `Tests:` summary, B vs baseline (rows shown only where Tests/Errors/Failures differ; every other one of the 78 directory groups is byte-identical between runs):**

| Directory | B: Tests/Assertions/Errors/Failures | baseline: Tests/Assertions/Errors/Failures |
|---|---|---|
| tests/Unit | 3231 / 35096 / 40 / 11 | 3222 / 35054 / 51 / 9 |
| tests/Architecture | 220 / 881 / 0 / 5 | 220 / 874 / 0 / 12 |
| tests/Feature/Catalog | 238 / 674 / 0 / 5 | 233 / 653 / 0 / 5 |
| tests/Feature/Compliance | 176 / 711 / 0 / 0 | 161 / 638 / 0 / 0 |
| tests/Feature/Console | 178 / 572 / 0 / 3 | 178 / 572 / 0 / 4 |
| tests/Feature/Contact | 29 / 138 / 0 / 0 | 27 / 130 / 0 / 0 |
| tests/Feature/Document | 989 / 4337 / 3 / 1 | 951 / 4191 / 3 / 1 |
| tests/Feature/Fiscal | 942 / 6554 / 0 / 3 | 942 / 6519 / 2 / 3 |
| tests/Feature/Inventory | 1062 / 4229 / 16 / 3 | 1046 / 4163 / 16 / 3 |
| tests/Feature/Treasury | 1355 / 5531 / 31 / 5 | 1341 / 5468 / 31 / 4 |
| **Total (all 78 groups)** | **14708 / 86728 / 124 / 97** | **14609 / 86228 / 137 / 102** |

No directory is missing from either run and neither run looks truncated (every group present in one is present in the other; totals move only by the expected new-test/fix/regression deltas above — e.g. Contact +2 tests, Catalog +5 tests, Document +38 tests, Inventory +16 tests, Treasury +14 tests, all net-new coverage added on local dev between baseline and B).

### B.2 Backend Tests — PG-only invariants — job B `101323833199` / baseline `101330624615`

**CI-shape caveat (affects both runs equally):** the "Run pgsql-only invariant tests" step runs three sequential `php artisan test -c phpunit-pgsql.xml` invocations in one `run:` block (GitHub Actions' default `bash -e`). The first invocation (huge `--filter=...` regex, ~200 classes) fails in both B and baseline, so the script aborts before invocation 2 (`CompanyPaymentRepositoryProvisioningTest`, `BackfillCompanyPaymentRepositoriesMigrationTest`) and invocation 3 (`DeliveryNoteConsolidationConcurrencyTest`, `DeliveryNoteBillingProjectionTest`, `DeliveryNoteBillingClaimServiceTest`, `DeliveryNoteBillingMarkerMigrationTest`) ever run — confirmed by only one Pest summary line appearing in either log. This is a pre-existing CI gap (identical in B and baseline), not a B regression, but it means those 6 classes' PG contract is currently unproven on **every** whole-suite run, not just this one.

**Totals**: B `Tests: 58 failed, 4 skipped, 1987 passed (9633 assertions)` · baseline `Tests: 56 failed, 4 skipped, 1947 passed (9420 assertions)`.

**REGRESSIONS (3)** — fail in B, absent in baseline:

- `Tests\Feature\Fiscal\TreasuryAccountChargeBridgeTest > treasury bridge creates ar journal entry from account charge event` — `Failed asserting that two strings are identical. -'e7875406-633a-40bd-bb87-49b0ded0efed' +'01c45ff8-b2be-4749-a28e-eeeed539d1f6'` (`TreasuryAccountChargeBridgeTest.php:83`, a tenant_id mismatch)
- `Tests\Feature\Fiscal\TreasuryAccountChargeBridgeTest > bridge resolves pending customer alias before ar creation` — `Failed asserting that actual size 0 matches expected size 1.` (`TreasuryAccountChargeBridgeTest.php:530`)
- `Tests\Feature\Uom\UnitsInvariantTest > visibility census failure logs the exception and tenant context` — `Method error(<Any Arguments>) from Mockery_2_Illuminate_Log_LogManager should be called at least 1 times but called 0 times.` (`UnitsInvariantTest.php:145`)

**Attribution check**: neither test file nor the underlying production files (`app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php`, the Uom census migration/command) has ANY commit between baseline (`4d5b8812e`) and B (`189fe7d8a`) (`git log 4d5b8812e..189fe7d8a -- <file>` = empty for all three). Neither class fails under the SQLite-driven classic run (§B.1) in either B or baseline. **These read as environment/order flakes, not code regressions** — see verdict.

**FIXED (1)**: `Tests\Feature\Fiscal\ChokepointCompletenessTest > every finalize callsite is reconciled with receiver type`.

**SHARED (55 methods / 18 classes)**:

```
  8 Tests\Feature\Tenant\FreshTenantCensusInvariantsTest
  6 Tests\Feature\Fiscal\TaskPhase3AccountChargeFullFlowTest
  6 Tests\Feature\POS\PosReceiptsCashRoundingCheckTest
  6 Tests\Feature\Uom\UnitsInvariantTest
  5 Tests\Feature\Fiscal\TreasuryReceiptBridgeTest
  4 Tests\Feature\Fiscal\TreasuryAccountChargeBridgeTest
  4 Tests\Feature\POS\Migrations\PosTerminalsIdentityLifecycleConstraintsTest
  3 Tests\Feature\Inventory\StockThresholdTest
  2 Tests\Feature\Fiscal\RefundCompensationControllerTest
  2 Tests\Feature\Import\UnitResolutionTest
  2 Tests\Feature\POS\ConfigureCashRoundingCommandTest
  1 each: Accounting\Reports\UpcomingPaymentsTest, Fiscal\PosCoreReceiptProjectionRefundDispositionStockTest,
          Import\ProductIdentityResolutionTest, Import\RoundTrip\CompositeItemsRoundTripTest, Import\UnitsNotSeededRefusalTest,
          POS\Migrations\BackfillLocationPosEnabledB3MigrationTest, Tenant\DayOneCensusCommandTest  (7 classes × 1)
```

### B.3 Treasury Spine — PG-only invariants — job B `101323833175` / baseline `101330624774`

**Same CI-shape caveat**: three separate named steps (`Treasury Feature suite`, `Accounting Feature suite`, `Treasury Unit suite`), none with `continue-on-error` or `if: always()`. Step 1 (`./vendor/bin/phpunit tests/Feature/Treasury`) fails in both runs, so GitHub Actions skips steps 2 and 3 — `tests/Feature/Accounting` and `tests/Unit/Treasury` never execute under this PG-only driver on **either** run. Pre-existing gap, identical in B and baseline.

**Totals**: B `Tests: 1355, Assertions: 5597, Errors: 33, Failures: 99` · baseline `Tests: 1341, Assertions: 5533, Errors: 33, Failures: 97`.

**REGRESSIONS (2)** — fail in B, absent in baseline (file did not exist in baseline):

- `Tests\Feature\Treasury\PaymentRefundRefusalTest::test_full_refund_of_a_supplier_payment_refuses_and_writes_nothing`
- `Tests\Feature\Treasury\PaymentRefundRefusalTest::test_partial_refund_of_a_supplier_payment_refuses_and_writes_nothing`

Both: `Failed asserting that table [repository_movements] matches expected entries count of 0. Entries found: 1.` (`PaymentRefundRefusalTest.php:334`, called from lines 154/167). `PaymentRefundRefusalTest.php` is a brand-new file (`git diff --name-status 4d5b8812e..189fe7d8a` shows `A`), added by F-W2-13 commits `c8c4aa55f` (2026-09-04, "refuse supplier payments in the customer refund lane") and `e6f576be1` (2026-09-05, "narrow the customer-refund refusal ... fix round 1"). The same two tests **pass** under the SQLite-driven classic run (§B.1, `tests/Feature/Treasury` group) in B — this is a PG-only defect in brand-new local-dev code, not a flake: the refusal guard the new tests assert on is not (yet) preventing a `repository_movements` write under the PostgreSQL driver. **This is a real, attributable regression** — see verdict.

**FIXED (0)**.

**SHARED (126 methods / 41 classes)**:

```
  11 Tests\Feature\Treasury\ShiftCashVarianceAdjustmentTest
  10 Tests\Feature\Treasury\OutboundInstrumentServiceTest
   8 Tests\Feature\Treasury\OutboundCancelReopenTest
   6 Tests\Feature\Treasury\DeferredSupplierPaymentTest
   6 Tests\Feature\Treasury\DeferredTenderPaymentTest
   5 Tests\Feature\Treasury\BackfillBanksCommandTest
   5 Tests\Feature\Treasury\DeferredTenderGuardsTest
   5 Tests\Feature\Treasury\OpeningItemPaymentDirectionTest
   5 Tests\Feature\Treasury\PosBridgeInstrumentTest
   5 Tests\Feature\Treasury\StatementImportFlowTest
   4 Tests\Feature\Treasury\RepositoryAdjustmentServiceTest
   3 Tests\Feature\Treasury\InstrumentLifecycleReceiveTest
   3 Tests\Feature\Treasury\InstrumentRemittanceServiceTest
   3 Tests\Feature\Treasury\PaymentReversalDocumentTest
   3 Tests\Feature\Treasury\PosSiblingBridgesMaturityTest
   3 Tests\Feature\Treasury\StatementCreationActionTest
   3 Tests\Feature\Treasury\TrainingAccountPaymentContainmentTest
   2 each: AcquirerFeeServiceTest, InstrumentClearTest, PosBridgeInstrumentRefundTest, PosReceiptVatGlSplitTest,
          RepositoryAdjustmentTest, RepositoryTransferServiceTest, ShiftCashVarianceQueueRetryTest, StatementMatchingHttpTest,
          StatementMatchingServiceTest, TreasuryMovementServiceTransferTest, TreasuryOrphanCensusCommandTest  (11 classes × 2)
   1 each: AdvanceReversalReportingAndApiTest, InstrumentEventsImmutabilityTest, MultiPaymentSpineTest,
          OutboundInstrumentConcurrencyTest, OutboundInstrumentEndpointsTest, PaymentAllocationDocumentStateTest,
          PaymentInstrumentPortfolioColumnsTest, PaymentReversalRefusalTest, PaymentTest, ReconcilePortfolioCheckTest,
          RepositoryTransferEndpointTest, ReversalIdempotencyIndexTest, ShiftCashVarianceBranchDrawerTest,
          ShiftCashVarianceOfflineDevicePayloadTest, ShiftCashVarianceTriggerPathsTest, StatementActionHandlerTest  (16 classes × 1)
```

Note `ReconcileTreasuryTest` (the §B.1 phpunit-job regression) does **not** appear in this PG-driven list at all — `test_one_millime_scale_mismatch_is_tolerated_and_does_not_false_freeze` passes here in both B and baseline, i.e. under PostgreSQL it is green on both runs; it only flips red under SQLite in B. Further evidence for the flake reading in the verdict.

### B.4 Frontend Tests (Vitest) — job B `101323833112` / baseline `101330624875`

**Totals**: B `Test Files 3 failed | 740 passed (743)`, `Tests 3 failed | 4971 passed | 3 todo (4977)` · baseline `Test Files 3 failed | 721 passed (724)`, `Tests 3 failed | 4781 passed | 3 todo (4787)`.

**REGRESSIONS (0). FIXED (0). SHARED (3/3 — identical failing set in both runs, byte-identical test names):**

```
src/components/__tests__/SharedSingletons.tenantScope.test.tsx > shared singleton tenant scope > scopes modal invalidations (.001-.003)
src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx > ReviewIngestionPage > posts only PartnerFormData keys when creating a supplier from the review page (no extraction-field leakage)
src/features/support-access/__tests__/SupportWindowForm.test.tsx > SupportWindowForm > matches backend length and absolute configured-window limits
```

No coverage gap: file/test counts grow by exactly the +19 test files / +190 tests added on local dev, nothing shrinks. The `i18n completeness — FAIL CLOSED: ...` lines visible mid-log (both runs) are **not** failures — they are captured stdout from the passing self-test `tools/__tests__/audit-i18n-completeness.test.mjs` (46 tests, ✓), which exercises the audit script's own fail-closed code paths against synthetic fixture hashes (`deadbeef…`, `0000…0`). They are unrelated to the real i18n gate below.

### B.5 Frontend Lint (ESLint) — job B `101323833105` / baseline `101330624855`

**B's only red is the i18n completeness MIRROR DRIFT path — confirmed, nothing else fails:**

```
i18n completeness — FAIL CLOSED: MIRROR DRIFT.
  I18N_BASELINE_PROTECTED_BLOB = 26a9ae1688d80e0f450215326b19ccd1701c9a8f
  progress-YAML mirror  = fd6dbe3952cc3daaec15dc432e6b99e007f50dd6
  The YAML mirror is a paper trail, not the authority; a divergence means one of the two
  was changed without the other. Re-pin both together (owner, at promotion).
 ELIFECYCLE  Command failed with exit code 1.
```

Preceding gates in the same job, all clean: `pnpm audit:keys` → `Gate C ... : 0` / `0 acknowledged, 0 new, 0 stale`; `pnpm audit:design-system` → `802 acknowledged, 0 new, 0 stale` (ratchet not growing); `pnpm audit:quantity` → `0 total (0 baselined, 0 new, 0 stale)`. No ESLint rule violations, no TypeScript errors reach this job at all — the pipeline aborts at `audit:i18n` before any linter runs. So the red is **exactly** the MIRROR-DRIFT/cannot-re-pin path described in the task, and nothing else.

**Baseline's red is a DIFFERENT gate — Gate C, not i18n:**

```
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 1
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries

New unscoped TanStack query key violations:
  src/features/uom/hooks/useUnits.ts:53:9 invalidateQueries({ queryKey: tenantScopedKey([...]) }) is a no-op filter: ...
```

Local dev fixed this baseline red via commit `4b5b58789` ("chore(web): clear lint-gate debt — e2e-local parser project, uom invalidation key, missing ar/fr i18n keys", 2026-09-03), landing before B's tip — confirmed clean (`Gate C ... : 0`) in B's log. Baseline never reaches the i18n gate at all (it fails earlier, at Gate C) — so the i18n MIRROR DRIFT in B is a **newly-exposed** red (the gate itself is unaffected by local dev's code; the drift is a stale CI variable / tag pin, not a code defect — see verdict).

## §C Verdict

**No regression in this promotion candidate is attributable to a code defect introduced by local dev's own new work, with one exception: `PaymentRefundRefusalTest` (2 tests, `tests/Feature/Treasury/PaymentRefundRefusalTest.php`, PG-only invariants job) is a real, attributable regression** — the file is brand-new (added between baseline and B by commits `c8c4aa55f` "fix(treasury): refuse supplier payments in the customer refund lane (F-W2-13, P0)" and `e6f576be1` "fix(treasury): narrow the customer-refund refusal ... fix round 1"), and its own two new tests fail under the PostgreSQL driver (`repository_movements` gets a row the refusal guard is supposed to prevent) while passing under SQLite. This blocks promotion of the Treasury Spine / PG-only invariants gate until F-W2-13's refusal guard is fixed to also hold under the PostgreSQL write path, or the fix-round author confirms and re-verifies.

Every other apparent regression across the five red jobs is one of:
1. **A single genuine flake with no code behind it** — `Tests\Feature\Treasury\ReconcileTreasuryTest::test_one_millime_scale_mismatch_is_tolerated_and_does_not_false_freeze` (Backend Tests PHPUnit job) fails only under SQLite in B; neither the test file nor `ReconcileTreasuryCommand.php` has any commit between baseline and B, and the same test passes under PostgreSQL in both runs (§B.3 note). Absent from run A's 228-failure list too.
2. **Two PG-only flakes riding the same pattern** — `TreasuryAccountChargeBridgeTest` (2 methods) and `UnitsInvariantTest` (1 method) fail only in B's PG-only invariants job; neither the test files nor their production code (`TreasuryAccountChargeBridge.php`, Uom census command) changed between baseline and B, and neither class fails under SQLite in either run. **Flake risk flagged per the task's "~3 test flakes" hint — that hint matches exactly**: 1 (ReconcileTreasuryTest) + 2 (TreasuryAccountChargeBridgeTest ×2 methods, same class) = 3 flake-shaped regressions with zero code behind them, all absent from run A's failure list, all environment/order-sensitive (a stray tenant_id / an uncalled Mockery log spy / an unfrozen repository) rather than deterministic assertion breaks.
3. **A bookkeeping drift, not a code regression** — Frontend Lint's i18n MIRROR DRIFT (`I18N_BASELINE_PROTECTED_BLOB` var `26a9ae16…` vs YAML mirror `fd6dbe39…`) is exactly the re-pin gap the task anticipated: yesterday's re-pin (`89b508e1f`) updated the working repo but the CI variable and the `ci-pin/enforcement-p2-r1` tag were not moved together. No FE code, no ESLint rule, no ratchet (Gate C: 0 new; design-system: 0 new; quantity: 0 new) contributes to this red. Needs an owner action (re-pin both together), not a code fix.
4. **A CI-shape gap, present identically in both runs, not a regression at all** — both PG-only jobs execute only their first script/step and skip the rest (Backend Tests PG-only's invocations 2–3 covering `CompanyPaymentRepositoryProvisioningTest`, `BackfillCompanyPaymentRepositoriesMigrationTest`, the 4 `DeliveryNote*` classes; Treasury Spine's `Accounting Feature suite` and `Treasury Unit suite` steps) because the first failing call aborts the job under GitHub Actions' default `bash -e`. This affects baseline and B equally, so it gates nothing here, but it means those classes' PostgreSQL contract is unproven on *every* whole-suite run today, independent of this promotion — worth its own follow-up ticket, out of this diff's scope.

Frontend Tests (Vitest) has zero drift either way (3/3 byte-identical failing tests in both runs). Frontend Lint fixed a real baseline red (Gate C at `useUnits.ts:53`, commit `4b5b58789`) while exposing the unrelated i18n pin drift. Backend Tests (PHPUnit) net-fixed 19 pre-existing failures (`FeatureLaneManifestCheckerTest` ×7, `ReceiptReturnServiceTest` ×9, `QuarantineBestEffortParseControllerTest` ×2, `ExportFrontendPermissionsMapCommandTest` ×1) against 1 flake-shaped new red. **Promotion-blocking finding: fix `PaymentRefundRefusalTest`'s PG-only failure (or get an explicit owner waiver) before promoting; the 3 flake-shaped reds warrant a re-run of the PG-only + Treasury Spine + Backend Tests (PHPUnit) jobs alone to confirm they clear on a clean re-run, but should not block promotion on their own given the zero-code-change evidence; the i18n MIRROR DRIFT needs an owner re-pin, not a code change.**
