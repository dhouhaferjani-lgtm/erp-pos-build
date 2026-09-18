pub mod escpos;
/// Golden-bytes regression harness for the printed ticket (Lane E, DEV-QA-092..095).
/// Test-only: it captures and compares committed ESC/POS baselines, and ships no
/// production code.
#[cfg(test)]
mod golden;
pub mod network;
pub mod receipt_template;
pub mod usb;
pub mod voucher_ticket;
#[cfg(target_os = "windows")]
pub mod windows;

use serde::{Deserialize, Serialize};

/// Represents a discovered printer (USB or network).
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PrinterInfo {
    pub id: String,
    pub name: String,
    pub connection_type: PrinterConnectionType,
    /// For USB: the serial port path. For network: "host:port".
    pub address: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum PrinterConnectionType {
    Usb,
    Network,
    Windows,
}

/// Errors from the printing subsystem.
#[derive(Debug, thiserror::Error)]
pub enum PrintError {
    #[error("USB error: {0}")]
    Usb(String),
    #[error("Network error: {0}")]
    Network(String),
    #[error("Template error: {0}")]
    Template(String),
    #[error("No printer configured")]
    NoPrinter,
    #[error("Printer not found: {0}")]
    PrinterNotFound(String),
    #[error("Windows printing error: {0}")]
    Windows(String),
}

impl Serialize for PrintError {
    fn serialize<S>(&self, serializer: S) -> Result<S::Ok, S::Error>
    where
        S: serde::Serializer,
    {
        serializer.serialize_str(&self.to_string())
    }
}

/// Send raw bytes to a printer identified by connection type and address.
pub async fn send_to_printer(
    connection_type: &PrinterConnectionType,
    address: &str,
    data: &[u8],
) -> Result<(), PrintError> {
    match connection_type {
        PrinterConnectionType::Usb => usb::send(address, data),
        PrinterConnectionType::Network => network::send(address, data).await,
        #[cfg(target_os = "windows")]
        PrinterConnectionType::Windows => windows::send(address, data).await,
        #[cfg(not(target_os = "windows"))]
        PrinterConnectionType::Windows => Err(PrintError::Windows(
            "Windows printing is only available on Windows".to_string(),
        )),
    }
}
