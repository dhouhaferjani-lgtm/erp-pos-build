<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\TransactionRemiseSplit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * D-1 gate r2 finding 6 — the shared kernel had three PHP consumers (the POS
 * allocator, the server-authored receipt path, and the contract validator's v5
 * split pin) and no direct test of its own. Every one of them is only as
 * trustworthy as this class, and the validator's pin makes a wrong answer here
 * a REFUSAL of an honest receipt rather than a quiet mis-declaration.
 */
final class TransactionRemiseSplitTest extends TestCase
{
    /** The ruling's worked example, group by group. */
    public function test_the_worked_examples_four_groups_split_as_the_ruling_says(): void
    {
        $this->assertSame(['15.625', '2.969'], TransactionRemiseSplit::split('18.594', '19.00', '200.000', '38.000', 3));
        $this->assertSame(['15.625', '2.031'], TransactionRemiseSplit::split('17.656', '13.00', '200.000', '26.000', 3));
        $this->assertSame(['7.812', '0.547'], TransactionRemiseSplit::split('8.359', '7.00', '100.000', '7.000', 3));
        // An exempt group carries the whole share on the net side.
        $this->assertSame(['5.391', '0.000'], TransactionRemiseSplit::split('5.391', '0.00', '69.000', '0.000', 3));
    }

    public function test_a_zero_share_splits_to_canonical_zeroes(): void
    {
        $this->assertSame(['0.000', '0.000'], TransactionRemiseSplit::split('0.000', '19.00', '200.000', '38.000', 3));
    }

    /**
     * The clamps are what make a 100 %-comp land on EXACTLY zero rather than a
     * ±1 ulp residue: the whole group's gross comes out, and each half is
     * capped at its own line sum.
     */
    public function test_a_full_comp_takes_exactly_the_groups_own_line_sums(): void
    {
        [$net, $vat] = TransactionRemiseSplit::split('238.006', '19.00', '200.005', '38.001', 3);

        $this->assertSame('200.005', $net);
        $this->assertSame('38.001', $vat);
        $this->assertSame(0, bccomp(bcadd($net, $vat, 3), '238.006', 3));
    }

    /** At most one clamp can bind, and the pair always sums back to the share. */
    public function test_the_vat_side_clamp_pushes_the_remainder_onto_the_net_side(): void
    {
        // A share larger than the group's VAT can absorb.
        [$net, $vat] = TransactionRemiseSplit::split('8.359', '7.00', '100.000', '0.500', 3);

        $this->assertSame('0.500', $vat);
        $this->assertSame('7.859', $net);
        $this->assertSame(0, bccomp(bcadd($net, $vat, 3), '8.359', 3));
    }

    public function test_scale_2_and_scale_0_currencies_round_half_up(): void
    {
        // EUR: 10.00 / 1.20 = 8.3333 -> 8.33, VAT 1.67.
        $this->assertSame(['8.33', '1.67'], TransactionRemiseSplit::split('10.00', '20.00', '100.00', '20.00', 2));
        // JPY: 100 / 1.10 = 90.909 -> 91, VAT 9.
        $this->assertSame(['91', '9'], TransactionRemiseSplit::split('100', '10.00', '1000', '100', 0));
    }

    public function test_ulp_is_one_at_the_last_representable_digit(): void
    {
        $this->assertSame('1', TransactionRemiseSplit::ulp(0));
        $this->assertSame('0.01', TransactionRemiseSplit::ulp(2));
        $this->assertSame('0.001', TransactionRemiseSplit::ulp(3));
    }

    public function test_round_half_up_goes_away_from_zero_at_the_boundary(): void
    {
        $this->assertSame('0.503', TransactionRemiseSplit::roundHalfUp('0.5025', 3));
        $this->assertSame('1.900', TransactionRemiseSplit::roundHalfUp('1.9000', 3));
    }

    /** A negative input is never a rounding artefact — it is corruption. */
    public function test_a_negative_input_is_refused_rather_than_absorbed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/transaction_remise_split_negative_input/');

        TransactionRemiseSplit::split('-1.000', '19.00', '200.000', '38.000', 3);
    }
}
