use super::{PrintError, PrinterConnectionType, PrinterInfo};

/// Discover USB serial printers by enumerating available serial ports.
/// Filters to ports that look like receipt printers (common USB-serial chips).
pub fn discover() -> Vec<PrinterInfo> {
    let mut printers = Vec::new();

    match serialport::available_ports() {
        Ok(ports) => {
            for port in ports {
                let name = match &port.port_type {
                    serialport::SerialPortType::UsbPort(usb_info) => {
                        let product = usb_info
                            .product
                            .as_deref()
                            .unwrap_or("USB Printer");
                        let manufacturer = usb_info
                            .manufacturer
                            .as_deref()
                            .unwrap_or("Unknown");
                        format!("{} ({})", product, manufacturer)
                    }
                    serialport::SerialPortType::PciPort => {
                        format!("PCI Serial ({})", port.port_name)
                    }
                    _ => continue, // Skip Bluetooth and Unknown types
                };

                printers.push(PrinterInfo {
                    id: format!("usb:{}", port.port_name),
                    name,
                    connection_type: PrinterConnectionType::Usb,
                    address: port.port_name.clone(),
                });
            }
        }
        Err(e) => {
            log::warn!("Failed to enumerate serial ports: {}", e);
        }
    }

    printers
}

/// Send raw bytes to a USB serial printer at the given port path.
///
/// Uses 9600 baud by default — most ESC/POS USB printers auto-negotiate
/// or use this as fallback. The port is opened, data written, and closed
/// for each print job to avoid holding the port.
pub fn send(port_path: &str, data: &[u8]) -> Result<(), PrintError> {
    use std::io::Write;
    use std::time::Duration;

    let mut port = serialport::new(port_path, 9600)
        .timeout(Duration::from_secs(5))
        .open()
        .map_err(|e| PrintError::Usb(format!("Cannot open {}: {}", port_path, e)))?;

    port.write_all(data)
        .map_err(|e| PrintError::Usb(format!("Write failed on {}: {}", port_path, e)))?;

    port.flush()
        .map_err(|e| PrintError::Usb(format!("Flush failed on {}: {}", port_path, e)))?;

    Ok(())
}
