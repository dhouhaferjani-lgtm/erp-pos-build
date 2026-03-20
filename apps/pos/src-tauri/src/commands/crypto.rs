use aes_gcm::{
    aead::{Aead, KeyInit, OsRng},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine};
use rand::RngCore;
use std::fs;
use std::path::PathBuf;

const KEY_FILE_NAME: &str = ".izipos_key";
const NONCE_SIZE: usize = 12;

fn get_key_path() -> Result<PathBuf, String> {
    let data_dir = dirs::data_local_dir()
        .ok_or_else(|| "Could not determine app data directory".to_string())?;
    let app_dir = data_dir.join("com.syneriva.izipos");
    Ok(app_dir.join(KEY_FILE_NAME))
}

fn get_or_create_key() -> Result<[u8; 32], String> {
    let key_path = get_key_path()?;

    if key_path.exists() {
        let key_bytes = fs::read(&key_path)
            .map_err(|e| format!("Failed to read encryption key: {}", e))?;
        if key_bytes.len() != 32 {
            return Err("Invalid encryption key length".to_string());
        }
        let mut key = [0u8; 32];
        key.copy_from_slice(&key_bytes);
        return Ok(key);
    }

    // Create parent directory if needed
    if let Some(parent) = key_path.parent() {
        fs::create_dir_all(parent)
            .map_err(|e| format!("Failed to create key directory: {}", e))?;
    }

    // Generate random 256-bit key
    let mut key = [0u8; 32];
    OsRng.fill_bytes(&mut key);

    fs::write(&key_path, &key)
        .map_err(|e| format!("Failed to write encryption key: {}", e))?;

    // Set restrictive permissions on Unix
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&key_path, fs::Permissions::from_mode(0o600))
            .map_err(|e| format!("Failed to set key file permissions: {}", e))?;
    }

    Ok(key)
}

#[tauri::command]
pub fn encrypt_secret(plaintext: String) -> Result<String, String> {
    let key_bytes = get_or_create_key()?;
    let cipher = Aes256Gcm::new_from_slice(&key_bytes)
        .map_err(|e| format!("Failed to create cipher: {}", e))?;

    // Generate random 12-byte nonce
    let mut nonce_bytes = [0u8; NONCE_SIZE];
    OsRng.fill_bytes(&mut nonce_bytes);
    let nonce = Nonce::from_slice(&nonce_bytes);

    let ciphertext = cipher
        .encrypt(nonce, plaintext.as_bytes())
        .map_err(|e| format!("Encryption failed: {}", e))?;

    // Combine nonce + ciphertext (tag is appended by aes-gcm)
    let mut combined = Vec::with_capacity(NONCE_SIZE + ciphertext.len());
    combined.extend_from_slice(&nonce_bytes);
    combined.extend_from_slice(&ciphertext);

    Ok(BASE64.encode(&combined))
}

#[tauri::command]
pub fn decrypt_secret(encrypted: String) -> Result<String, String> {
    let key_bytes = get_or_create_key()?;
    let cipher = Aes256Gcm::new_from_slice(&key_bytes)
        .map_err(|e| format!("Failed to create cipher: {}", e))?;

    let combined = BASE64
        .decode(&encrypted)
        .map_err(|e| format!("Base64 decode failed: {}", e))?;

    if combined.len() < NONCE_SIZE + 16 {
        // 16 bytes minimum for GCM tag
        return Err("Encrypted data too short".to_string());
    }

    let (nonce_bytes, ciphertext) = combined.split_at(NONCE_SIZE);
    let nonce = Nonce::from_slice(nonce_bytes);

    let plaintext = cipher
        .decrypt(nonce, ciphertext)
        .map_err(|_| "Decryption failed — token may be corrupted".to_string())?;

    String::from_utf8(plaintext).map_err(|e| format!("UTF-8 decode failed: {}", e))
}
