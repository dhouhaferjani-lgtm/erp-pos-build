//! Single-connection SQLite writer.
//!
//! The `tauri-plugin-sql` pool is unsound for JS-issued multi-statement
//! transactions (statements split across pooled connections). All offline
//! writes that need transactional atomicity run on THIS one long-lived
//! connection, serialized by the JS write gate + the state mutex below.
//! Errors are returned as plain strings to match the plugin's failure
//! surface (the JS layer's string-error handling stays truthful).

use serde_json::Value as JsonValue;
use sqlx::sqlite::{SqliteConnectOptions, SqliteJournalMode, SqliteSynchronous};
use sqlx::{Column, ConnectOptions, Connection, Executor, Row, SqliteConnection, TypeInfo, Value, ValueRef};
use std::collections::HashMap;
use std::time::Duration;
use tauri::{command, AppHandle, Manager, Runtime, State};
use tokio::sync::Mutex;

#[derive(Default)]
pub struct WriterState(pub Mutex<HashMap<String, SqliteConnection>>);

#[derive(serde::Serialize)]
pub struct ExecResult {
    #[serde(rename = "rowsAffected")]
    pub rows_affected: u64,
    #[serde(rename = "lastInsertId")]
    pub last_insert_id: i64,
}

fn bind_values<'q>(
    mut query: sqlx::query::Query<'q, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'q>>,
    values: Vec<JsonValue>,
) -> sqlx::query::Query<'q, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'q>> {
    // Mirrors tauri-plugin-sql's binding exactly (wrapper.rs) so behavior is
    // identical to the read pool: null, string, number-as-f64, json fallback.
    for value in values {
        if value.is_null() {
            query = query.bind(None::<JsonValue>);
        } else if value.is_string() {
            query = query.bind(value.as_str().unwrap().to_owned());
        } else if let Some(number) = value.as_number() {
            query = query.bind(number.as_f64().unwrap_or_default());
        } else {
            query = query.bind(value);
        }
    }
    query
}

fn value_to_json(v: sqlx::sqlite::SqliteValueRef<'_>) -> JsonValue {
    if v.is_null() {
        return JsonValue::Null;
    }
    // DATE/TIME/DATETIME columns store TEXT in this schema — decode as String
    // (the plugin decodes via the `time` crate then stringifies; same output).
    match v.type_info().name() {
        "REAL" => v
            .to_owned()
            .try_decode::<f64>()
            .map(JsonValue::from)
            .unwrap_or(JsonValue::Null),
        "INTEGER" | "NUMERIC" => v
            .to_owned()
            .try_decode::<i64>()
            .map(|n| JsonValue::Number(n.into()))
            .unwrap_or(JsonValue::Null),
        "BOOLEAN" => v
            .to_owned()
            .try_decode::<bool>()
            .map(JsonValue::Bool)
            .unwrap_or(JsonValue::Null),
        "BLOB" => v
            .to_owned()
            .try_decode::<Vec<u8>>()
            .map(|b| JsonValue::Array(b.into_iter().map(|n| JsonValue::Number(n.into())).collect()))
            .unwrap_or(JsonValue::Null),
        _ => v
            .to_owned()
            .try_decode::<String>()
            .map(JsonValue::String)
            .unwrap_or(JsonValue::Null),
    }
}

async fn open_connection(path: std::path::PathBuf) -> Result<SqliteConnection, String> {
    SqliteConnectOptions::new()
        .filename(path)
        .create_if_missing(true)
        .journal_mode(SqliteJournalMode::Wal)
        .synchronous(SqliteSynchronous::Normal)
        .busy_timeout(Duration::from_secs(5))
        .foreign_keys(true)
        .connect()
        .await
        .map_err(|e| e.to_string())
}

/// Open (or re-open) the single writer connection for `db` (a bare file name
/// like `izipos-<companyId>.db`, resolved against the app config dir — the
/// SAME directory the SQL plugin uses, so both layers address one file).
#[command]
pub async fn writer_open<R: Runtime>(
    app: AppHandle<R>,
    state: State<'_, WriterState>,
    db: String,
) -> Result<(), String> {
    let app_path = app
        .path()
        .app_config_dir()
        .map_err(|e| format!("no app config dir: {e}"))?;
    std::fs::create_dir_all(&app_path).map_err(|e| format!("cannot create app config dir: {e}"))?;
    let conn = open_connection(app_path.join(&db)).await?;

    let mut map = state.0.lock().await;
    if let Some(old) = map.remove(&db) {
        let _ = old.close().await;
    }
    map.insert(db, conn);
    Ok(())
}

#[command]
pub async fn writer_execute(
    state: State<'_, WriterState>,
    db: String,
    sql: String,
    values: Vec<JsonValue>,
) -> Result<ExecResult, String> {
    let mut map = state.0.lock().await;
    let conn = map
        .get_mut(&db)
        .ok_or_else(|| format!("writer not open: {db}"))?;
    let query = bind_values(sqlx::query(&sql), values);
    let result = conn.execute(query).await.map_err(|e| e.to_string())?;
    Ok(ExecResult {
        rows_affected: result.rows_affected(),
        last_insert_id: result.last_insert_rowid(),
    })
}

#[command]
pub async fn writer_select(
    state: State<'_, WriterState>,
    db: String,
    sql: String,
    values: Vec<JsonValue>,
) -> Result<Vec<serde_json::Map<String, JsonValue>>, String> {
    let mut map = state.0.lock().await;
    let conn = map
        .get_mut(&db)
        .ok_or_else(|| format!("writer not open: {db}"))?;
    let query = bind_values(sqlx::query(&sql), values);
    let rows = conn.fetch_all(query).await.map_err(|e| e.to_string())?;
    let mut out = Vec::with_capacity(rows.len());
    for row in rows {
        let mut obj = serde_json::Map::new();
        for (i, column) in row.columns().iter().enumerate() {
            let raw = row.try_get_raw(i).map_err(|e| e.to_string())?;
            obj.insert(column.name().to_string(), value_to_json(raw));
        }
        out.push(obj);
    }
    Ok(out)
}

#[command]
pub async fn writer_close(state: State<'_, WriterState>, db: String) -> Result<(), String> {
    let mut map = state.0.lock().await;
    if let Some(conn) = map.remove(&db) {
        conn.close().await.map_err(|e| e.to_string())?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    async fn test_conn() -> SqliteConnection {
        SqliteConnectOptions::new()
            .in_memory(true)
            .connect()
            .await
            .expect("in-memory sqlite")
    }

    #[tokio::test]
    async fn bind_and_decode_roundtrip() {
        let mut conn = test_conn().await;
        conn.execute("CREATE TABLE t (a TEXT, b INTEGER, c REAL, d TEXT)")
            .await
            .unwrap();
        let q = bind_values(
            sqlx::query("INSERT INTO t (a, b, c, d) VALUES ($1, $2, $3, $4)"),
            vec![
                JsonValue::String("12.345".into()), // money stays a string
                JsonValue::Number(7.into()),
                JsonValue::from(1.5),
                JsonValue::Null,
            ],
        );
        conn.execute(q).await.unwrap();

        let rows = conn
            .fetch_all(sqlx::query("SELECT a, b, c, d FROM t"))
            .await
            .unwrap();
        let row = &rows[0];
        let mut obj = serde_json::Map::new();
        for (i, column) in row.columns().iter().enumerate() {
            obj.insert(
                column.name().to_string(),
                value_to_json(row.try_get_raw(i).unwrap()),
            );
        }
        assert_eq!(obj.get("a"), Some(&JsonValue::String("12.345".into())));
        // numbers bind as f64 (plugin parity) → INTEGER column affinity stores 7
        assert_eq!(obj.get("b"), Some(&JsonValue::Number(7.into())));
        assert_eq!(obj.get("c"), Some(&JsonValue::from(1.5)));
        assert_eq!(obj.get("d"), Some(&JsonValue::Null));
    }

    #[tokio::test]
    async fn open_connection_applies_wal_and_busy_timeout() {
        let dir = std::env::temp_dir().join(format!("pos-writer-test-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let mut conn = open_connection(dir.join("t.db")).await.unwrap();
        let rows = conn
            .fetch_all(sqlx::query("PRAGMA journal_mode"))
            .await
            .unwrap();
        let mode = value_to_json(rows[0].try_get_raw(0).unwrap());
        assert_eq!(mode, JsonValue::String("wal".into()));
        let rows = conn
            .fetch_all(sqlx::query("PRAGMA busy_timeout"))
            .await
            .unwrap();
        let timeout = value_to_json(rows[0].try_get_raw(0).unwrap());
        assert_eq!(timeout, JsonValue::Number(5000.into()));
        let _ = std::fs::remove_dir_all(&dir);
    }
}
