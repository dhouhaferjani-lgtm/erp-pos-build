use std::net::SocketAddr;
use tokio::io::AsyncWriteExt;
use tokio::net::TcpStream;
use tokio::time::{timeout, Duration};

use super::{PrintError, PrinterConnectionType, PrinterInfo};

/// Default ESC/POS network printer port.
const DEFAULT_PORT: u16 = 9100;

/// Connection timeout for network printers.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(3);

/// Write timeout for sending data.
const WRITE_TIMEOUT: Duration = Duration::from_secs(10);

/// Attempt to discover network printers by probing common addresses on port 9100.
/// This performs a TCP connect scan on the local subnet.
///
/// In production, this is typically supplemented by mDNS/Bonjour discovery
/// or manual configuration. This scan checks a few well-known addresses.
pub async fn discover(subnet_prefix: &str) -> Vec<PrinterInfo> {
    let mut printers = Vec::new();

    // Scan common addresses (.1 through .254) — but limit to first 50 for speed
    let mut tasks = Vec::new();

    for i in 1u8..=254 {
        let addr_str = format!("{}{}:{}", subnet_prefix, i, DEFAULT_PORT);
        if let Ok(addr) = addr_str.parse::<SocketAddr>() {
            let host = format!("{}{}", subnet_prefix, i);
            tasks.push(tokio::spawn(async move {
                match timeout(Duration::from_millis(200), TcpStream::connect(addr)).await {
                    Ok(Ok(_stream)) => Some(PrinterInfo {
                        id: format!("net:{}", host),
                        name: format!("Network Printer ({})", host),
                        connection_type: PrinterConnectionType::Network,
                        address: format!("{}:{}", host, DEFAULT_PORT),
                    }),
                    _ => None,
                }
            }));
        }
    }

    for task in tasks {
        if let Ok(Some(printer)) = task.await {
            printers.push(printer);
        }
    }

    printers
}

/// Send raw bytes to a network printer at host:port via TCP.
pub async fn send(address: &str, data: &[u8]) -> Result<(), PrintError> {
    let addr: SocketAddr = parse_address(address)?;

    let mut stream = timeout(CONNECT_TIMEOUT, TcpStream::connect(addr))
        .await
        .map_err(|_| PrintError::Network(format!("Connection timed out to {}", address)))?
        .map_err(|e| PrintError::Network(format!("Cannot connect to {}: {}", address, e)))?;

    timeout(WRITE_TIMEOUT, stream.write_all(data))
        .await
        .map_err(|_| PrintError::Network(format!("Write timed out to {}", address)))?
        .map_err(|e| PrintError::Network(format!("Write failed to {}: {}", address, e)))?;

    timeout(WRITE_TIMEOUT, stream.flush())
        .await
        .map_err(|_| PrintError::Network(format!("Flush timed out to {}", address)))?
        .map_err(|e| PrintError::Network(format!("Flush failed to {}: {}", address, e)))?;

    Ok(())
}

/// Parse an address string like "192.168.1.100:9100" or "192.168.1.100".
fn parse_address(address: &str) -> Result<SocketAddr, PrintError> {
    // Try parsing as-is first (with port)
    if let Ok(addr) = address.parse::<SocketAddr>() {
        return Ok(addr);
    }

    // Try appending default port
    let with_port = format!("{}:{}", address, DEFAULT_PORT);
    with_port
        .parse::<SocketAddr>()
        .map_err(|e| PrintError::Network(format!("Invalid address '{}': {}", address, e)))
}
