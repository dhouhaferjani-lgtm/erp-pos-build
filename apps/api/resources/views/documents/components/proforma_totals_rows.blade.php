{{--
    THE PROFORMA TOTALS BOX — one copy, two call sites.

    C-F0 / SPEC §2.4 (F-95), amended by fix round r1 (gate F-2, SPEC §2.4 r11.2) and
    fix round r2 (gate r2 §3, residual R-8). Extracted here in fix round r3 by
    conventions gate F-C2 / fiscal gate F-12.

    WHY IT IS A PARTIAL. `components/totals.blade.php` and
    `templates/credit_note.blade.php` had two hand-copies of these four rows. The
    credit note has its own totals block rather than using the shared component, and
    r2's comment there said the copy "must stay in step with the component" — while
    nothing made it, and no test rendered it. That is the drift the census cannot
    see either: `consultsTheFlagInCode()` is per-FILE, and `credit_note.blade.php`
    already consults the flag elsewhere, so the whole file reads as gated. One copy
    removes the question. The only thing the two call sites disagreed about is the
    total row's colour band, which is now `$totalRowStyle`.

    WHAT IT PRINTS, and why each row is allowed on a page that carries no VAT:

      - `Stamp duty` — the stored `stamp_duty_amount`, a TN *droit de timbre* under
        the Code des droits d'enregistrement et de timbre. Art. 18 attaches liability
        to VAT mentioned on an issued invoice and to nothing else; naming a separate
        duty is not that. Duty words only, in all three locales.
      - `Discount` / `Adjustment` — the DERIVED residual `total − (Σ printed gross
        lines + stamp)`, signed. Derived and never assembled from the stored
        `discount_amount`: the two coincide only when every line carries a persisted
        `tax_amount`, and the NULL-`tax_amount` fallback taxes the PRE-discount net,
        so an assembled row leaves a silent remainder on a legacy row. An increase is
        never called a discount.
      - `Estimated total` — the STORED `documents.total`, never recomputed.

    The blade renders and computes nothing: every value arrives decided on
    `ProformaTotals`, built once in `DocumentPdfService::prepareData()`.

    The net subtotal and the tax row are absent by design, and so are the settlement
    rows: printing a net line beside a gross one is a VAT breakdown written as a
    subtraction, and a proforma is not a statement of account.

    THE OUTER `@if($isProforma ?? false)` IS NOT REDUNDANT. Both call sites are
    already inside their own proforma arm, so it never changes what renders today —
    but it is what makes this file gated in its own right rather than by the accident
    of who includes it, and `ProformaTemplateCensusTest` said so the moment the file
    appeared (it emits tax mentions in this very docblock and consulted nothing).
    A third call site that forgets the arm renders nothing here instead of printing
    an estimate on a definitive document.
--}}
@if($isProforma ?? false)
@if(($proformaTotals ?? null) !== null && $proformaTotals->stampDuty !== null)
<tr>
    <td>{{ __('documents.proforma.stamp_duty') }}</td>
    <td>{{ $formatMoney($proformaTotals->stampDuty) }}</td>
</tr>
@endif
@if(($proformaTotals ?? null) !== null && $proformaTotals->discount !== null)
<tr>
    <td>{{ __('documents.proforma.discount') }}</td>
    <td>-{{ $formatMoney($proformaTotals->discount) }}</td>
</tr>
@elseif(($proformaTotals ?? null) !== null && $proformaTotals->surcharge !== null)
<tr>
    <td>{{ __('documents.proforma.adjustment') }}</td>
    <td>{{ $formatMoney($proformaTotals->surcharge) }}</td>
</tr>
@endif
<tr class="total-row"@if($totalRowStyle ?? '') style="{{ $totalRowStyle }}"@endif>
    <td>{{ __('documents.proforma.estimated_total') }}</td>
    <td>{{ $formatMoney($document->total) }}</td>
</tr>
@endif
