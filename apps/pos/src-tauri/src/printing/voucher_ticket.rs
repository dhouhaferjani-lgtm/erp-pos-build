//! Voucher ticket renderer.
//!
//! When a refund destination is `store_voucher`, the POS prints a SEPARATE
//! ticket alongside the refund receipt (see receipt_template.rs). Voucher
//! tickets have a fundamentally different layout from receipts (no line
//! items, dominant voucher-code section, scannable QR encoding the code) so
//! they live in their own module instead of bloating the receipt formatter
//! with conditional branches.
//!
//! The rendered ticket includes:
//!   - Company header (same as receipts).
//!   - Localized "STORE VOUCHER" / "BON D'ACHAT" banner.
//!   - Voucher code in large monospaced font + scannable QR.
//!   - Issued balance.
//!   - Expiry date (or "No expiry" line if null).
//!   - Redemption mode (Bearer / Customer-bound).
//!   - Issuing terminal + operator + ISO date.
//!   - Terms one-liner.

use serde::{Deserialize, Serialize};

use super::escpos::{Alignment, CutMode, EscPosBuilder, FontSize, QrErrorCorrection};
use super::receipt_template::{CompanyInfo, PrintSettings};

/// Localized voucher-ticket labels. All optional with English defaults.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VoucherTicketLabels {
    pub header: Option<String>,
    pub code: Option<String>,
    pub balance: Option<String>,
    pub expires: Option<String>,
    pub no_expiry: Option<String>,
    pub mode_bearer: Option<String>,
    pub mode_customer_bound: Option<String>,
    pub redemption_mode: Option<String>,
    pub issued_by: Option<String>,
    pub issued_at: Option<String>,
    pub terms: Option<String>,
}

/// Voucher ticket data passed from the frontend via JSON.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VoucherTicketData {
    pub company: CompanyInfo,
    pub code: String,
    pub initial_balance: String,
    pub currency_symbol: String,
    /// ISO timestamp of expiry, or `None` for no-expiry vouchers.
    #[serde(default)]
    pub expires_at: Option<String>,
    /// "Bearer" or "CustomerBound" — printed via the localized label.
    pub redemption_mode: String,
    pub issued_at: String,
    pub terminal_name: String,
    pub operator_name: String,
    #[serde(default)]
    pub labels: Option<VoucherTicketLabels>,
}

impl VoucherTicketData {
    fn label(
        &self,
        getter: impl Fn(&VoucherTicketLabels) -> &Option<String>,
        default: &str,
    ) -> String {
        self.labels
            .as_ref()
            .and_then(|l| getter(l).as_ref())
            .cloned()
            .unwrap_or_else(|| default.to_string())
    }

    /// Render the localized redemption-mode label (Bearer / Customer-bound).
    /// Falls through to the raw value when the discriminator is unrecognized.
    fn redemption_mode_label(&self) -> String {
        match self.redemption_mode.as_str() {
            "Bearer" | "bearer" => self.label(|l| &l.mode_bearer, "Bearer"),
            "CustomerBound" | "customer_bound" => {
                self.label(|l| &l.mode_customer_bound, "Customer-bound")
            }
            other => other.to_string(),
        }
    }
}

/// Format a voucher ticket into ESC/POS commands ready to send to a printer.
///
/// Mirrors the `format_receipt` / `format_receipt_with_settings` pair in
/// receipt_template.rs — the no-settings shortcut exists for parity, even
/// though the production call path goes through the `_with_settings`
/// variant invoked by the Tauri command handler.
///
/// This function is exercised by the test module
/// (`tests::voucher_ticket_includes_code_and_balance` and its siblings).
#[allow(dead_code)] // called from the test module below; not reachable from production code paths
pub fn format_voucher_ticket(data: &VoucherTicketData) -> Vec<u8> {
    format_voucher_ticket_with_settings(data, None)
}

/// Format a voucher ticket with optional print settings.
pub fn format_voucher_ticket_with_settings(
    data: &VoucherTicketData,
    settings: Option<&PrintSettings>,
) -> Vec<u8> {
    let columns = settings.map_or(42, |s| s.columns);
    let mut b = EscPosBuilder::with_columns(columns);

    // Encoding / code page (same convention as receipt_template).
    if let Some(s) = settings {
        b.set_encoding(s.encoding_rs());
        let page = s.code_page();
        if page != 0 {
            b.set_code_page(page);
        }
    }

    // ── Company Header ──
    b.align(Alignment::Center);
    b.font_size(FontSize::DoubleWidthHeight);
    b.bold(true);
    b.text_line(&data.company.name);
    b.bold(false);
    b.font_size(FontSize::Normal);

    if !data.company.address_line1.is_empty() {
        b.text_line(&data.company.address_line1);
    }
    if let Some(ref addr2) = data.company.address_line2 {
        if !addr2.is_empty() {
            b.text_line(addr2);
        }
    }
    if !data.company.city.is_empty() || !data.company.postal_code.is_empty() {
        b.text_line(&format!(
            "{} {}",
            data.company.postal_code, data.company.city
        ));
    }

    b.separator('=');

    // ── Voucher banner ──
    b.align(Alignment::Center);
    b.font_size(FontSize::DoubleWidthHeight);
    b.bold(true);
    b.text_line(&data.label(|l| &l.header, "STORE VOUCHER"));
    b.bold(false);
    b.font_size(FontSize::Normal);

    b.separator('-');

    // ── Voucher code (large) + QR ──
    b.text_line(&data.label(|l| &l.code, "Voucher code:"));
    b.empty_line();
    b.font_size(FontSize::DoubleWidthHeight);
    b.bold(true);
    b.text_line(&data.code);
    b.bold(false);
    b.font_size(FontSize::Normal);
    b.empty_line();

    // QR encoding the voucher code so the next sale can scan it.
    b.qr_code(&data.code, 6, QrErrorCorrection::M);
    b.empty_line();

    b.separator('-');

    // ── Balance / expiry / mode ──
    b.align(Alignment::Left);
    b.two_column(
        &data.label(|l| &l.balance, "Balance:"),
        &format!("{}{}", data.currency_symbol, data.initial_balance),
    );

    let expires_value = match data.expires_at.as_ref() {
        Some(ts) if !ts.is_empty() => ts.clone(),
        _ => data.label(|l| &l.no_expiry, "No expiry"),
    };
    b.two_column(&data.label(|l| &l.expires, "Expires:"), &expires_value);

    b.two_column(
        &data.label(|l| &l.redemption_mode, "Mode:"),
        &data.redemption_mode_label(),
    );

    b.separator('-');

    // ── Issuance metadata ──
    b.two_column(
        &data.label(|l| &l.issued_by, "Issued by:"),
        &data.operator_name,
    );
    b.two_column(&data.label(|l| &l.issued_at, "Issued at:"), &data.issued_at);
    b.two_column("Terminal:", &data.terminal_name);

    b.separator('-');

    // ── Terms one-liner ──
    b.align(Alignment::Center);
    b.text_line(&data.label(|l| &l.terms, "Present this voucher to redeem its balance."));
    b.empty_line();

    // Feed and cut
    b.feed_lines(4);
    let cut = settings.and_then(|s| s.cut_mode_enum());
    if let Some(mode) = cut {
        b.cut(mode);
    } else if settings.is_none_or(|s| s.is_cut_enabled()) {
        b.cut(CutMode::Partial);
    }

    b.build()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn make_ticket() -> VoucherTicketData {
        VoucherTicketData {
            company: CompanyInfo {
                name: "Test Shop".to_string(),
                address_line1: "1 rue Test".to_string(),
                address_line2: None,
                city: "Paris".to_string(),
                postal_code: "75001".to_string(),
                country: "FR".to_string(),
                tax_id: "FR12345".to_string(),
                phone: None,
                vat_number: None,
                legal_identifier_lines: None,
            },
            code: "VCH-ABC-123".to_string(),
            initial_balance: "25.00".to_string(),
            currency_symbol: "€".to_string(),
            expires_at: Some("2026-12-31T23:59:59Z".to_string()),
            redemption_mode: "Bearer".to_string(),
            issued_at: "2026-04-30T10:15:00Z".to_string(),
            terminal_name: "T1".to_string(),
            operator_name: "Alice".to_string(),
            labels: None,
        }
    }

    #[test]
    fn voucher_ticket_includes_code_and_balance() {
        let ticket = make_ticket();
        let bytes = format_voucher_ticket(&ticket);
        let text = String::from_utf8_lossy(&bytes);
        assert!(text.contains("VCH-ABC-123"), "should include voucher code");
        assert!(text.contains("25.00"), "should include balance");
        assert!(text.contains("Test Shop"), "should include company name");
    }

    #[test]
    fn voucher_ticket_renders_no_expiry_when_expires_at_is_none() {
        let mut ticket = make_ticket();
        ticket.expires_at = None;
        let bytes = format_voucher_ticket(&ticket);
        let text = String::from_utf8_lossy(&bytes);
        assert!(
            text.contains("No expiry"),
            "should render the no-expiry label when expires_at is None",
        );
    }

    #[test]
    fn voucher_ticket_redemption_mode_falls_back_to_raw_value() {
        let mut ticket = make_ticket();
        ticket.redemption_mode = "SomeOtherMode".to_string();
        let bytes = format_voucher_ticket(&ticket);
        let text = String::from_utf8_lossy(&bytes);
        assert!(
            text.contains("SomeOtherMode"),
            "unrecognized mode should be printed verbatim",
        );
    }
}
