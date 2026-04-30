use crate::printing::{
    self, PrintError, PrinterConnectionType, PrinterInfo,
    receipt_template::{self, DrawerKickSettings, PrintSettings, ReceiptData},
    voucher_ticket::{self, VoucherTicketData},
};

/// Discover available printers (USB + network).
/// The `subnet_prefix` parameter is optional for network scanning (e.g., "192.168.1.").
#[tauri::command]
pub async fn discover_printers(subnet_prefix: Option<String>) -> Result<Vec<PrinterInfo>, PrintError> {
    let mut printers = Vec::new();

    // Windows Winspool discovery (local + connected printers)
    #[cfg(target_os = "windows")]
    {
        match printing::windows::discover() {
            Ok(win_printers) => printers.extend(win_printers),
            Err(e) => log::warn!("Windows printer discovery failed: {}", e),
        }
    }

    // USB discovery (synchronous, quick)
    let usb_printers = printing::usb::discover();
    printers.extend(usb_printers);

    // Network discovery (async, scans subnet)
    let prefix = subnet_prefix.unwrap_or_else(|| "192.168.1.".to_string());
    let net_printers = printing::network::discover(&prefix).await;
    printers.extend(net_printers);

    log::info!("Discovered {} printers", printers.len());
    Ok(printers)
}

/// Print a receipt from JSON data.
/// Formats the receipt using the ESC/POS template and sends it to the specified printer.
#[tauri::command]
pub async fn print_receipt(
    receipt: ReceiptData,
    connection_type: PrinterConnectionType,
    address: String,
    print_settings: Option<PrintSettings>,
) -> Result<(), PrintError> {
    let data = receipt_template::format_receipt_with_settings(
        &receipt,
        print_settings.as_ref(),
    );

    let copies = print_settings.as_ref().map_or(1, |s| s.copies.max(1));

    log::info!(
        "Printing receipt {} ({} bytes, {} copies) to {:?}:{}",
        receipt.receipt_number,
        data.len(),
        copies,
        connection_type,
        address,
    );

    for _ in 0..copies {
        printing::send_to_printer(&connection_type, &address, &data).await?;
    }

    Ok(())
}

/// Print a voucher ticket from JSON data.
///
/// Voucher tickets are a SEPARATE artifact from refund receipts: when a
/// refund's destination is `store_voucher`, the POS prints both a refund
/// receipt AND a voucher ticket so the customer leaves with the redeemable
/// instrument. The dedicated layout (large code + scannable QR encoding the
/// code, balance, expiry, redemption mode) lives in
/// `voucher_ticket::format_voucher_ticket_with_settings`.
#[tauri::command]
pub async fn print_voucher_ticket(
    ticket: VoucherTicketData,
    connection_type: PrinterConnectionType,
    address: String,
    print_settings: Option<PrintSettings>,
) -> Result<(), PrintError> {
    let data = voucher_ticket::format_voucher_ticket_with_settings(
        &ticket,
        print_settings.as_ref(),
    );

    let copies = print_settings.as_ref().map_or(1, |s| s.copies.max(1));

    log::info!(
        "Printing voucher ticket {} ({} bytes, {} copies) to {:?}:{}",
        ticket.code,
        data.len(),
        copies,
        connection_type,
        address,
    );

    for _ in 0..copies {
        printing::send_to_printer(&connection_type, &address, &data).await?;
    }

    Ok(())
}

/// Print a test/alignment page to verify printer configuration.
#[tauri::command]
pub async fn print_test_page(
    connection_type: PrinterConnectionType,
    address: String,
    columns: Option<u8>,
) -> Result<(), PrintError> {
    let data = receipt_template::format_test_page_with_columns(columns);

    log::info!(
        "Printing test page ({} bytes, {} cols) to {:?}:{}",
        data.len(),
        columns.unwrap_or(42),
        connection_type,
        address,
    );

    printing::send_to_printer(&connection_type, &address, &data).await
}

/// Send a cash drawer kick pulse to open the cash drawer.
#[tauri::command]
pub async fn open_cash_drawer(
    connection_type: PrinterConnectionType,
    address: String,
    drawer_settings: Option<DrawerKickSettings>,
) -> Result<(), PrintError> {
    let data = receipt_template::format_drawer_kick_with_settings(drawer_settings.as_ref());

    log::info!(
        "Opening cash drawer via {:?}:{}",
        connection_type,
        address,
    );

    printing::send_to_printer(&connection_type, &address, &data).await
}
