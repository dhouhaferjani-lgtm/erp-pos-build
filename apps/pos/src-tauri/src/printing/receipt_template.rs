use serde::{Deserialize, Serialize};

use super::escpos::{Alignment, CutMode, EscPosBuilder, FontSize, QrErrorCorrection, TextEncoding};

/// Per-tender cash count row for Z-report printing.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ZReceiptCashCountRow {
    pub code: String,
    pub name: String,
    pub expected: String,
    pub actual: String,
    pub variance: String,
    pub direction: String,
}

/// Receipt data structure passed from the frontend via JSON.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReceiptData {
    /// Company / store information
    pub company: CompanyInfo,
    /// Receipt metadata
    pub receipt_number: String,
    pub date_time: String,
    pub terminal_name: String,
    pub operator_name: String,
    /// Line items
    pub lines: Vec<ReceiptLine>,
    /// Totals
    pub subtotal: String,
    pub discount_amount: String,
    pub tax_amount: String,
    pub total: String,
    pub currency_symbol: String,
    /// VAT breakdown
    pub vat_breakdown: Vec<VatBreakdownLine>,
    /// Payment methods used
    pub payments: Vec<PaymentLine>,
    pub change_due: String,
    /// Cash-sale tolerance write-off (GL 658). When `has_tolerance` is true,
    /// the formatter prints a "Rounding -X.XX" line using this string verbatim.
    /// Absent on legacy receipts and on receipts without applied tolerance.
    #[serde(default)]
    pub tolerance_writeoff: Option<String>,
    /// Precomputed flag from the TS boundary: true iff `tolerance_writeoff`
    /// represents a positive monetary value. Computing this on the Rust side
    /// would require parsing a monetary string into f64, which is a precision
    /// smell even when the parsed value is only compared to zero.
    #[serde(default)]
    pub has_tolerance: bool,
    /// SIGNED cash-rounding adjustment (`rounded − exact`). Printed VERBATIM in
    /// the totals block — the string already carries its own `-` when negative,
    /// so unlike the tolerance line no sign is forced on.
    ///
    /// It sits between Tax and TOTAL because the stored receipt is deliberately
    /// mixed: `total` is ROUNDED while `subtotal` / `tax_amount` /
    /// `discount_amount` stay EXACT. Without this line the ticket prints
    /// `subtotal − discount + tax != TOTAL` with nothing to explain the gap.
    #[serde(default)]
    pub cash_rounding_adjustment: Option<String>,
    /// Precomputed flag from the TS boundary: true iff `cash_rounding_adjustment`
    /// is a non-zero monetary value (it may legitimately be negative, so this is
    /// a `!= 0` test, not `> 0`). Rust never parses the monetary string.
    #[serde(default)]
    pub has_cash_rounding: Option<bool>,
    /// Fiscal compliance
    pub fiscal_hash: Option<String>,
    pub fiscal_signature: Option<String>,
    /// Optional customer info
    pub customer_name: Option<String>,
    /// Optional notes
    pub notes: Option<String>,
    /// Localized labels (optional — English defaults if absent)
    pub labels: Option<ReceiptLabels>,
    /// Visibility flags — all default to true when absent (backward compatible)
    pub show_vat_breakdown: Option<bool>,
    pub show_fiscal_info: Option<bool>,
    pub show_payment_details: Option<bool>,
    pub show_customer: Option<bool>,
    /// When true, a bold centred DUPLICATA banner is printed after the receipt
    /// metadata block to indicate this is a copy of an already-issued document.
    pub is_reprint: Option<bool>,
    /// Z-report cash-count block (optional — only set when closing a shift with cash counts).
    pub cash_counts: Option<Vec<ZReceiptCashCountRow>>,
    pub manager_name: Option<String>,
    pub variance_reason: Option<String>,
    pub variance_severity: Option<String>,
    pub aggregate_variance: Option<String>,
    /// Signed QR token (`v:kid:receipt_uuid:mac`) for THIS receipt. When present,
    /// a centred QR + human-readable token is rendered at the footer. Absent /
    /// null on offline receipts whose token has not been signed by the server.
    #[serde(default)]
    pub qr_token: Option<String>,
    /// Receipt-kind discriminator. `"refund"` triggers the
    /// REMBOURSEMENT/REFUND header and the original-ticket reference block;
    /// any other value (or absent) is treated as a sale receipt.
    #[serde(default)]
    pub receipt_kind: Option<String>,
    /// On refund receipts: the original sale receipt's number.
    #[serde(default)]
    pub original_receipt_number: Option<String>,
    /// On refund receipts: the original sale receipt's QR token, re-printed
    /// at the top of the refund receipt so the original can still be scanned
    /// for further partial refunds.
    #[serde(default)]
    pub original_receipt_qr_token: Option<String>,
    #[serde(default)]
    pub account_balance_before: Option<String>,
    #[serde(default)]
    pub account_balance_after: Option<String>,
    #[serde(default)]
    pub account_snapshot_stale: bool,
    #[serde(default)]
    pub business_date: Option<String>,
    #[serde(default)]
    pub terminal_id: Option<String>,
    #[serde(default)]
    pub shift_id: Option<String>,
    #[serde(default)]
    pub training_flag: bool,
    #[serde(default)]
    pub customer_account_id: Option<String>,
    #[serde(default)]
    pub customer_phone: Option<String>,
}

/// Localized receipt labels. All fields optional with English defaults.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReceiptLabels {
    pub receipt: Option<String>,
    pub date: Option<String>,
    pub terminal: Option<String>,
    pub operator: Option<String>,
    pub customer: Option<String>,
    pub item: Option<String>,
    pub qty: Option<String>,
    pub amount: Option<String>,
    pub subtotal: Option<String>,
    pub discount: Option<String>,
    pub tax: Option<String>,
    pub total: Option<String>,
    pub payments: Option<String>,
    pub change_due: Option<String>,
    /// Label for the SIGNED cash-rounding line in the totals block.
    pub rounding: Option<String>,
    /// Label for the tolerance write-off line. Distinct from `rounding`: two
    /// identically-labelled money lines on one ticket would be unreadable.
    #[serde(default)]
    pub tolerance: Option<String>,
    pub vat_rate: Option<String>,
    pub taxable: Option<String>,
    pub tax_col: Option<String>,
    pub thank_you: Option<String>,
    pub tax_id: Option<String>,
    /// Label for the establishment VAT-number header line.
    #[serde(default)]
    pub vat_number: Option<String>,
    pub tel: Option<String>,
    pub cash_count_section_title: Option<String>,
    pub cash_count_total_variance: Option<String>,
    pub cash_count_approved_by: Option<String>,
    pub cash_count_reason: Option<String>,
    pub cash_count_col_tender: Option<String>,
    pub cash_count_col_expected: Option<String>,
    pub cash_count_col_actual: Option<String>,
    pub cash_count_col_variance: Option<String>,
    /// Refund-receipt header label (e.g. "REMBOURSEMENT" / "REFUND" / "AVOIR").
    pub refund_header: Option<String>,
    /// "Original ticket:" label printed on refund receipts.
    pub original_ticket: Option<String>,
    /// "Scan original ticket:" label above the original-receipt QR re-print.
    pub original_qr_label: Option<String>,
    /// Caption printed immediately above the refund-lookup QR on every ticket
    /// that carries one (e.g. "Scanner pour retour / échange"). Optional so an
    /// older device build that does not send it still deserialises.
    #[serde(default)]
    pub qr_scan_label: Option<String>,
    pub account_payment_header: Option<String>,
    pub balance_before: Option<String>,
    pub balance_after: Option<String>,
    pub stale_balance: Option<String>,
    pub business_date: Option<String>,
    pub terminal_id: Option<String>,
    pub shift_id: Option<String>,
    pub training: Option<String>,
    pub customer_account: Option<String>,
    pub customer_phone: Option<String>,
}

impl ReceiptData {
    /// Get a localized label with English fallback.
    fn label(&self, getter: impl Fn(&ReceiptLabels) -> &Option<String>, default: &str) -> String {
        self.labels
            .as_ref()
            .and_then(|l| getter(l).as_ref())
            .map(|s| s.clone())
            .unwrap_or_else(|| default.to_string())
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CompanyInfo {
    pub name: String,
    pub address_line1: String,
    pub address_line2: Option<String>,
    pub city: String,
    pub postal_code: String,
    pub country: String,
    pub tax_id: String,
    pub phone: Option<String>,
    /// Establishment VAT number (spec 2026-06-11 §4.6) — printed under the
    /// tax id. Display-only; absent on pre-§4.6 payloads.
    #[serde(default)]
    pub vat_number: Option<String>,
    /// Pre-formatted legal identifier lines (e.g. "SIRET: 552…") printed
    /// verbatim after the tax id / VAT lines. The TS boundary formats them;
    /// the Rust side never parses identifier structures.
    #[serde(default)]
    pub legal_identifier_lines: Option<Vec<String>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReceiptLine {
    pub name: String,
    pub quantity: String,
    pub unit_price: String,
    pub line_total: String,
    pub modifiers: Option<Vec<ModifierLine>>,
    pub discount: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ModifierLine {
    pub name: String,
    pub price: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VatBreakdownLine {
    pub rate: String,
    pub taxable: String,
    pub tax: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PaymentLine {
    pub method: String,
    pub amount: String,
}

/// Print settings passed from the frontend.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PrintSettings {
    pub columns: u8,
    pub cut_mode: String,
    pub encoding: String,
    pub footer_text: String,
    pub copies: u32,
}

/// Cash drawer kick settings passed from the frontend.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DrawerKickSettings {
    pub pin: u8,
    pub pulse_on: u8,
    pub pulse_off: u8,
    pub beep: bool,
}

impl PrintSettings {
    /// Resolve the stored `encoding` string to the ONE type that owns both the
    /// declared code page (`ESC t n`) and the transcoding (DEV-QA-094).
    ///
    /// Anything unrecognised falls back to CP1252 — the device default
    /// (`printerStore.ts`) and the only one of the three that can print the
    /// French accents this market needs.
    pub(crate) fn text_encoding(&self) -> TextEncoding {
        match self.encoding.as_str() {
            "cp437" => TextEncoding::Cp437,
            "cp858" => TextEncoding::Cp858,
            _ => TextEncoding::Cp1252,
        }
    }

    pub(crate) fn cut_mode_enum(&self) -> Option<CutMode> {
        match self.cut_mode.as_str() {
            "full" => Some(CutMode::Full),
            "partial" => Some(CutMode::Partial),
            _ => None, // "none"
        }
    }

    /// Returns `true` when cutting is enabled (i.e. `cut_mode` is not `"none"`).
    pub(crate) fn is_cut_enabled(&self) -> bool {
        self.cut_mode != "none"
    }
}

/// Format receipt data into ESC/POS commands ready to send to a printer.
pub fn format_receipt(data: &ReceiptData) -> Vec<u8> {
    format_receipt_with_settings(data, None)
}

/// Format receipt with optional print settings.
pub fn format_receipt_with_settings(
    data: &ReceiptData,
    settings: Option<&PrintSettings>,
) -> Vec<u8> {
    let columns = settings.map_or(42, |s| s.columns);
    let encoding = settings.map_or(TextEncoding::Cp1252, |s| s.text_encoding());
    // The builder emits `ESC @` + `ESC t <code page>` and transcodes to that
    // same page — the two can no longer disagree (DEV-QA-094).
    let mut b = EscPosBuilder::with_columns_and_encoding(columns, encoding);

    // ── Company Header ──
    b.align(Alignment::Center);
    b.font_size(FontSize::DoubleWidthHeight);
    b.bold(true);
    b.text_line(&data.company.name);
    b.bold(false);
    b.font_size(FontSize::Normal);

    b.text_line(&data.company.address_line1);
    if let Some(ref addr2) = data.company.address_line2 {
        if let Some(line) = non_empty_trimmed(addr2) {
            b.text_line(line);
        }
    }

    let postal_city = [data.company.postal_code.trim(), data.company.city.trim()]
        .into_iter()
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join(" ");
    if !postal_city.is_empty() {
        b.text_line(&postal_city);
    }

    if let Some(ref phone) = data.company.phone {
        if let Some(value) = non_empty_trimmed(phone) {
            b.text_line(&format!("{} {}", data.label(|l| &l.tel, "Tel:"), value));
        }
    }
    if let Some(tax_id) = non_empty_trimmed(&data.company.tax_id) {
        b.text_line(&format!(
            "{} {}",
            data.label(|l| &l.tax_id, "Tax ID:"),
            tax_id
        ));
    }
    // Establishment identity extras (spec 2026-06-11 §4.6) — only sent when
    // the terminal location's fiscal identity is complete. Display-only.
    if let Some(ref vat_number) = data.company.vat_number {
        if let Some(value) = non_empty_trimmed(vat_number) {
            b.text_line(&format!(
                "{} {}",
                data.label(|l| &l.vat_number, "VAT No:"),
                value
            ));
        }
    }
    if let Some(ref identifier_lines) = data.company.legal_identifier_lines {
        for line in identifier_lines {
            if let Some(value) = non_empty_trimmed(line) {
                b.text_line(value);
            }
        }
    }

    b.align(Alignment::Left);
    b.separator('-');

    // ── Receipt Meta ──
    b.two_column(
        &data.label(|l| &l.receipt, "Receipt:"),
        &data.receipt_number,
    );
    b.two_column(&data.label(|l| &l.date, "Date:"), &data.date_time);
    b.two_column(
        &data.label(|l| &l.terminal, "Terminal:"),
        &data.terminal_name,
    );
    b.two_column(
        &data.label(|l| &l.operator, "Operator:"),
        &data.operator_name,
    );
    if let Some(ref business_date) = data.business_date {
        if let Some(value) = non_empty_trimmed(business_date) {
            b.text_line(&format!(
                "{} {}",
                data.label(|l| &l.business_date, "Business date:"),
                value
            ));
        }
    }
    if let Some(ref terminal_id) = data.terminal_id {
        if let Some(value) = non_empty_trimmed(terminal_id) {
            b.text_line(&format!(
                "{} {}",
                data.label(|l| &l.terminal_id, "Terminal ID:"),
                value
            ));
        }
    }
    if let Some(ref shift_id) = data.shift_id {
        if let Some(value) = non_empty_trimmed(shift_id) {
            b.text_line(&format!(
                "{} {}",
                data.label(|l| &l.shift_id, "Shift ID:"),
                value
            ));
        }
    }

    if data.show_customer.unwrap_or(true) {
        if let Some(ref customer) = data.customer_name {
            b.two_column(&data.label(|l| &l.customer, "Customer:"), customer);
        }
        if let Some(ref account_id) = data.customer_account_id {
            if let Some(value) = non_empty_trimmed(account_id) {
                b.text_line(&format!(
                    "{} {}",
                    data.label(|l| &l.customer_account, "Account:"),
                    value
                ));
            }
        }
        if let Some(ref phone) = data.customer_phone {
            if let Some(value) = non_empty_trimmed(phone) {
                b.text_line(&format!(
                    "{} {}",
                    data.label(|l| &l.customer_phone, "Customer phone:"),
                    value
                ));
            }
        }
    }

    if data.training_flag {
        b.align(Alignment::Center);
        b.font_size(FontSize::DoubleWidthHeight);
        b.bold(true);
        b.text_line(&data.label(|l| &l.training, "TRAINING"));
        b.bold(false);
        b.font_size(FontSize::Normal);
        b.align(Alignment::Left);
    }

    // ── DUPLICATA banner (reprint indicator) ──
    if data.is_reprint == Some(true) {
        b.align(Alignment::Center);
        b.font_size(FontSize::DoubleWidthHeight);
        b.bold(true);
        b.text_line("DUPLICATA");
        b.bold(false);
        b.font_size(FontSize::Normal);
        b.align(Alignment::Left);
    }

    // ── REMBOURSEMENT / REFUND banner (refund receipts only) ──
    // Gated on receipt_kind == "refund". Sale receipts (default) skip this block.
    let is_refund = data
        .receipt_kind
        .as_deref()
        .map(|k| k.eq_ignore_ascii_case("refund"))
        .unwrap_or(false);
    let is_account_payment = data
        .receipt_kind
        .as_deref()
        .map(|k| k.eq_ignore_ascii_case("account_payment"))
        .unwrap_or(false);

    if is_refund {
        b.align(Alignment::Center);
        b.font_size(FontSize::DoubleWidthHeight);
        b.bold(true);
        b.text_line(&data.label(|l| &l.refund_header, "REFUND"));
        b.bold(false);
        b.font_size(FontSize::Normal);
        b.align(Alignment::Left);

        // Original ticket reference + original-ticket QR re-print
        if let Some(ref orig_num) = data.original_receipt_number {
            b.two_column(
                &data.label(|l| &l.original_ticket, "Original ticket:"),
                orig_num,
            );
        }

        if let Some(ref orig_token) = data.original_receipt_qr_token {
            b.align(Alignment::Center);
            b.empty_line();
            b.select_font(true); // Font B (smaller) for the label
            b.text_line(&data.label(|l| &l.original_qr_label, "Scan original ticket:"));
            b.select_font(false);
            b.qr_code(orig_token, 4, QrErrorCorrection::M);
            b.empty_line();
            b.align(Alignment::Left);
        }
    }

    b.separator('=');

    if is_account_payment {
        b.align(Alignment::Center);
        b.font_size(FontSize::DoubleHeight);
        b.bold(true);
        b.text_line(&data.label(|l| &l.account_payment_header, "ACCOUNT PAYMENT RECEIPT"));
        b.bold(false);
        b.font_size(FontSize::Normal);
        b.align(Alignment::Left);
        b.separator('-');

        if let Some(ref before) = data.account_balance_before {
            b.two_column(
                &data.label(|l| &l.balance_before, "Balance before:"),
                &format!("{}{}", data.currency_symbol, before),
            );
        }
        b.two_column(
            &data.label(|l| &l.amount, "Amount"),
            &format!("{}{}", data.currency_symbol, data.total),
        );
        if let Some(ref after) = data.account_balance_after {
            b.two_column(
                &data.label(|l| &l.balance_after, "Balance after:"),
                &format!("{}{}", data.currency_symbol, after),
            );
        }
        if data.account_snapshot_stale {
            b.text_line(&data.label(|l| &l.stale_balance, "Balance snapshot stale"));
        }

        if data.show_payment_details.unwrap_or(true) {
            b.separator('-');
            b.bold(true);
            b.text_line(&data.label(|l| &l.payments, "Payments:"));
            b.bold(false);
            for payment in &data.payments {
                b.two_column(
                    &format!("  {}", payment.method),
                    &format!("{}{}", data.currency_symbol, payment.amount),
                );
            }
        }
    } else {
        // ── Line Items ──
        // Header
        b.bold(true);
        b.three_column(
            &data.label(|l| &l.item, "Item"),
            &data.label(|l| &l.qty, "Qty"),
            &data.label(|l| &l.amount, "Amount"),
        );
        b.bold(false);
        b.separator('-');

        for line in &data.lines {
            // Product name on its own line if long
            let qty_price = format!("{} x {}", line.quantity, line.unit_price);
            let total_str = format!("{}{}", data.currency_symbol, line.line_total);

            if line.name.len() > 20 {
                // Long name: print name on first line, details on second
                b.text_line(&line.name);
                b.two_column(&format!("  {}", qty_price), &total_str);
            } else {
                b.text_line(&line.name);
                b.two_column(&format!("  {}", qty_price), &total_str);
            }

            // Modifiers
            if let Some(ref modifiers) = line.modifiers {
                for modifier in modifiers {
                    let mod_price = if modifier.price == "0.00" || modifier.price == "0" {
                        String::new()
                    } else {
                        format!("+{}{}", data.currency_symbol, modifier.price)
                    };
                    b.two_column(&format!("  + {}", modifier.name), &mod_price);
                }
            }

            // Line discount
            if let Some(ref discount) = line.discount {
                b.two_column(
                    &format!("  {}", data.label(|l| &l.discount, "Discount")),
                    &format!("-{}{}", data.currency_symbol, discount),
                );
            }
        }

        b.separator('=');

        // ── Totals ──
        b.two_column(
            &data.label(|l| &l.subtotal, "Subtotal:"),
            &format!("{}{}", data.currency_symbol, data.subtotal),
        );

        if data.discount_amount != "0.00" && data.discount_amount != "0" {
            b.two_column(
                &format!(
                    "{}:",
                    data.label(|l| &l.discount, "Discount")
                        .trim_end_matches(':')
                ),
                &format!("-{}{}", data.currency_symbol, data.discount_amount),
            );
        }

        // ── VAT ventilation (D-1, owner ruling 2026-08-25) ──
        // Commercial layout: lines → Remise → per-rate base/VAT → TOTAL.
        //
        // The table sits ABOVE the TOTAL and REPLACES the standalone "Tax:"
        // line, because since D-1 the sealed base and VAT are POST-remise.
        // Printing `Subtotal − Remise + Tax` down the ticket no longer lands on
        // TOTAL (the remise would be counted twice: once as its own line and
        // again inside the reduced tax), so the aggregate Tax line is shown
        // ONLY when there is no ventilation table to carry the same
        // information — legacy receipts, and tickets whose VAT block the
        // merchant has switched off.
        //
        // With the table present the ticket's arithmetic is
        // `Subtotal (TTC) − Remise (+ rounding) == TOTAL`, and the ventilation
        // shows the taxable base the customer was actually charged on.
        let shows_vat_breakdown =
            data.show_vat_breakdown.unwrap_or(true) && !data.vat_breakdown.is_empty();

        if shows_vat_breakdown {
            b.separator('-');
            b.bold(true);
            b.three_column(
                &data.label(|l| &l.vat_rate, "VAT %"),
                &data.label(|l| &l.taxable, "Taxable"),
                &data.label(|l| &l.tax_col, "Tax"),
            );
            b.bold(false);

            for vat in &data.vat_breakdown {
                b.three_column(
                    &format!("{}%", vat.rate),
                    &format!("{}{}", data.currency_symbol, vat.taxable),
                    &format!("{}{}", data.currency_symbol, vat.tax),
                );
            }
            b.separator('-');
        } else {
            b.two_column(
                &data.label(|l| &l.tax, "Tax:"),
                &format!("{}{}", data.currency_symbol, data.tax_amount),
            );
        }

        // ── Cash rounding (SIGNED) ──
        // Deliberately inside the totals block, between Tax and TOTAL, NOT in
        // the payments block: the stored receipt is mixed (rounded `total`,
        // exact `subtotal`/`tax_amount`/`discount_amount`), so this is the one
        // line that lets a customer's own arithmetic land on TOTAL. Putting it
        // under Payments would also hide it whenever `show_payment_details` is
        // off, leaving the mismatch printed with no explanation.
        //
        // Printed verbatim: the adjustment can be positive or negative and the
        // TS boundary already formatted it at currency scale.
        if data.has_cash_rounding.unwrap_or(false) {
            if let Some(ref adjustment) = data.cash_rounding_adjustment {
                b.two_column(
                    &data.label(|l| &l.rounding, "Rounding"),
                    &format!("{}{}", data.currency_symbol, adjustment),
                );
            }
        }

        b.bold(true);
        b.font_size(FontSize::DoubleHeight);
        b.two_column(
            &data.label(|l| &l.total, "TOTAL:"),
            &format!("{}{}", data.currency_symbol, data.total),
        );
        b.font_size(FontSize::Normal);
        b.bold(false);

        // ── Payments ──
        if data.show_payment_details.unwrap_or(true) {
            b.separator('-');
            b.bold(true);
            b.text_line(&data.label(|l| &l.payments, "Payments:"));
            b.bold(false);

            for payment in &data.payments {
                b.two_column(
                    &format!("  {}", payment.method),
                    &format!("{}{}", data.currency_symbol, payment.amount),
                );
            }

            if data.change_due != "0.00" && data.change_due != "0" {
                b.bold(true);
                b.two_column(
                    &data.label(|l| &l.change_due, "Change Due:"),
                    &format!("{}{}", data.currency_symbol, data.change_due),
                );
                b.bold(false);
            }

            // ── Tolerance write-off ──
            // Belongs with the payment lines: it describes the gap between what
            // was DUE and what was TENDERED, not the composition of the total.
            // It keeps the forced `-` (a write-off is always in the customer's
            // favour) and now carries its OWN label — the cash-rounding line
            // above already owns `rounding`.
            //
            // Printed only when a non-zero tolerance write-off is present on the receipt.
            // The TS layer (buildReceiptData) precomputes `has_tolerance` from the
            // monetary string using arbitrary-precision decimal — Rust does not parse
            // the monetary value here.
            if data.has_tolerance {
                if let Some(ref tolerance) = data.tolerance_writeoff {
                    b.two_column(
                        &data.label(|l| &l.tolerance, "Tolerance"),
                        &format!("-{}{}", data.currency_symbol, tolerance),
                    );
                }
            }
        }
    }

    // ── Notes ──
    if let Some(ref notes) = data.notes {
        b.separator('-');
        b.text_line(notes);
    }

    // ── Fiscal Compliance Footer ──
    if data.show_fiscal_info.unwrap_or(true)
        && (data.fiscal_hash.is_some() || data.fiscal_signature.is_some())
    {
        b.separator('-');
        b.select_font(true); // Font B (smaller) for compliance data
        b.align(Alignment::Center);

        if let Some(ref hash) = data.fiscal_hash {
            // Show first 16 and last 16 chars of the hash for readability
            let display_hash = if hash.len() > 32 {
                format!("{}...{}", &hash[..16], &hash[hash.len() - 16..])
            } else {
                hash.clone()
            };
            b.text_line(&format!("Hash: {}", display_hash));
        }

        if let Some(ref sig) = data.fiscal_signature {
            b.text_line(&format!("Sig: {}", sig));
        }

        // DEV-QA-093: no fiscal-hash QR. It encoded the raw hash hex — not a
        // URL, no scheme, nothing a customer's camera can resolve — and it
        // duplicated the `Hash:` line printed two lines above. The ticket now
        // carries exactly ONE QR, the labelled refund-lookup token below.

        b.select_font(false); // Back to Font A
    }

    // ── Receipt QR token (scannable for return / partial refund) ──
    // The QR encodes the canonical `v:kid:receipt_uuid:mac` token signed by
    // the backend's ReceiptQrTokenSigner. Any terminal can scan a printed
    // receipt to start a return — see voucherRepository.findReceiptByQrToken.
    // Distinct from the fiscal_hash QR above (compliance-only) — this one
    // is workflow-facing.
    if let Some(ref token) = data.qr_token {
        if !token.is_empty() {
            b.separator('-');
            b.align(Alignment::Center);
            b.empty_line();
            // Caption FIRST, so the customer knows what the one remaining QR
            // is for before they look at it (DEV-QA-093). Same idiom as the
            // refund path's original-ticket caption above (`:490-494`):
            // Font B for the label, back to Font A for everything after.
            b.select_font(true);
            b.text_line(&data.label(|l| &l.qr_scan_label, "Scan for return / exchange"));
            b.select_font(false);
            b.qr_code(token, 4, QrErrorCorrection::M);
            b.empty_line();
            // Human-readable token below the QR (small font) so it can be
            // typed in if the QR is damaged.
            b.select_font(true);
            b.text_line(token);
            b.select_font(false);
        }
    }

    // ── Footer ──
    b.align(Alignment::Center);
    b.empty_line();
    let default_thank_you = data.label(|l| &l.thank_you, "Thank you for your purchase!");
    let footer = settings
        .and_then(|s| {
            if s.footer_text.is_empty() {
                None
            } else {
                Some(s.footer_text.clone())
            }
        })
        .unwrap_or(default_thank_you);
    b.text_line(&footer);
    b.empty_line();

    // ── Cash-Count block (Z-report only) ──
    if let Some(counts) = &data.cash_counts {
        if !counts.is_empty() {
            b.separator('-');
            b.align(Alignment::Center);
            b.bold(true);
            b.text_line(&data.label(|l| &l.cash_count_section_title, "CASH COUNT"));
            b.bold(false);
            b.align(Alignment::Left);
            let cols = columns as usize;
            let header = format_cash_count_header(data, cols);
            b.text_line(&header);
            b.separator('-');
            for row in counts {
                let line = format_cash_count_row(row, cols);
                b.text_line(&line);
            }
            b.separator('-');
            if let Some(agg) = &data.aggregate_variance {
                let has_over = counts.iter().any(|r| r.direction == "over");
                let has_under = counts.iter().any(|r| r.direction == "under");
                let dir = if has_over {
                    "+"
                } else if has_under {
                    "-"
                } else {
                    " "
                };
                b.text_line(&format!(
                    "{} {} {}",
                    data.label(|l| &l.cash_count_total_variance, "Total variance:"),
                    agg,
                    dir,
                ));
            }
            if let Some(mgr) = &data.manager_name {
                b.text_line(&format!(
                    "{} {}",
                    data.label(|l| &l.cash_count_approved_by, "Approved by:"),
                    mgr,
                ));
            }
            if let Some(reason) = &data.variance_reason {
                b.text_line(&format!(
                    "{} {}",
                    data.label(|l| &l.cash_count_reason, "Reason:"),
                    reason,
                ));
            }
        }
    }

    // Feed and cut
    b.feed_lines(4);
    let cut = settings.and_then(|s| s.cut_mode_enum());
    if let Some(mode) = cut {
        b.cut(mode);
    } else if settings.map_or(true, |s| s.cut_mode != "none") {
        b.cut(CutMode::Partial);
    }

    b.build()
}

fn non_empty_trimmed(value: &str) -> Option<&str> {
    let trimmed = value.trim();
    if trimmed.is_empty() {
        None
    } else {
        Some(trimmed)
    }
}

fn format_cash_count_header(data: &ReceiptData, cols: usize) -> String {
    let tender_w = (cols / 4).min(10);
    let tender_label = data.label(|l| &l.cash_count_col_tender, "Tender");
    let expected_label = data.label(|l| &l.cash_count_col_expected, "Expected");
    let actual_label = data.label(|l| &l.cash_count_col_actual, "Actual");
    let variance_label = data.label(|l| &l.cash_count_col_variance, "Variance");
    let tender_truncated: String = tender_label.chars().take(tender_w).collect();
    format!(
        "{:<tender_w$} {:>9} {:>9} {:>9}",
        tender_truncated,
        &expected_label[..expected_label.len().min(9)],
        &actual_label[..actual_label.len().min(9)],
        &variance_label[..variance_label.len().min(9)],
        tender_w = tender_w,
    )
}

fn format_cash_count_row(row: &ZReceiptCashCountRow, cols: usize) -> String {
    let tender_w = (cols / 4).min(10);
    let dir_char = match row.direction.as_str() {
        "over" => "+",
        "under" => "-",
        _ => " ",
    };
    let name_truncated: String = row.name.chars().take(tender_w).collect();
    format!(
        "{:<tender_w$} {:>9} {:>9} {:>8}{}",
        &name_truncated,
        row.expected,
        row.actual,
        row.variance,
        dir_char,
        tender_w = tender_w,
    )
}

/// Format a test page for printer alignment verification.
pub fn format_test_page() -> Vec<u8> {
    format_test_page_with_columns(None, None)
}

/// Format a test page with optional column width and print settings.
///
/// The settings matter here more than anywhere else: this is the page the
/// operator prints to validate a printer, so it must go out under the SAME
/// code page and transcoding as a real receipt (DEV-QA-094). Before the fix it
/// took only `columns` and emitted no `ESC t` at all, which is precisely why a
/// green test page never revealed the mojibake on the ticket.
pub fn format_test_page_with_columns(
    columns: Option<u8>,
    settings: Option<&PrintSettings>,
) -> Vec<u8> {
    let cols = columns
        .or_else(|| settings.map(|s| s.columns))
        .unwrap_or(42);
    let encoding = settings.map_or(TextEncoding::Cp1252, |s| s.text_encoding());
    let mut b = EscPosBuilder::with_columns_and_encoding(cols, encoding);

    b.align(Alignment::Center);
    b.font_size(FontSize::DoubleWidthHeight);
    b.bold(true);
    b.text_line("IziPOS Test Print");
    b.bold(false);
    b.font_size(FontSize::Normal);
    b.empty_line();

    b.align(Alignment::Left);
    b.separator('=');

    // Test alignment
    b.align(Alignment::Left);
    b.text_line("Left aligned text");
    b.align(Alignment::Center);
    b.text_line("Center aligned text");
    b.align(Alignment::Right);
    b.text_line("Right aligned text");

    b.align(Alignment::Left);
    b.separator('-');

    // Code-page / accent check (DEV-QA-094). The operator validates a printer
    // with THIS page, so it has to show what the ticket will show: the page
    // that was declared, and the accents that page is supposed to carry. If
    // these print as `Θ` / `τ`, the printer ignored `ESC t` — switch the
    // encoding setting until they are right.
    b.text_line(&format!("Code page: ESC t {}", encoding.code_page()));
    b.text_line("Accents: Café crème, Garçon");
    b.text_line("àâäéèêëîïôöùûüç");

    b.separator('-');

    // Test two-column
    b.bold(true);
    b.text_line("Two-column layout:");
    b.bold(false);
    b.two_column("Left side", "Right side");
    b.two_column("Product Name", "10.00");
    b.two_column("Long product name here", "1,234.56");

    b.separator('-');

    // Test font sizes
    b.text_line("Normal text");
    b.font_size(FontSize::DoubleWidth);
    b.text_line("Double width");
    b.font_size(FontSize::DoubleHeight);
    b.text_line("Double height");
    b.font_size(FontSize::DoubleWidthHeight);
    b.text_line("Double W+H");
    b.font_size(FontSize::Normal);

    b.separator('-');

    // Test bold/underline
    b.bold(true);
    b.text_line("Bold text");
    b.bold(false);
    b.underline(true);
    b.text_line("Underlined text");
    b.underline(false);

    b.separator('-');

    // Test barcode
    b.align(Alignment::Center);
    b.text_line("Barcode test:");
    b.barcode_height(60);
    b.barcode_width(3);
    b.barcode_hri_position(2);
    b.barcode(super::escpos::BarcodeSystem::Code128, "IZIPOS001");
    b.empty_line();

    // Test QR
    b.text_line("QR Code test:");
    b.qr_code("https://izipos.app/test", 6, QrErrorCorrection::M);
    b.empty_line();

    b.separator('=');
    b.text_line("Printer OK");
    b.empty_line();

    // Column ruler
    b.align(Alignment::Left);
    b.select_font(true);
    let ruler: String = (0..cols as u32)
        .map(|i| char::from(b'0' + ((i % 10) as u8)))
        .collect();
    b.text_line(&ruler);
    b.select_font(false);

    b.feed_lines(4);
    b.cut(CutMode::Partial);

    b.build()
}

#[cfg(test)]
mod tests_z_cash_counts {
    use super::*;

    fn make_company() -> CompanyInfo {
        CompanyInfo {
            name: "Test Shop".to_string(),
            address_line1: "".to_string(),
            address_line2: None,
            city: "".to_string(),
            postal_code: "".to_string(),
            country: "".to_string(),
            tax_id: "".to_string(),
            phone: None,
            vat_number: None,
            legal_identifier_lines: None,
        }
    }

    #[test]
    fn z_receipt_includes_cash_count_block_when_present() {
        let data = ReceiptData {
            company: make_company(),
            receipt_number: "Z0042".to_string(),
            date_time: "2026-04-25T10:00:00Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            lines: vec![],
            subtotal: "0.00".to_string(),
            discount_amount: "0.00".to_string(),
            tax_amount: "0.00".to_string(),
            total: "0.00".to_string(),
            currency_symbol: "€".to_string(),
            vat_breakdown: vec![],
            payments: vec![],
            change_due: "0.00".to_string(),
            tolerance_writeoff: None,
            has_tolerance: false,
            cash_rounding_adjustment: None,
            has_cash_rounding: None,
            fiscal_hash: None,
            fiscal_signature: None,
            customer_name: None,
            notes: None,
            labels: None,
            show_vat_breakdown: Some(false),
            show_fiscal_info: Some(false),
            show_payment_details: Some(false),
            show_customer: Some(false),
            is_reprint: Some(false),
            cash_counts: Some(vec![ZReceiptCashCountRow {
                code: "CASH".to_string(),
                name: "Cash".to_string(),
                expected: "150.00".to_string(),
                actual: "155.00".to_string(),
                variance: "5.00".to_string(),
                direction: "over".to_string(),
            }]),
            manager_name: Some("Jean".to_string()),
            variance_reason: Some("till miscount".to_string()),
            variance_severity: Some("warning".to_string()),
            aggregate_variance: Some("5.00".to_string()),
            qr_token: None,
            receipt_kind: None,
            original_receipt_number: None,
            original_receipt_qr_token: None,
            account_balance_before: None,
            account_balance_after: None,
            account_snapshot_stale: false,
            business_date: None,
            terminal_id: None,
            shift_id: None,
            training_flag: false,
            customer_account_id: None,
            customer_phone: None,
        };
        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);
        assert!(text.contains("CASH"), "should contain tender code CASH");
        assert!(text.contains("Jean"), "should contain manager name");
        assert!(
            text.contains("till miscount"),
            "should contain variance reason"
        );
    }

    #[test]
    fn account_payment_receipt_uses_account_layout_without_sale_lines_or_vat() {
        let data = ReceiptData {
            company: make_company(),
            receipt_number: "AP-0001".to_string(),
            date_time: "2026-05-21T10:15:30.000Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            lines: vec![],
            subtotal: "0.00".to_string(),
            discount_amount: "0.00".to_string(),
            tax_amount: "0.00".to_string(),
            total: "100.000".to_string(),
            currency_symbol: "TND".to_string(),
            vat_breakdown: vec![],
            payments: vec![PaymentLine {
                method: "CASH".to_string(),
                amount: "100.000".to_string(),
            }],
            change_due: "0.000".to_string(),
            tolerance_writeoff: None,
            has_tolerance: false,
            cash_rounding_adjustment: None,
            has_cash_rounding: None,
            fiscal_hash: Some("b".repeat(64)),
            fiscal_signature: Some("event-1".to_string()),
            customer_name: Some("Mariam Ben Ali".to_string()),
            notes: None,
            labels: None,
            show_vat_breakdown: Some(false),
            show_fiscal_info: Some(false),
            show_payment_details: Some(true),
            show_customer: Some(true),
            is_reprint: Some(false),
            cash_counts: None,
            manager_name: None,
            variance_reason: None,
            variance_severity: None,
            aggregate_variance: None,
            qr_token: None,
            receipt_kind: Some("account_payment".to_string()),
            original_receipt_number: None,
            original_receipt_qr_token: None,
            account_balance_before: Some("300.000".to_string()),
            account_balance_after: Some("200.000".to_string()),
            account_snapshot_stale: true,
            business_date: Some("2026-05-21".to_string()),
            terminal_id: Some("33333333-3333-4333-8333-333333333333".to_string()),
            shift_id: Some("22222222-2222-4222-8222-222222222222".to_string()),
            training_flag: true,
            customer_account_id: Some("55555555-5555-4555-8555-555555555555".to_string()),
            customer_phone: Some("+21611111111".to_string()),
        };

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("ACCOUNT PAYMENT RECEIPT"));
        assert!(text.contains("Balance before:"));
        assert!(text.contains("TND300.000"));
        assert!(text.contains("Balance after:"));
        assert!(text.contains("TND200.000"));
        assert!(text.contains("Balance snapshot stale"));
        assert!(text.contains("Business date:"));
        assert!(text.contains("2026-05-21"));
        assert!(text.contains("Terminal ID:"));
        assert!(text.contains("33333333-3333-4333-8333-333333333333"));
        assert!(text.contains("Shift ID:"));
        assert!(text.contains("22222222-2222-4222-8222-222222222222"));
        assert!(text.contains("Account:"));
        assert!(text.contains("55555555-5555-4555-8555-555555555555"));
        assert!(text.contains("Customer phone:"));
        assert!(text.contains("+21611111111"));
        assert!(text.contains("TRAINING"));
        assert!(!text.contains("Item"));
        assert!(!text.contains("VAT %"));
    }

    #[test]
    fn receipt_header_omits_blank_optional_legal_fields() {
        let mut company = make_company();
        company.name = "Test Shop".to_string();
        company.address_line1 = "12 Main Street".to_string();
        company.address_line2 = Some("   ".to_string());
        company.city = " ".to_string();
        company.postal_code = "".to_string();
        company.tax_id = "  ".to_string();
        company.phone = Some("\t".to_string());

        let data = ReceiptData {
            company,
            receipt_number: "R001".to_string(),
            date_time: "2026-05-12T10:00:00Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            lines: vec![],
            subtotal: "0.00".to_string(),
            discount_amount: "0.00".to_string(),
            tax_amount: "0.00".to_string(),
            total: "0.00".to_string(),
            currency_symbol: "€".to_string(),
            vat_breakdown: vec![],
            payments: vec![],
            change_due: "0.00".to_string(),
            tolerance_writeoff: None,
            has_tolerance: false,
            cash_rounding_adjustment: None,
            has_cash_rounding: None,
            fiscal_hash: None,
            fiscal_signature: None,
            customer_name: None,
            notes: None,
            labels: None,
            show_vat_breakdown: Some(false),
            show_fiscal_info: Some(false),
            show_payment_details: Some(false),
            show_customer: Some(false),
            is_reprint: Some(false),
            cash_counts: None,
            manager_name: None,
            variance_reason: None,
            variance_severity: None,
            aggregate_variance: None,
            qr_token: None,
            receipt_kind: None,
            original_receipt_number: None,
            original_receipt_qr_token: None,
            account_balance_before: None,
            account_balance_after: None,
            account_snapshot_stale: false,
            business_date: None,
            terminal_id: None,
            shift_id: None,
            training_flag: false,
            customer_account_id: None,
            customer_phone: None,
        };

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("Test Shop"));
        assert!(text.contains("12 Main Street"));
        assert!(!text.contains("Tax ID:"));
        assert!(!text.contains("Tel:"));
    }

    #[test]
    fn receipt_header_trims_present_optional_legal_fields() {
        let mut company = make_company();
        company.address_line1 = "12 Main Street".to_string();
        company.address_line2 = Some(" Suite 4 ".to_string());
        company.city = " Paris ".to_string();
        company.postal_code = " 75001 ".to_string();
        company.tax_id = " FR123 ".to_string();
        company.phone = Some(" 0102030405 ".to_string());

        let data = ReceiptData {
            company,
            receipt_number: "R001".to_string(),
            date_time: "2026-05-12T10:00:00Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            lines: vec![],
            subtotal: "0.00".to_string(),
            discount_amount: "0.00".to_string(),
            tax_amount: "0.00".to_string(),
            total: "0.00".to_string(),
            currency_symbol: "€".to_string(),
            vat_breakdown: vec![],
            payments: vec![],
            change_due: "0.00".to_string(),
            tolerance_writeoff: None,
            has_tolerance: false,
            cash_rounding_adjustment: None,
            has_cash_rounding: None,
            fiscal_hash: None,
            fiscal_signature: None,
            customer_name: None,
            notes: None,
            labels: None,
            show_vat_breakdown: Some(false),
            show_fiscal_info: Some(false),
            show_payment_details: Some(false),
            show_customer: Some(false),
            is_reprint: Some(false),
            cash_counts: None,
            manager_name: None,
            variance_reason: None,
            variance_severity: None,
            aggregate_variance: None,
            qr_token: None,
            receipt_kind: None,
            original_receipt_number: None,
            original_receipt_qr_token: None,
            account_balance_before: None,
            account_balance_after: None,
            account_snapshot_stale: false,
            business_date: None,
            terminal_id: None,
            shift_id: None,
            training_flag: false,
            customer_account_id: None,
            customer_phone: None,
        };

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("Suite 4"));
        assert!(text.contains("75001 Paris"));
        assert!(text.contains("Tax ID: FR123"));
        assert!(text.contains("Tel: 0102030405"));
    }

    #[test]
    fn receipt_header_prints_branch_vat_number_and_legal_identifier_lines() {
        // Spec 2026-06-11 §4.6: when the terminal location's fiscal identity
        // is complete, the TS boundary sends vat_number + pre-formatted legal
        // identifier lines — they print after the tax id; blank lines are
        // skipped.
        let mut company = make_company();
        company.tax_id = "BRANCH-FR-TAX".to_string();
        company.vat_number = Some("FRBRANCHVAT".to_string());
        company.legal_identifier_lines = Some(vec![
            "SIRET: 55210055400014".to_string(),
            "   ".to_string(),
        ]);

        let data = ReceiptData {
            company,
            receipt_number: "R001".to_string(),
            date_time: "2026-06-12T10:00:00Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            lines: vec![],
            subtotal: "0.00".to_string(),
            discount_amount: "0.00".to_string(),
            tax_amount: "0.00".to_string(),
            total: "0.00".to_string(),
            currency_symbol: "€".to_string(),
            vat_breakdown: vec![],
            payments: vec![],
            change_due: "0.00".to_string(),
            tolerance_writeoff: None,
            has_tolerance: false,
            cash_rounding_adjustment: None,
            has_cash_rounding: None,
            fiscal_hash: None,
            fiscal_signature: None,
            customer_name: None,
            notes: None,
            labels: None,
            show_vat_breakdown: Some(false),
            show_fiscal_info: Some(false),
            show_payment_details: Some(false),
            show_customer: Some(false),
            is_reprint: Some(false),
            cash_counts: None,
            manager_name: None,
            variance_reason: None,
            variance_severity: None,
            aggregate_variance: None,
            qr_token: None,
            receipt_kind: None,
            original_receipt_number: None,
            original_receipt_qr_token: None,
            account_balance_before: None,
            account_balance_after: None,
            account_snapshot_stale: false,
            business_date: None,
            terminal_id: None,
            shift_id: None,
            training_flag: false,
            customer_account_id: None,
            customer_phone: None,
        };

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("Tax ID: BRANCH-FR-TAX"));
        assert!(text.contains("VAT No: FRBRANCHVAT"));
        assert!(text.contains("SIRET: 55210055400014"));
    }

    // ── Cash rounding on the printed ticket (spec §4.3, Task 10) ────────────
    //
    // The stored receipt is deliberately MIXED: `total` is ROUNDED while
    // `subtotal` / `tax_amount` / `discount_amount` stay EXACT
    // (receiptService.ts). Without a rounding line the customer's own
    // arithmetic misses the total. These tests assert on the RENDERED bytes.

    /// A TND cash sale rounded DOWN to the nearest 50 millimes.
    /// 10.000 net + 1.900 VAT = 11.900 exact → 11.880 charged, adjustment −0.020.
    fn make_rounded_sale() -> ReceiptData {
        ReceiptData {
            company: make_company(),
            receipt_number: "R-T1-0007".to_string(),
            date_time: "2026-07-27T10:00:00Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            lines: vec![],
            subtotal: "10.000".to_string(),
            discount_amount: "0.000".to_string(),
            tax_amount: "1.900".to_string(),
            total: "11.880".to_string(),
            currency_symbol: "TND".to_string(),
            vat_breakdown: vec![],
            payments: vec![PaymentLine {
                method: "Cash".to_string(),
                amount: "11.880".to_string(),
            }],
            change_due: "0.000".to_string(),
            tolerance_writeoff: None,
            has_tolerance: false,
            cash_rounding_adjustment: Some("-0.020".to_string()),
            has_cash_rounding: Some(true),
            fiscal_hash: None,
            fiscal_signature: None,
            customer_name: None,
            notes: None,
            labels: None,
            show_vat_breakdown: Some(false),
            show_fiscal_info: Some(false),
            show_payment_details: Some(true),
            show_customer: Some(false),
            is_reprint: Some(false),
            cash_counts: None,
            manager_name: None,
            variance_reason: None,
            variance_severity: None,
            aggregate_variance: None,
            qr_token: None,
            receipt_kind: None,
            original_receipt_number: None,
            original_receipt_qr_token: None,
            account_balance_before: None,
            account_balance_after: None,
            account_snapshot_stale: false,
            business_date: None,
            terminal_id: None,
            shift_id: None,
            training_flag: false,
            customer_account_id: None,
            customer_phone: None,
        }
    }

    /// The rendered amount on the first line whose text starts with `label`,
    /// as signed millimes. Test-only parsing — the formatter itself never
    /// parses a monetary string.
    fn printed_millimes(text: &str, label: &str) -> i64 {
        // `contains`, not `starts_with`: the rendered stream interleaves ESC/POS
        // control bytes with the text, so a line can begin with e.g. `E!`.
        let line = text
            .lines()
            .find(|l| l.contains(label))
            .unwrap_or_else(|| panic!("no printed line contains {label:?}\n---\n{text}\n---"));
        let token = line
            .split_whitespace()
            .next_back()
            .unwrap_or_else(|| panic!("no amount token on {line:?}"));
        // The sign sits either side of the currency symbol depending on the
        // line ("TND-0.020" vs "-TND0.050"), so read it from the whole token.
        let negative = token.contains('-');
        let digits: String = token
            .chars()
            .filter(|c| c.is_ascii_digit() || *c == '.')
            .collect();
        let (whole, frac) = digits.split_once('.').unwrap_or((digits.as_str(), ""));
        let frac = format!("{frac:0<3}");
        let value: i64 = whole.parse::<i64>().expect("whole part") * 1000
            + frac[..3].parse::<i64>().expect("fraction part");
        if negative {
            -value
        } else {
            value
        }
    }

    #[test]
    fn rounded_sale_prints_a_rounding_line_that_reconciles_the_printed_total() {
        let bytes = format_receipt_with_settings(&make_rounded_sale(), None);
        let text = String::from_utf8_lossy(&bytes);

        let subtotal = printed_millimes(&text, "Subtotal:");
        let tax = printed_millimes(&text, "Tax:");
        let rounding = printed_millimes(&text, "Rounding");
        let total = printed_millimes(&text, "TOTAL:");

        assert_eq!(rounding, -20, "the signed adjustment prints verbatim");
        // The whole point: a customer adding up what is on the paper lands on
        // the printed TOTAL. Without the rounding line this is 11.900 != 11.880.
        assert_eq!(
            subtotal + tax + rounding,
            total,
            "printed ticket must reconcile:\n{text}"
        );
    }

    #[test]
    fn the_rounding_line_prints_between_tax_and_total_not_below_the_payments() {
        let bytes = format_receipt_with_settings(&make_rounded_sale(), None);
        let text = String::from_utf8_lossy(&bytes);

        let index_of = |needle: &str| {
            text.find(needle)
                .unwrap_or_else(|| panic!("{needle:?} not printed\n---\n{text}\n---"))
        };
        // Adjacency is what makes the arithmetic readable, and it also keeps
        // the line out of the `show_payment_details` gate.
        assert!(index_of("Tax:") < index_of("Rounding"));
        assert!(index_of("Rounding") < index_of("TOTAL:"));
    }

    /// D-1 (owner ruling 2026-08-25) — a discounted ticket prints the
    /// commercial layout: lines → Remise → per-rate base/VAT → TOTAL, and the
    /// customer's arithmetic lands on `Subtotal − Remise == TOTAL`.
    ///
    /// The ventilation table REPLACES the aggregate `Tax:` line: since D-1 the
    /// base and VAT are POST-remise, so `Subtotal − Remise + Tax` would count
    /// the discount twice on the printed ticket.
    #[test]
    fn a_discounted_sale_prints_the_ventilation_above_the_total_and_no_aggregate_tax_line() {
        let mut data = make_rounded_sale();
        data.subtotal = "640.000".to_string();
        data.discount_amount = "50.000".to_string();
        data.tax_amount = "65.453".to_string();
        data.total = "590.000".to_string();
        data.cash_rounding_adjustment = None;
        data.has_cash_rounding = Some(false);
        data.show_vat_breakdown = Some(true);
        data.vat_breakdown = vec![
            VatBreakdownLine { rate: "0.00".to_string(), taxable: "63.609".to_string(), tax: "0.000".to_string() },
            VatBreakdownLine { rate: "7.00".to_string(), taxable: "92.188".to_string(), tax: "6.453".to_string() },
            VatBreakdownLine { rate: "13.00".to_string(), taxable: "184.375".to_string(), tax: "23.969".to_string() },
            VatBreakdownLine { rate: "19.00".to_string(), taxable: "184.375".to_string(), tax: "35.031".to_string() },
        ];

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        let index_of = |needle: &str| {
            text.find(needle)
                .unwrap_or_else(|| panic!("{needle:?} not printed\n---\n{text}\n---"))
        };

        assert!(index_of("Subtotal:") < index_of("Discount"));
        assert!(index_of("Discount") < index_of("Taxable"));
        assert!(index_of("Taxable") < index_of("TOTAL:"));
        assert!(
            !text.contains("Tax:"),
            "the aggregate Tax line must give way to the ventilation\n{text}"
        );

        // The ticket's own arithmetic. `printed_millimes` reads the printed
        // sign, and the Remise line prints as `-TND50.000`, so the discount
        // comes back NEGATIVE and is ADDED here.
        assert_eq!(
            printed_millimes(&text, "Subtotal:") + printed_millimes(&text, "Discount"),
            printed_millimes(&text, "TOTAL:"),
        );
    }

    /// With the VAT block switched off there is no table to carry the base and
    /// VAT, so the aggregate `Tax:` line comes back — a merchant hiding the
    /// ventilation must not also lose the VAT figure.
    #[test]
    fn hiding_the_vat_block_restores_the_aggregate_tax_line() {
        let mut data = make_rounded_sale();
        data.show_vat_breakdown = Some(false);
        data.vat_breakdown = vec![VatBreakdownLine {
            rate: "19.00".to_string(),
            taxable: "10.000".to_string(),
            tax: "1.900".to_string(),
        }];

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("Tax:"), "aggregate Tax line must survive\n{text}");
        assert!(!text.contains("Taxable"), "ventilation must stay hidden\n{text}");
    }

    #[test]
    fn the_rounding_line_prints_even_when_payment_details_are_hidden() {
        let mut data = make_rounded_sale();
        data.show_payment_details = Some(false);

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("Rounding"), "totals must still reconcile\n{text}");
        assert_eq!(
            printed_millimes(&text, "Subtotal:") + printed_millimes(&text, "Tax:")
                + printed_millimes(&text, "Rounding"),
            printed_millimes(&text, "TOTAL:"),
        );
    }

    #[test]
    fn a_positive_adjustment_prints_without_a_forced_minus() {
        let mut data = make_rounded_sale();
        data.cash_rounding_adjustment = Some("0.030".to_string());
        data.total = "11.930".to_string();

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert_eq!(printed_millimes(&text, "Rounding"), 30);
        assert!(!text.contains("TND-0.030"));
    }

    #[test]
    fn an_unrounded_sale_prints_no_rounding_line() {
        let mut data = make_rounded_sale();
        data.cash_rounding_adjustment = None;
        data.has_cash_rounding = None;
        data.total = "11.900".to_string();

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(!text.contains("Rounding"));
        assert_eq!(
            printed_millimes(&text, "Subtotal:") + printed_millimes(&text, "Tax:"),
            printed_millimes(&text, "TOTAL:"),
        );
    }

    #[test]
    fn tolerance_and_rounding_print_as_two_distinctly_labelled_lines() {
        let mut data = make_rounded_sale();
        data.tolerance_writeoff = Some("0.050".to_string());
        data.has_tolerance = true;

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        // The tolerance line no longer borrows the `rounding` label.
        assert!(text.contains("Tolerance"), "{text}");
        assert_eq!(printed_millimes(&text, "Tolerance"), -50);
        assert_eq!(printed_millimes(&text, "Rounding"), -20);
        assert_eq!(text.matches("Rounding").count(), 1, "{text}");
    }

    #[test]
    fn a_localized_tolerance_label_is_used_when_supplied() {
        let mut data = make_rounded_sale();
        data.tolerance_writeoff = Some("0.050".to_string());
        data.has_tolerance = true;
        data.labels = Some(ReceiptLabels {
            receipt: None,
            date: None,
            terminal: None,
            operator: None,
            customer: None,
            item: None,
            qty: None,
            amount: None,
            subtotal: None,
            discount: None,
            tax: None,
            total: None,
            payments: None,
            change_due: None,
            rounding: Some("Arrondi".to_string()),
            tolerance: Some("Ecart accepte".to_string()),
            vat_rate: None,
            taxable: None,
            tax_col: None,
            thank_you: None,
            tax_id: None,
            vat_number: None,
            tel: None,
            cash_count_section_title: None,
            cash_count_total_variance: None,
            cash_count_approved_by: None,
            cash_count_reason: None,
            cash_count_col_tender: None,
            cash_count_col_expected: None,
            cash_count_col_actual: None,
            cash_count_col_variance: None,
            refund_header: None,
            original_ticket: None,
            original_qr_label: None,
            qr_scan_label: None,
            account_payment_header: None,
            balance_before: None,
            balance_after: None,
            stale_balance: None,
            business_date: None,
            terminal_id: None,
            shift_id: None,
            training: None,
            customer_account: None,
            customer_phone: None,
        });

        let bytes = format_receipt_with_settings(&data, None);
        let text = String::from_utf8_lossy(&bytes);

        assert!(text.contains("Arrondi"), "{text}");
        assert!(text.contains("Ecart accepte"), "{text}");
    }
}

/// Generate a cash drawer kick command only.
pub fn format_drawer_kick() -> Vec<u8> {
    format_drawer_kick_with_settings(None)
}

/// Generate a cash drawer kick command with optional settings.
pub fn format_drawer_kick_with_settings(settings: Option<&DrawerKickSettings>) -> Vec<u8> {
    let mut b = EscPosBuilder::new();
    match settings {
        Some(s) => {
            b.cash_drawer_kick_custom(s.pin, s.pulse_on, s.pulse_off);
            if s.beep {
                b.beep();
            }
        }
        None => {
            b.cash_drawer_kick(0);
        }
    }
    b.build()
}
