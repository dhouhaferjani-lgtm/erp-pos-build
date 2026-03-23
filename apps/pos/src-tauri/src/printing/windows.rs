//! Windows Winspool raw printing support.
//!
//! Uses the Windows Print Spooler API to discover installed printers and send
//! raw ESC/POS data directly to thermal receipt printers.

use std::ffi::OsStr;
use std::os::windows::ffi::OsStrExt;
use std::time::Duration;

use windows::core::{PCWSTR, PWSTR};
use windows::Win32::Foundation::{BOOL, HANDLE};
use windows::Win32::Graphics::Printing::{
    ClosePrinter, EnumPrintersW, OpenPrinterW, StartDocPrinterW, StartPagePrinter,
    WritePrinter, EndPagePrinter, EndDocPrinter,
    DOC_INFO_1W, PRINTER_DEFAULTSW, PRINTER_INFO_2W,
    PRINTER_ENUM_LOCAL, PRINTER_ENUM_CONNECTIONS,
    PRINTER_ACCESS_USE,
};

use super::{PrintError, PrinterConnectionType, PrinterInfo};

/// RAII wrapper around a Windows printer handle.
struct PrinterHandle(HANDLE);

impl Drop for PrinterHandle {
    fn drop(&mut self) {
        if !self.0.is_invalid() {
            unsafe {
                let _ = ClosePrinter(self.0);
            }
        }
    }
}

/// Convert a Rust string to a null-terminated UTF-16 wide string.
fn to_wide(s: &str) -> Vec<u16> {
    OsStr::new(s).encode_wide().chain(std::iter::once(0)).collect()
}

/// Check if a printer name looks like a virtual/document printer we should skip.
fn is_virtual_printer(name: &str) -> bool {
    let lower = name.to_lowercase();
    let virtual_keywords = [
        "pdf",
        "xps",
        "fax",
        "onenote",
        "one note",
        "microsoft print",
        "send to",
        "snagit",
        "cutepdf",
        "bullzip",
        "dopdf",
        "foxit",
        "nitro",
        "primo",
        "pdfcreator",
    ];
    virtual_keywords.iter().any(|kw| lower.contains(kw))
}

/// Discover physical printers installed on Windows via the Print Spooler.
pub fn discover() -> Result<Vec<PrinterInfo>, PrintError> {
    let flags = PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS;

    // First call: get required buffer size.
    let mut bytes_needed: u32 = 0;
    let mut count: u32 = 0;

    unsafe {
        let _ = EnumPrintersW(
            flags,
            None,
            2, // PRINTER_INFO_2
            None,
            &mut bytes_needed,
            &mut count,
        );
    }

    if bytes_needed == 0 {
        return Ok(Vec::new());
    }

    // Allocate buffer and enumerate.
    let mut buffer = vec![0u8; bytes_needed as usize];
    unsafe {
        EnumPrintersW(
            flags,
            None,
            2,
            Some(&mut buffer),
            &mut bytes_needed,
            &mut count,
        )
        .map_err(|e| PrintError::Windows(format!("EnumPrintersW failed: {}", e)))?;
    }

    let printer_infos = unsafe {
        std::slice::from_raw_parts(
            buffer.as_ptr() as *const PRINTER_INFO_2W,
            count as usize,
        )
    };

    let mut printers = Vec::new();
    for info in printer_infos {
        let name = unsafe { info.pPrinterName.to_string() }
            .unwrap_or_default();

        if name.is_empty() || is_virtual_printer(&name) {
            continue;
        }

        printers.push(PrinterInfo {
            id: format!("win-{}", name.replace(' ', "-").to_lowercase()),
            name: name.clone(),
            connection_type: PrinterConnectionType::Windows,
            address: name, // Windows printers are addressed by name
        });
    }

    Ok(printers)
}

/// Send raw ESC/POS data to a Windows printer via the Winspool RAW API.
///
/// This opens the printer, starts a RAW document, writes the data, and cleans up.
/// Wrapped in `spawn_blocking` with a 10-second timeout since Winspool calls are blocking.
pub async fn send(printer_name: &str, data: &[u8]) -> Result<(), PrintError> {
    let name = printer_name.to_string();
    let data = data.to_vec();

    let result = tokio::time::timeout(
        Duration::from_secs(10),
        tokio::task::spawn_blocking(move || send_blocking(&name, &data)),
    )
    .await;

    match result {
        Ok(Ok(inner)) => inner,
        Ok(Err(e)) => Err(PrintError::Windows(format!("Task join error: {}", e))),
        Err(_) => Err(PrintError::Windows(
            "Printer operation timed out after 10 seconds".to_string(),
        )),
    }
}

/// Blocking implementation of the Winspool send sequence.
fn send_blocking(printer_name: &str, data: &[u8]) -> Result<(), PrintError> {
    let wide_name = to_wide(printer_name);

    let defaults = PRINTER_DEFAULTSW {
        pDatatype: PWSTR::null(),
        pDevMode: std::ptr::null_mut(),
        DesiredAccess: PRINTER_ACCESS_USE,
    };

    let mut handle = HANDLE::default();

    unsafe {
        OpenPrinterW(
            PCWSTR(wide_name.as_ptr()),
            &mut handle,
            Some(&defaults),
        )
        .map_err(|e| PrintError::Windows(format!("OpenPrinterW failed: {}", e)))?;
    }

    let printer = PrinterHandle(handle);

    let mut doc_name = to_wide("POS Receipt");
    let mut datatype = to_wide("RAW");

    let mut doc_info = DOC_INFO_1W {
        pDocName: PWSTR(doc_name.as_mut_ptr()),
        pOutputFile: PWSTR::null(),
        pDatatype: PWSTR(datatype.as_mut_ptr()),
    };

    unsafe {
        let job_id = StartDocPrinterW(printer.0, 1, &mut doc_info as *mut _ as *mut _);
        if job_id == 0 {
            return Err(PrintError::Windows(
                "StartDocPrinterW failed: returned job ID 0".to_string(),
            ));
        }

        let result: BOOL = StartPagePrinter(printer.0);
        if !result.as_bool() {
            return Err(PrintError::Windows("StartPagePrinter failed".to_string()));
        }

        let mut bytes_written: u32 = 0;
        let result: BOOL = WritePrinter(
            printer.0,
            data.as_ptr() as *const _,
            data.len() as u32,
            &mut bytes_written,
        );
        if !result.as_bool() {
            return Err(PrintError::Windows("WritePrinter failed".to_string()));
        }

        if (bytes_written as usize) != data.len() {
            log::warn!(
                "WritePrinter wrote {} of {} bytes",
                bytes_written,
                data.len()
            );
        }

        let _ = EndPagePrinter(printer.0);
        let _ = EndDocPrinter(printer.0);
    }

    // ClosePrinter is called automatically by PrinterHandle::drop

    Ok(())
}
