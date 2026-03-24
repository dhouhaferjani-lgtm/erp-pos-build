use serde::{Deserialize, Serialize};

use super::escpos::{Alignment, CutMode, EscPosBuilder, FontSize, QrErrorCorrection};

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
    /// Fiscal compliance
    pub fiscal_hash: Option<String>,
    pub fiscal_signature: Option<String>,
    /// Optional customer info
    pub customer_name: Option<String>,
    /// Optional notes
    pub notes: Option<String>,
    /// Localized labels (optional — English defaults if absent)
    pub labels: Option<ReceiptLabels>,
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
    pub vat_rate: Option<String>,
    pub taxable: Option<String>,
    pub tax_col: Option<String>,
    pub thank_you: Option<String>,
    pub tax_id: Option<String>,
    pub tel: Option<String>,
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
    fn code_page(&self) -> u8 {
        match self.encoding.as_str() {
            "cp858" => 19,
            "cp1252" => 16,
            _ => 0, // cp437
        }
    }

    /// Return the `encoding_rs` encoding matching the configured code page.
    fn encoding_rs(&self) -> &'static encoding_rs::Encoding {
        match self.encoding.as_str() {
            "cp1252" => encoding_rs::WINDOWS_1252,
            "cp858" => encoding_rs::WINDOWS_1252, // CP858 ≈ CP850 + euro; 1252 covers French needs
            _ => encoding_rs::WINDOWS_1252,        // default to 1252 instead of cp437
        }
    }

    fn cut_mode_enum(&self) -> Option<CutMode> {
        match self.cut_mode.as_str() {
            "full" => Some(CutMode::Full),
            "partial" => Some(CutMode::Partial),
            _ => None, // "none"
        }
    }
}

/// Format receipt data into ESC/POS commands ready to send to a printer.
pub fn format_receipt(data: &ReceiptData) -> Vec<u8> {
    format_receipt_with_settings(data, None)
}

/// Format receipt with optional print settings.
pub fn format_receipt_with_settings(data: &ReceiptData, settings: Option<&PrintSettings>) -> Vec<u8> {
    let columns = settings.map_or(42, |s| s.columns);
    let mut b = EscPosBuilder::with_columns(columns);

    // Set encoding and code page if specified (after initialize, which is called in with_columns)
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

    b.text_line(&data.company.address_line1);
    if let Some(ref addr2) = data.company.address_line2 {
        b.text_line(addr2);
    }
    b.text_line(&format!(
        "{} {}",
        data.company.postal_code, data.company.city
    ));
    if let Some(ref phone) = data.company.phone {
        b.text_line(&format!("{} {}", data.label(|l| &l.tel, "Tel:"), phone));
    }
    b.text_line(&format!("{} {}", data.label(|l| &l.tax_id, "Tax ID:"), data.company.tax_id));

    b.align(Alignment::Left);
    b.separator('-');

    // ── Receipt Meta ──
    b.two_column(&data.label(|l| &l.receipt, "Receipt:"), &data.receipt_number);
    b.two_column(&data.label(|l| &l.date, "Date:"), &data.date_time);
    b.two_column(&data.label(|l| &l.terminal, "Terminal:"), &data.terminal_name);
    b.two_column(&data.label(|l| &l.operator, "Operator:"), &data.operator_name);

    if let Some(ref customer) = data.customer_name {
        b.two_column(&data.label(|l| &l.customer, "Customer:"), customer);
    }

    b.separator('=');

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
    b.two_column(&data.label(|l| &l.subtotal, "Subtotal:"), &format!("{}{}", data.currency_symbol, data.subtotal));

    if data.discount_amount != "0.00" && data.discount_amount != "0" {
        b.two_column(
            &format!("{}:", data.label(|l| &l.discount, "Discount").trim_end_matches(':')),
            &format!("-{}{}", data.currency_symbol, data.discount_amount),
        );
    }

    b.two_column(&data.label(|l| &l.tax, "Tax:"), &format!("{}{}", data.currency_symbol, data.tax_amount));

    b.bold(true);
    b.font_size(FontSize::DoubleHeight);
    b.two_column(&data.label(|l| &l.total, "TOTAL:"), &format!("{}{}", data.currency_symbol, data.total));
    b.font_size(FontSize::Normal);
    b.bold(false);

    // ── VAT Breakdown ──
    if !data.vat_breakdown.is_empty() {
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
    }

    // ── Payments ──
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

    // ── Notes ──
    if let Some(ref notes) = data.notes {
        b.separator('-');
        b.text_line(notes);
    }

    // ── Fiscal Compliance Footer ──
    if data.fiscal_hash.is_some() || data.fiscal_signature.is_some() {
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

        // QR code with full hash for verification
        if let Some(ref hash) = data.fiscal_hash {
            b.empty_line();
            b.qr_code(hash, 4, QrErrorCorrection::M);
            b.empty_line();
        }

        b.select_font(false); // Back to Font A
    }

    // ── Footer ──
    b.align(Alignment::Center);
    b.empty_line();
    let default_thank_you = data.label(|l| &l.thank_you, "Thank you for your purchase!");
    let footer = settings
        .and_then(|s| if s.footer_text.is_empty() { None } else { Some(s.footer_text.clone()) })
        .unwrap_or(default_thank_you);
    b.text_line(&footer);
    b.empty_line();

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

/// Format a test page for printer alignment verification.
pub fn format_test_page() -> Vec<u8> {
    format_test_page_with_columns(None)
}

/// Format a test page with optional column width.
pub fn format_test_page_with_columns(columns: Option<u8>) -> Vec<u8> {
    let cols = columns.unwrap_or(42);
    let mut b = EscPosBuilder::with_columns(cols);

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
    let ruler: String = (0..cols as u32).map(|i| char::from(b'0' + ((i % 10) as u8))).collect();
    b.text_line(&ruler);
    b.select_font(false);

    b.feed_lines(4);
    b.cut(CutMode::Partial);

    b.build()
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
