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

/// Format receipt data into ESC/POS commands ready to send to a printer.
pub fn format_receipt(data: &ReceiptData) -> Vec<u8> {
    let mut b = EscPosBuilder::new();

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
        b.text_line(&format!("Tel: {}", phone));
    }
    b.text_line(&format!("Tax ID: {}", data.company.tax_id));

    b.align(Alignment::Left);
    b.separator('-');

    // ── Receipt Meta ──
    b.two_column("Receipt:", &data.receipt_number);
    b.two_column("Date:", &data.date_time);
    b.two_column("Terminal:", &data.terminal_name);
    b.two_column("Operator:", &data.operator_name);

    if let Some(ref customer) = data.customer_name {
        b.two_column("Customer:", customer);
    }

    b.separator('=');

    // ── Line Items ──
    // Header
    b.bold(true);
    b.three_column("Item", "Qty", "Amount");
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
                "  Discount",
                &format!("-{}{}", data.currency_symbol, discount),
            );
        }
    }

    b.separator('=');

    // ── Totals ──
    b.two_column("Subtotal:", &format!("{}{}", data.currency_symbol, data.subtotal));

    if data.discount_amount != "0.00" && data.discount_amount != "0" {
        b.two_column(
            "Discount:",
            &format!("-{}{}", data.currency_symbol, data.discount_amount),
        );
    }

    b.two_column("Tax:", &format!("{}{}", data.currency_symbol, data.tax_amount));

    b.bold(true);
    b.font_size(FontSize::DoubleHeight);
    b.two_column("TOTAL:", &format!("{}{}", data.currency_symbol, data.total));
    b.font_size(FontSize::Normal);
    b.bold(false);

    // ── VAT Breakdown ──
    if !data.vat_breakdown.is_empty() {
        b.separator('-');
        b.bold(true);
        b.three_column("VAT %", "Taxable", "Tax");
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
    b.text_line("Payments:");
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
            "Change Due:",
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
    b.text_line("Thank you for your purchase!");
    b.empty_line();

    // Feed and cut
    b.feed_lines(4);
    b.cut(CutMode::Partial);

    b.build()
}

/// Format a test page for printer alignment verification.
pub fn format_test_page() -> Vec<u8> {
    let mut b = EscPosBuilder::new();

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
    let ruler: String = (0..42).map(|i| char::from(b'0' + (i % 10))).collect();
    b.text_line(&ruler);
    b.select_font(false);

    b.feed_lines(4);
    b.cut(CutMode::Partial);

    b.build()
}

/// Generate a cash drawer kick command only.
pub fn format_drawer_kick() -> Vec<u8> {
    let mut b = EscPosBuilder::new();
    b.cash_drawer_kick(0); // Pin 2
    b.build()
}
