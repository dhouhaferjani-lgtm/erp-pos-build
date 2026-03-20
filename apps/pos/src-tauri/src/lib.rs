mod commands;
mod printing;

use tauri::Manager;

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_sql::Builder::new().build())
        .plugin(tauri_plugin_store::Builder::new().build())
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_os::init())
        .plugin(tauri_plugin_window_state::Builder::new().build())
        .plugin(tauri_plugin_log::Builder::new().build())
        .invoke_handler(tauri::generate_handler![
            commands::greet,
            commands::printing::discover_printers,
            commands::printing::print_receipt,
            commands::printing::print_test_page,
            commands::printing::open_cash_drawer,
            commands::display::list_monitors,
            commands::display::open_customer_display,
            commands::display::close_customer_display,
            commands::display::send_to_customer_display,
            commands::crypto::encrypt_secret,
            commands::crypto::decrypt_secret,
        ])
        .setup(|app| {
            let window = app.get_webview_window("main").unwrap();
            #[cfg(debug_assertions)]
            window.open_devtools();
            log::info!("IziPOS starting, window: {:?}", window.label());
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running IziPOS");
}
