use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Emitter, Manager, WebviewUrl, WebviewWindowBuilder};

/// Information about an available monitor.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct MonitorInfo {
    pub name: Option<String>,
    pub position: (i32, i32),
    pub size: (u32, u32),
    pub scale_factor: f64,
    pub is_primary: bool,
}

/// Payload sent to the customer display window via events.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type")]
pub enum CustomerDisplayPayload {
    #[serde(rename = "idle")]
    Idle { image_url: String },
    #[serde(rename = "cart")]
    Cart {
        items: Vec<CartDisplayItem>,
        total: String,
        currency: String,
    },
    #[serde(rename = "thank_you")]
    ThankYou {
        receipt_number: String,
        total: String,
        currency: String,
    },
}

/// A simplified cart item for the customer display.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CartDisplayItem {
    pub name: String,
    pub quantity: f64,
    pub quantity_decimals: Option<u8>,
    pub line_total: String,
}

const CUSTOMER_DISPLAY_LABEL: &str = "customer-display";

/// List all available monitors with their metadata.
#[tauri::command]
pub async fn list_monitors(app: AppHandle) -> Result<Vec<MonitorInfo>, String> {
    let monitors = app.available_monitors().map_err(|e| e.to_string())?;
    let primary = app.primary_monitor().map_err(|e| e.to_string())?;
    let primary_name = primary.as_ref().map(|m| m.name().map(|n| n.to_string()));

    let result: Vec<MonitorInfo> = monitors
        .iter()
        .map(|m| {
            let name = m.name().map(|n| n.to_string());
            let position = m.position();
            let size = m.size();
            let is_primary = match &primary_name {
                Some(Some(pn)) => name.as_deref() == Some(pn.as_str()),
                _ => false,
            };

            MonitorInfo {
                name,
                position: (position.x, position.y),
                size: (size.width, size.height),
                scale_factor: m.scale_factor(),
                is_primary,
            }
        })
        .collect();

    log::info!("Found {} monitors", result.len());
    Ok(result)
}

/// Open the customer-facing display window on the specified monitor.
/// If `monitor_index` is None, auto-selects the first non-primary monitor.
#[tauri::command]
pub async fn open_customer_display(
    app: AppHandle,
    monitor_index: Option<usize>,
) -> Result<(), String> {
    // If window already exists, just focus it
    if let Some(existing) = app.get_webview_window(CUSTOMER_DISPLAY_LABEL) {
        existing.set_focus().map_err(|e| e.to_string())?;
        log::info!("Customer display window already open, focused");
        return Ok(());
    }

    let monitors = app.available_monitors().map_err(|e| e.to_string())?;
    if monitors.is_empty() {
        return Err("No monitors available".to_string());
    }

    let primary = app.primary_monitor().map_err(|e| e.to_string())?;
    let primary_name = primary.as_ref().and_then(|m| m.name().map(|n| n.to_string()));

    // Select the target monitor
    let target = if let Some(idx) = monitor_index {
        monitors
            .get(idx)
            .ok_or_else(|| format!("Monitor index {} out of range (found {})", idx, monitors.len()))?
    } else {
        // Auto-detect: pick first non-primary monitor; error if none exists
        monitors
            .iter()
            .find(|m| {
                let name = m.name().map(|n| n.to_string());
                name != primary_name
            })
            .ok_or_else(|| "No secondary monitor found. Connect a second screen to use the customer display.".to_string())?
    };

    let position = target.position();
    let size = target.size();

    log::info!(
        "Opening customer display on monitor {:?} at ({}, {}) size {}x{}",
        target.name(),
        position.x,
        position.y,
        size.width,
        size.height,
    );

    let window = WebviewWindowBuilder::new(
        &app,
        CUSTOMER_DISPLAY_LABEL,
        WebviewUrl::App("/customer-display".into()),
    )
    .title("Customer Display")
    .position(position.x as f64, position.y as f64)
    .inner_size(size.width as f64, size.height as f64)
    .decorations(false)
    .always_on_top(true)
    .skip_taskbar(true)
    .resizable(false)
    .focused(false)
    .build()
    .map_err(|e| e.to_string())?;

    // Set fullscreen after creation
    window.set_fullscreen(true).map_err(|e| e.to_string())?;

    log::info!("Customer display window opened");
    Ok(())
}

/// Close the customer-facing display window.
#[tauri::command]
pub async fn close_customer_display(app: AppHandle) -> Result<(), String> {
    if let Some(window) = app.get_webview_window(CUSTOMER_DISPLAY_LABEL) {
        window.close().map_err(|e| e.to_string())?;
        log::info!("Customer display window closed");
    } else {
        log::info!("Customer display window not open, nothing to close");
    }
    Ok(())
}

/// Send a payload to the customer display window via Tauri events.
#[tauri::command]
pub async fn send_to_customer_display(
    app: AppHandle,
    payload: CustomerDisplayPayload,
) -> Result<(), String> {
    let window = app
        .get_webview_window(CUSTOMER_DISPLAY_LABEL)
        .ok_or_else(|| "Customer display window is not open".to_string())?;

    window
        .emit_to(CUSTOMER_DISPLAY_LABEL, "customer-display-update", &payload)
        .map_err(|e: tauri::Error| e.to_string())?;

    log::debug!("Sent payload to customer display: {:?}", payload);
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::CustomerDisplayPayload;

    #[test]
    fn cart_payload_round_trips_fractional_quantity_and_unit_precision() {
        let payload: CustomerDisplayPayload = serde_json::from_str(
            r#"{
                "type":"cart",
                "items":[{
                    "name":"Olive oil",
                    "quantity":1.5,
                    "quantity_decimals":2,
                    "line_total":"12.00"
                }],
                "total":"12.00",
                "currency":"EUR"
            }"#,
        )
        .expect("the customer-display command accepts fractional quantities");

        let emitted = serde_json::to_value(payload)
            .expect("the customer-display command emits its accepted payload");

        assert_eq!(emitted["items"][0]["quantity"], 1.5);
        assert_eq!(emitted["items"][0]["quantity_decimals"], 2);
    }
}
