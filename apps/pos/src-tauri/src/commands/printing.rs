use crate::printing::{
    self, PrintError, PrinterConnectionType, PrinterInfo,
    receipt_template::{self, ReceiptData},
};

/// Discover available printers (USB + network).
/// The `subnet_prefix` parameter is optional for network scanning (e.g., "192.168.1.").
#[tauri::command]
pub async fn discover_printers(subnet_prefix: Option<String>) -> Result<Vec<PrinterInfo>, PrintError> {
    let mut printers = Vec::new();

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
) -> Result<(), PrintError> {
    let data = receipt_template::format_receipt(&receipt);

    log::info!(
        "Printing receipt {} ({} bytes) to {:?}:{}",
        receipt.receipt_number,
        data.len(),
        connection_type,
        address,
    );

    printing::send_to_printer(&connection_type, &address, &data).await
}

/// Print a test/alignment page to verify printer configuration.
#[tauri::command]
pub async fn print_test_page(
    connection_type: PrinterConnectionType,
    address: String,
) -> Result<(), PrintError> {
    let data = receipt_template::format_test_page();

    log::info!(
        "Printing test page ({} bytes) to {:?}:{}",
        data.len(),
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
) -> Result<(), PrintError> {
    let data = receipt_template::format_drawer_kick();

    log::info!(
        "Opening cash drawer via {:?}:{}",
        connection_type,
        address,
    );

    printing::send_to_printer(&connection_type, &address, &data).await
}
