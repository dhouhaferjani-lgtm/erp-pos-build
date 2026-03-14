pub mod display;
pub mod printing;

#[tauri::command]
pub fn greet(name: &str) -> String {
    format!("Hello, {}! Welcome to IziPOS.", name)
}
