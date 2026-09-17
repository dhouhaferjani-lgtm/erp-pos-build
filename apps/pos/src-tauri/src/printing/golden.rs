//! Golden-bytes regression harness for the printed POS ticket (Lane E).
//!
//! # Why this exists
//!
//! Four printed-ticket defects are about to be fixed on
//! [`super::receipt_template::format_receipt_with_settings`]:
//!
//! | Registry row | Defect | Expected byte-level effect |
//! |---|---|---|
//! | DEV-QA-092 | the matricule fiscal is printed twice (`MF :` then `N° TVA :` with the same value) | ONE text line disappears |
//! | DEV-QA-093 | the footer carries an unlabelled compliance-only QR of the raw fiscal hash | ONE `GS ( k` QR block disappears, ONE caption line appears above the workflow QR |
//! | DEV-QA-094 | `ESC t` is never emitted for `cp437`, and the declared code page and the transcoding disagree | the `ESC t n` prologue token appears / changes |
//! | DEV-QA-095 | `TND` is concatenated LEFT of the amount with no separator | money lines are re-laid-out, same content |
//!
//! Every OTHER byte of the ticket must stay identical. This harness captures the
//! CURRENT (unmodified) output into committed goldens and, once the fixes land,
//! fails on any diff that is not one of the five declared whitelist classes in
//! [`WhitelistClass`].
//!
//! # Running it
//!
//! ```text
//! # compare against the committed goldens (the default; this is the gate)
//! cd apps/pos/src-tauri && cargo test --lib printing::golden
//!
//! # (re)capture the six goldens from the CURRENT template output
//! cd apps/pos/src-tauri && GOLDEN_WRITE=1 cargo test --lib printing::golden -- --nocapture
//! ```
//!
//! `GOLDEN_WRITE=1` overwrites `tests/golden/{sale,refund,z}-{42,32}.bin`. Recapture
//! ONLY to establish a new baseline after a diff has been reviewed and accepted —
//! never to make a red run go green.
//!
//! # How the comparison works
//!
//! Raw ESC/POS is not diffable as text: a one-character change to a label shifts
//! the padding of a whole `two_column` row. So both byte streams are decoded into
//! a sequence of [`Item`]s — text lines (CP1252-decoded, split on `LF`) and the
//! control tokens the template actually emits (`ESC @`, `ESC a`, `ESC E`, `ESC -`,
//! `ESC M`, `ESC t`, `ESC d`, `GS !`, `GS V`, and the five-block `GS ( k` QR
//! sequence collapsed into one item carrying its payload). The two sequences are
//! LCS-diffed and every non-equal item must be explained by a whitelist class.
//!
//! # Fixture
//!
//! One TN café ticket (`Café Nour`, TND / 3 decimals, French labels copied from
//! `apps/pos/src/locales/fr/pos.json` `receiptLabel.*`), in three shapes × two
//! paper widths (42 cols = 80 mm, 32 cols = 58 mm). Money strings are
//! pre-formatted exactly as the TS builder emits them (`buildReceiptData.ts`) —
//! rule 19: no float ever touches money, and Rust never parses these strings.
//!
//! The Z shape is built the way `apps/pos/src/lib/printing.ts:251-296`
//! `buildZReceiptData` builds it — there is no `receipt_kind` discriminator for a
//! Z ticket: it is the default kind with `cash_counts` set, every `show_*` flag
//! off and zeroed totals. Keeping it faithful is the point; a Z fixture that
//! looked like a sale would guard nothing on the real Z path.

use std::path::PathBuf;

use super::escpos::{CutMode, EscPosBuilder, QrErrorCorrection, TextEncoding};
use super::receipt_template::{
    format_receipt_with_settings, CompanyInfo, PaymentLine, PrintSettings, ReceiptData,
    ReceiptLabels, ReceiptLine, VatBreakdownLine, ZReceiptCashCountRow,
};

// ───────────────────────────── fixture constants ─────────────────────────────

/// Tunisian matricule fiscal. DEV-QA-092: the template prints it twice because
/// `tax_id` and `vat_number` carry the SAME value for a TN establishment.
const TAX_ID: &str = "1234567/A/M/000";
/// The `N° TVA` label (without its trailing colon) — the second half of the
/// class-1 predicate, so that removing an unrelated line carrying the matricule
/// (e.g. `MF :`) is NOT silently whitelisted.
const VAT_LABEL: &str = "N° TVA";
/// 64 hex chars, as a real SHA-256 fiscal hash. DEV-QA-093: this exact string is
/// the payload of the QR the fix removes.
const FISCAL_HASH: &str = "3f7a1c9e08b542d6a15c7e93b0d4f28671ac35e9d8420fb6c7e1539a04d8b2c6";
const FISCAL_SIGNATURE: &str = "SIG-2026-09-17-AB12CD34EF56";
/// Workflow QR token (`v:kid:receipt_uuid:mac`) — the QR that SURVIVES the fix
/// and gains a printed caption (class 5).
const QR_TOKEN: &str = "1:kid:uuid:mac";
const ORIGINAL_QR_TOKEN: &str = "1:kid:uuid-original:mac";
/// DEV-QA-095: `getCurrencySymbol()` resolves TND with a hardcoded `'en'` locale,
/// so the "symbol" is the ISO code.
const CURRENCY: &str = "TND";

fn whitelist() -> Whitelist<'static> {
    Whitelist {
        vat_number: TAX_ID,
        vat_label: VAT_LABEL,
        fiscal_hash: FISCAL_HASH,
        qr_token: QR_TOKEN,
        currency: CURRENCY,
    }
}

// ───────────────────────────────── fixtures ──────────────────────────────────

/// French labels, copied verbatim from `apps/pos/src/locales/fr/pos.json`
/// (`receiptLabel.*` and `cash_count.*`) as assembled by
/// `apps/pos/src/lib/buildReceiptData.ts:557-606` `buildReceiptLabels()`.
fn fr_labels() -> ReceiptLabels {
    ReceiptLabels {
        receipt: Some("Reçu :".to_string()),
        date: Some("Date :".to_string()),
        terminal: Some("Terminal :".to_string()),
        operator: Some("Opérateur :".to_string()),
        customer: Some("Client :".to_string()),
        item: Some("Article".to_string()),
        qty: Some("Qté".to_string()),
        amount: Some("Montant".to_string()),
        subtotal: Some("Sous-total :".to_string()),
        discount: Some("Remise :".to_string()),
        tax: Some("TVA :".to_string()),
        total: Some("TOTAL :".to_string()),
        payments: Some("Paiements :".to_string()),
        change_due: Some("Monnaie Rendue :".to_string()),
        rounding: Some("Arrondi".to_string()),
        tolerance: Some("Écart accepté".to_string()),
        vat_rate: Some("TVA %".to_string()),
        taxable: Some("Base HT".to_string()),
        tax_col: Some("TVA".to_string()),
        thank_you: Some("Merci pour votre achat !".to_string()),
        tax_id: Some("MF :".to_string()),
        vat_number: Some("N° TVA :".to_string()),
        tel: Some("Tél :".to_string()),
        cash_count_section_title: Some("Réconciliation de caisse".to_string()),
        cash_count_total_variance: Some("Écart".to_string()),
        cash_count_approved_by: Some("Autorisé par :".to_string()),
        cash_count_reason: Some("Motif de l'écart (requis)".to_string()),
        cash_count_col_tender: Some("Mode de paiement".to_string()),
        cash_count_col_expected: Some("Attendu".to_string()),
        cash_count_col_actual: Some("Compté".to_string()),
        cash_count_col_variance: Some("Écart".to_string()),
        refund_header: Some("REMBOURSEMENT".to_string()),
        original_ticket: Some("Ticket original :".to_string()),
        original_qr_label: Some("Scanner le ticket original :".to_string()),
        account_payment_header: Some("RECU D'ENCAISSEMENT".to_string()),
        balance_before: Some("Solde avant :".to_string()),
        balance_after: Some("Solde apres :".to_string()),
        stale_balance: Some("Solde non actualise".to_string()),
        business_date: Some("Date fiscale :".to_string()),
        terminal_id: Some("ID terminal :".to_string()),
        shift_id: Some("ID service :".to_string()),
        training: Some("FORMATION".to_string()),
        customer_account: Some("Compte :".to_string()),
        customer_phone: Some("Telephone client :".to_string()),
    }
}

/// TN establishment whose `tax_id` and `vat_number` are EQUAL — the DEV-QA-092
/// condition (`buildReceiptData.ts:195,197`, `sellerIdentity.ts:120`).
fn cafe_nour() -> CompanyInfo {
    CompanyInfo {
        name: "Café Nour".to_string(),
        address_line1: "12 Avenue Habib Bourguiba".to_string(),
        address_line2: None,
        city: "Tunis".to_string(),
        postal_code: "1001".to_string(),
        country: "TN".to_string(),
        tax_id: TAX_ID.to_string(),
        phone: Some("+216 71 000 000".to_string()),
        vat_number: Some(TAX_ID.to_string()),
        legal_identifier_lines: None,
    }
}

/// Sale ticket.
///
/// Arithmetic (all strings, TND scale 3 — the ticket's own guarantee since D-1 is
/// `Sous-total (TTC) − Remise == TOTAL`):
/// `9.000 + 2.000 = 11.000` gross, remise `1.000`, `TOTAL 10.000`;
/// the ventilation carries the POST-remise bases `7.647 + 0.535` and
/// `1.528 + 0.290`, i.e. `9.175 + 0.825 = 10.000`.
fn sale_fixture() -> ReceiptData {
    ReceiptData {
        company: cafe_nour(),
        receipt_number: "TRM1-2026-000042".to_string(),
        date_time: "17/09/2026 09:32:14".to_string(),
        terminal_name: "Caisse 1".to_string(),
        operator_name: "Amine B.".to_string(),
        lines: vec![
            ReceiptLine {
                name: "Café crème".to_string(),
                quantity: "2".to_string(),
                unit_price: "4.500".to_string(),
                line_total: "9.000".to_string(),
                modifiers: None,
                discount: None,
            },
            ReceiptLine {
                name: "Garçon".to_string(),
                quantity: "1".to_string(),
                unit_price: "2.000".to_string(),
                line_total: "2.000".to_string(),
                modifiers: None,
                discount: None,
            },
        ],
        subtotal: "11.000".to_string(),
        discount_amount: "1.000".to_string(),
        tax_amount: "0.825".to_string(),
        total: "10.000".to_string(),
        currency_symbol: CURRENCY.to_string(),
        vat_breakdown: vec![
            VatBreakdownLine {
                rate: "7".to_string(),
                taxable: "7.647".to_string(),
                tax: "0.535".to_string(),
            },
            VatBreakdownLine {
                rate: "19".to_string(),
                taxable: "1.528".to_string(),
                tax: "0.290".to_string(),
            },
        ],
        payments: vec![PaymentLine {
            method: "Espèces".to_string(),
            amount: "10.000".to_string(),
        }],
        // `buildEscPosReceiptData` emits `(0).toFixed(decimals)` when no change is
        // due, i.e. "0.000" for TND — which is neither "0.00" nor "0", so the
        // template's skip test at receipt_template.rs:700 does NOT fire and a
        // zero change line prints. Faithful on purpose: the golden must carry it.
        change_due: "0.000".to_string(),
        tolerance_writeoff: None,
        has_tolerance: false,
        cash_rounding_adjustment: None,
        has_cash_rounding: Some(false),
        fiscal_hash: Some(FISCAL_HASH.to_string()),
        fiscal_signature: Some(FISCAL_SIGNATURE.to_string()),
        customer_name: Some("Sonia Ben Ali".to_string()),
        notes: None,
        labels: Some(fr_labels()),
        show_vat_breakdown: Some(true),
        show_fiscal_info: Some(true),
        show_payment_details: Some(true),
        show_customer: Some(true),
        is_reprint: Some(false),
        cash_counts: None,
        manager_name: None,
        variance_reason: None,
        variance_severity: None,
        aggregate_variance: None,
        qr_token: Some(QR_TOKEN.to_string()),
        receipt_kind: Some("sale".to_string()),
        original_receipt_number: None,
        original_receipt_qr_token: None,
        account_balance_before: None,
        account_balance_after: None,
        account_snapshot_stale: false,
        business_date: None,
        terminal_id: None,
        shift_id: None,
        training_flag: false,
        customer_account_id: None,
        customer_phone: None,
    }
}

/// Refund ticket: the sale fixture with the refund discriminator, the original
/// ticket number and the original QR token, so the REMBOURSEMENT banner and the
/// original-ticket QR (`receipt_template.rs:493-502`) are emitted.
fn refund_fixture() -> ReceiptData {
    ReceiptData {
        receipt_number: "TRM1-2026-000043".to_string(),
        receipt_kind: Some("refund".to_string()),
        original_receipt_number: Some("TRM1-2026-000042".to_string()),
        original_receipt_qr_token: Some(ORIGINAL_QR_TOKEN.to_string()),
        ..sale_fixture()
    }
}

/// Z ticket, shaped exactly like `buildZReceiptData` (`printing.ts:251-296`):
/// header identity only, zeroed totals at the builder's literal 2-decimal scale,
/// every `show_*` flag off, and the per-tender cash-count block.
fn z_fixture() -> ReceiptData {
    ReceiptData {
        company: CompanyInfo {
            name: "Café Nour".to_string(),
            address_line1: String::new(),
            address_line2: None,
            city: String::new(),
            postal_code: String::new(),
            country: String::new(),
            tax_id: TAX_ID.to_string(),
            phone: None,
            vat_number: Some(TAX_ID.to_string()),
            legal_identifier_lines: None,
        },
        receipt_number: "Z-2026-000007".to_string(),
        date_time: "17/09/2026 23:58:02".to_string(),
        terminal_name: "Caisse 1".to_string(),
        operator_name: "Amine B.".to_string(),
        lines: Vec::new(),
        subtotal: "0.00".to_string(),
        discount_amount: "0.00".to_string(),
        tax_amount: "0.00".to_string(),
        total: "0.00".to_string(),
        currency_symbol: CURRENCY.to_string(),
        vat_breakdown: Vec::new(),
        payments: Vec::new(),
        change_due: "0.00".to_string(),
        tolerance_writeoff: None,
        has_tolerance: false,
        cash_rounding_adjustment: None,
        has_cash_rounding: Some(false),
        fiscal_hash: None,
        fiscal_signature: None,
        customer_name: None,
        notes: None,
        labels: Some(fr_labels()),
        show_vat_breakdown: Some(false),
        show_fiscal_info: Some(false),
        show_payment_details: Some(false),
        show_customer: Some(false),
        is_reprint: Some(false),
        cash_counts: Some(vec![
            ZReceiptCashCountRow {
                code: "cash".to_string(),
                name: "Espèces".to_string(),
                expected: "310.000".to_string(),
                actual: "308.500".to_string(),
                variance: "1.500".to_string(),
                direction: "under".to_string(),
            },
            ZReceiptCashCountRow {
                code: "card".to_string(),
                name: "Carte".to_string(),
                expected: "120.000".to_string(),
                actual: "120.000".to_string(),
                variance: "0.000".to_string(),
                direction: "equal".to_string(),
            },
        ]),
        manager_name: Some("Leïla T.".to_string()),
        variance_reason: Some("Erreur de rendu monnaie".to_string()),
        variance_severity: Some("minor".to_string()),
        aggregate_variance: Some("1.500".to_string()),
        qr_token: None,
        receipt_kind: None,
        original_receipt_number: None,
        original_receipt_qr_token: None,
        account_balance_before: None,
        account_balance_after: None,
        account_snapshot_stale: false,
        business_date: None,
        terminal_id: None,
        shift_id: None,
        training_flag: false,
        customer_account_id: None,
        customer_phone: None,
    }
}

/// Device defaults (`apps/pos/src/stores/printerStore.ts:30-36`) at the two paper
/// widths mapped by `apps/pos/src/lib/printing.ts:436` (80 mm → 42, 58 mm → 32).
fn settings(columns: u8) -> PrintSettings {
    PrintSettings {
        columns,
        cut_mode: "partial".to_string(),
        encoding: "cp1252".to_string(),
        footer_text: String::new(),
        copies: 1,
    }
}

// ──────────────────────────── ESC/POS item model ─────────────────────────────

/// One decoded unit of the printed stream: either a text line or a control token.
#[derive(Debug, Clone, PartialEq, Eq)]
enum Item {
    /// A text line, CP1252-decoded, WITHOUT its terminating `LF`.
    Text(String),
    /// `ESC @` — initialize.
    Init,
    /// `ESC a n` — alignment.
    Align(u8),
    /// `ESC E n` — bold.
    Bold(u8),
    /// `ESC - n` — underline.
    Underline(u8),
    /// `GS ! n` — character size.
    Size(u8),
    /// `ESC M n` — font A/B.
    Font(u8),
    /// `ESC t n` — code page (DEV-QA-094).
    CodePage(u8),
    /// `ESC d n` — print and feed n lines.
    Feed(u8),
    /// `GS V m` — cut.
    Cut(u8),
    /// The whole `GS ( k` five-block QR sequence, collapsed.
    Qr {
        module: u8,
        ec: u8,
        payload: String,
    },
    /// Any other control byte run, kept verbatim so nothing is silently dropped.
    Raw(Vec<u8>),
}

fn decode_cp1252(bytes: &[u8]) -> String {
    let (cow, _, _) = encoding_rs::WINDOWS_1252.decode(bytes);
    cow.into_owned()
}

fn encode_cp1252(s: &str) -> Vec<u8> {
    let (cow, _, _) = encoding_rs::WINDOWS_1252.encode(s);
    cow.into_owned()
}

/// Decode a raw ESC/POS buffer into the item sequence used for diffing.
fn parse(bytes: &[u8]) -> Vec<Item> {
    let mut items: Vec<Item> = Vec::new();
    let mut pending: Vec<u8> = Vec::new();
    let mut qr_module: u8 = 0;
    let mut qr_ec: u8 = 0;
    let mut qr_payload: Vec<u8> = Vec::new();
    let mut i = 0usize;

    while i < bytes.len() {
        match bytes[i] {
            0x0A => {
                items.push(Item::Text(decode_cp1252(&pending)));
                pending.clear();
                i += 1;
            }
            0x1B | 0x1D => {
                if !pending.is_empty() {
                    items.push(Item::Text(decode_cp1252(&pending)));
                    pending.clear();
                }
                let (item, consumed) =
                    parse_control(bytes, i, &mut qr_module, &mut qr_ec, &mut qr_payload);
                if let Some(item) = item {
                    items.push(item);
                }
                i += consumed;
            }
            other => {
                pending.push(other);
                i += 1;
            }
        }
    }
    if !pending.is_empty() {
        items.push(Item::Text(decode_cp1252(&pending)));
    }
    items
}

/// Parse one control sequence at `i`. Returns the item (QR sub-blocks return
/// `None` until the print block closes them) and how many bytes it consumed.
fn parse_control(
    bytes: &[u8],
    i: usize,
    qr_module: &mut u8,
    qr_ec: &mut u8,
    qr_payload: &mut Vec<u8>,
) -> (Option<Item>, usize) {
    let at = |off: usize| bytes.get(i + off).copied();
    let raw1 = || (Some(Item::Raw(vec![bytes[i]])), 1usize);

    match (bytes[i], at(1)) {
        // ESC @
        (0x1B, Some(0x40)) => (Some(Item::Init), 2),
        // ESC a n / ESC E n / ESC - n / ESC M n / ESC t n / ESC d n
        (0x1B, Some(0x61)) => match at(2) {
            Some(n) => (Some(Item::Align(n)), 3),
            None => raw1(),
        },
        (0x1B, Some(0x45)) => match at(2) {
            Some(n) => (Some(Item::Bold(n)), 3),
            None => raw1(),
        },
        (0x1B, Some(0x2D)) => match at(2) {
            Some(n) => (Some(Item::Underline(n)), 3),
            None => raw1(),
        },
        (0x1B, Some(0x4D)) => match at(2) {
            Some(n) => (Some(Item::Font(n)), 3),
            None => raw1(),
        },
        (0x1B, Some(0x74)) => match at(2) {
            Some(n) => (Some(Item::CodePage(n)), 3),
            None => raw1(),
        },
        (0x1B, Some(0x64)) => match at(2) {
            Some(n) => (Some(Item::Feed(n)), 3),
            None => raw1(),
        },
        // ESC p m t1 t2 — drawer kick
        (0x1B, Some(0x70)) if i + 5 <= bytes.len() => {
            (Some(Item::Raw(bytes[i..i + 5].to_vec())), 5)
        }
        // GS ! n
        (0x1D, Some(0x21)) => match at(2) {
            Some(n) => (Some(Item::Size(n)), 3),
            None => raw1(),
        },
        // GS V m
        (0x1D, Some(0x56)) => match at(2) {
            Some(m) => (Some(Item::Cut(m)), 3),
            None => raw1(),
        },
        // GS ( k pL pH cn fn …
        (0x1D, Some(0x28)) if at(2) == Some(0x6B) => {
            let (pl, ph) = match (at(3), at(4)) {
                (Some(pl), Some(ph)) => (pl as usize, ph as usize),
                _ => return raw1(),
            };
            let params = pl + (ph << 8);
            let end = i + 5 + params;
            if params < 2 || end > bytes.len() {
                return raw1();
            }
            let func = bytes[i + 6];
            let consumed = 5 + params;
            match func {
                // fn 167 — module size
                0x43 => {
                    if let Some(n) = at(7) {
                        *qr_module = n;
                    }
                    (None, consumed)
                }
                // fn 169 — error correction
                0x45 => {
                    if let Some(n) = at(7) {
                        *qr_ec = n;
                    }
                    (None, consumed)
                }
                // fn 180 — store data (cn, fn, m, then the payload)
                0x50 => {
                    if params < 3 {
                        return raw1();
                    }
                    *qr_payload = bytes[i + 8..end].to_vec();
                    (None, consumed)
                }
                // fn 181 — print the stored symbol
                0x51 => {
                    let item = Item::Qr {
                        module: *qr_module,
                        ec: *qr_ec,
                        payload: decode_cp1252(qr_payload),
                    };
                    qr_payload.clear();
                    (Some(item), consumed)
                }
                // fn 165 (select model) and anything else: structural, not compared
                _ => (None, consumed),
            }
        }
        // GS H n / GS h n / GS w n
        (0x1D, Some(0x48)) | (0x1D, Some(0x68)) | (0x1D, Some(0x77)) if i + 3 <= bytes.len() => {
            (Some(Item::Raw(bytes[i..i + 3].to_vec())), 3)
        }
        _ => raw1(),
    }
}

/// Re-encode an item to the bytes the printer would have received (used for the
/// hexdump in a failure message).
fn item_bytes(item: &Item) -> Vec<u8> {
    match item {
        Item::Text(s) => {
            let mut v = encode_cp1252(s);
            v.push(0x0A);
            v
        }
        Item::Init => vec![0x1B, 0x40],
        Item::Align(n) => vec![0x1B, 0x61, *n],
        Item::Bold(n) => vec![0x1B, 0x45, *n],
        Item::Underline(n) => vec![0x1B, 0x2D, *n],
        Item::Size(n) => vec![0x1D, 0x21, *n],
        Item::Font(n) => vec![0x1B, 0x4D, *n],
        Item::CodePage(n) => vec![0x1B, 0x74, *n],
        Item::Feed(n) => vec![0x1B, 0x64, *n],
        Item::Cut(m) => vec![0x1D, 0x56, *m],
        Item::Qr {
            module,
            ec,
            payload,
        } => {
            let data = payload.as_bytes();
            let store_len = (data.len() + 3) as u16;
            let mut v = vec![
                0x1D,
                0x28,
                0x6B,
                4,
                0,
                0x31,
                0x41,
                50,
                0,
                0x1D,
                0x28,
                0x6B,
                3,
                0,
                0x31,
                0x43,
                *module,
                0x1D,
                0x28,
                0x6B,
                3,
                0,
                0x31,
                0x45,
                *ec,
                0x1D,
                0x28,
                0x6B,
                (store_len & 0xFF) as u8,
                ((store_len >> 8) & 0xFF) as u8,
                0x31,
                0x50,
                0x30,
            ];
            v.extend_from_slice(data);
            v.extend_from_slice(&[0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x51, 0x30]);
            v
        }
        Item::Raw(v) => v.clone(),
    }
}

fn describe(item: &Item) -> String {
    match item {
        Item::Text(s) => format!("text {:?}", s),
        Item::Qr {
            module,
            ec,
            payload,
        } => format!("QR(module={module}, ec={ec}) payload {:?}", payload),
        other => format!("{:?}", other),
    }
}

fn hexdump(bytes: &[u8]) -> String {
    const MAX: usize = 96;
    let shown: Vec<String> = bytes
        .iter()
        .take(MAX)
        .map(|b| format!("{:02X}", b))
        .collect();
    if bytes.len() > MAX {
        format!("{} … (+{} bytes)", shown.join(" "), bytes.len() - MAX)
    } else {
        shown.join(" ")
    }
}

// ─────────────────────────────── whitelist ───────────────────────────────────

/// The five — and only five — differences the printed-ticket fixes may cause.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum WhitelistClass {
    /// DEV-QA-092 — a removed text line carrying BOTH the matricule value and
    /// the `N° TVA` label.
    VatDuplicateLineRemoved,
    /// DEV-QA-093 — a removed `GS ( k` QR block whose payload is the fiscal hash.
    FiscalHashQrRemoved,
    /// DEV-QA-094 — an `ESC t n` prologue token added or changed.
    CodePagePrologue,
    /// DEV-QA-095 — a re-laid-out money line: identical once currency placement
    /// is normalised and space runs are collapsed.
    CurrencyPlacement,
    /// DEV-QA-093 — one added text line immediately before the workflow QR block.
    QrCaptionAdded,
}

struct Whitelist<'a> {
    vat_number: &'a str,
    vat_label: &'a str,
    fiscal_hash: &'a str,
    qr_token: &'a str,
    currency: &'a str,
}

/// `TND11.000` ↔ `11.000 TND`, then collapse every run of whitespace.
///
/// Deliberately permissive about padding: moving the currency code to the right
/// of the amount re-pads the whole `two_column` row, and that re-padding is the
/// expected consequence of DEV-QA-095, not a separate change.
fn normalize_money(s: &str, currency: &str) -> String {
    let chars: Vec<char> = s.chars().collect();
    let cur: Vec<char> = currency.chars().collect();
    let mut out = String::with_capacity(s.len() + 8);
    let mut i = 0usize;

    while i < chars.len() {
        if !cur.is_empty() && i + cur.len() <= chars.len() && chars[i..i + cur.len()] == cur[..] {
            let after = i + cur.len();
            let mut j = after;
            while j < chars.len()
                && (chars[j].is_ascii_digit() || chars[j] == '.' || chars[j] == ',')
            {
                j += 1;
            }
            if j > after {
                let amount: String = chars[after..j].iter().collect();
                out.push_str(&amount);
                out.push(' ');
                out.push_str(currency);
                i = j;
                continue;
            }
        }
        out.push(chars[i]);
        i += 1;
    }

    out.split_whitespace().collect::<Vec<&str>>().join(" ")
}

#[derive(Debug, Clone, Copy)]
enum Op {
    Equal,
    Del(usize),
    Ins(usize),
}

/// LCS diff over the item sequences. Small inputs (a few hundred items), so the
/// quadratic table is cheap and the result is stable and readable.
fn diff_items(a: &[Item], b: &[Item]) -> Vec<Op> {
    let (n, m) = (a.len(), b.len());
    let stride = m + 1;
    let mut dp = vec![0usize; (n + 1) * stride];

    for i in (0..n).rev() {
        for j in (0..m).rev() {
            dp[i * stride + j] = if a[i] == b[j] {
                dp[(i + 1) * stride + j + 1] + 1
            } else {
                dp[(i + 1) * stride + j].max(dp[i * stride + j + 1])
            };
        }
    }

    let mut ops = Vec::new();
    let (mut i, mut j) = (0usize, 0usize);
    while i < n && j < m {
        if a[i] == b[j] {
            ops.push(Op::Equal);
            i += 1;
            j += 1;
        } else if dp[(i + 1) * stride + j] >= dp[i * stride + j + 1] {
            ops.push(Op::Del(i));
            i += 1;
        } else {
            ops.push(Op::Ins(j));
            j += 1;
        }
    }
    while i < n {
        ops.push(Op::Del(i));
        i += 1;
    }
    while j < m {
        ops.push(Op::Ins(j));
        j += 1;
    }
    ops
}

fn failure(a: &[Item], b: &[Item], gi: Option<usize>, nj: Option<usize>) -> String {
    let golden_line = gi.map_or_else(
        || "<nothing — the item is an ADDITION>".to_string(),
        |i| format!("#{i} {}", describe(&a[i])),
    );
    let new_line = nj.map_or_else(
        || "<nothing — the item is a REMOVAL>".to_string(),
        |j| format!("#{j} {}", describe(&b[j])),
    );
    let golden_hex = gi.map_or_else(String::new, |i| hexdump(&item_bytes(&a[i])));
    let new_hex = nj.map_or_else(String::new, |j| hexdump(&item_bytes(&b[j])));

    format!(
        "golden-bytes diff is NOT covered by any whitelist class.\n\
         \x20 golden item: {golden_line}\n\
         \x20 new    item: {new_line}\n\
         \x20 classes attempted: 1 VatDuplicateLineRemoved (text carrying both the matricule and the `{}` label), \
         2 FiscalHashQrRemoved (QR payload == fiscal_hash), \
         3 CodePagePrologue (ESC t n added/changed), \
         4 CurrencyPlacement (same line once `{}`-placement is normalised), \
         5 QrCaptionAdded (one text line immediately before the workflow QR)\n\
         \x20 golden hex: {golden_hex}\n\
         \x20 new    hex: {new_hex}",
        VAT_LABEL, CURRENCY
    )
}

/// Span of an inserted QR caption: `[start, end)` covers the caption text line
/// plus the `Font` / `Align` tokens that frame it, where `end` is the index of
/// the workflow-token QR the caption announces.
///
/// Returns `None` unless `b[j]` is a non-empty text line that does NOT contain
/// the fiscal hash and that reaches the `qr_token` QR through Font/Align tokens
/// only. Anything else — a blank caption, a re-printed hash, a caption with
/// real content between it and the QR — is left for the reviewer.
fn qr_caption_span(b: &[Item], j: usize, wl: &Whitelist) -> Option<(usize, usize)> {
    match &b[j] {
        Item::Text(t) if !t.trim().is_empty() && !t.contains(wl.fiscal_hash) => {}
        _ => return None,
    }

    let mut end = j + 1;
    while matches!(b.get(end), Some(Item::Font(_)) | Some(Item::Align(_))) {
        end += 1;
    }
    match b.get(end) {
        Some(Item::Qr { payload, .. }) if payload == wl.qr_token => {}
        _ => return None,
    }

    let mut start = j;
    while start > 0 && matches!(b[start - 1], Item::Font(_) | Item::Align(_)) {
        start -= 1;
    }
    Some((start, end))
}

/// Classify one contiguous change hunk. Content-keyed classes (1, 2, 5) are
/// resolved first so that the permissive currency pairing (4) can never absorb
/// them by accident.
fn classify_hunk(
    a: &[Item],
    b: &[Item],
    dels: &mut Vec<usize>,
    inss: &mut Vec<usize>,
    wl: &Whitelist,
    classes: &mut Vec<WhitelistClass>,
) -> Result<(), String> {
    // 1 — removed duplicate VAT-number line (DEV-QA-092).
    dels.retain(|&i| {
        if let Item::Text(t) = &a[i] {
            if t.contains(wl.vat_number) && t.contains(wl.vat_label) {
                classes.push(WhitelistClass::VatDuplicateLineRemoved);
                return false;
            }
        }
        true
    });

    // 2 — removed fiscal-hash QR (DEV-QA-093).
    dels.retain(|&i| {
        if let Item::Qr { payload, .. } = &a[i] {
            if payload == wl.fiscal_hash {
                classes.push(WhitelistClass::FiscalHashQrRemoved);
                return false;
            }
        }
        true
    });

    // 5 — one added caption line immediately before the workflow QR (DEV-QA-093).
    //
    // Tightened after review: the codebase's labelled-QR idiom is
    // `select_font(true); text_line(label); select_font(false); qr_code(..)`
    // (`receipt_template.rs:490-494`), so `ESC M` tokens sit BETWEEN the caption
    // and the QR. The lookahead skips Font/Align tokens and whitelists the ones
    // that frame this caption — and only those, so a style toggle anywhere else
    // still fails. The caption must be non-empty and must not smuggle the fiscal
    // hash back onto the ticket in text form.
    if let Some((start, end)) = inss.iter().find_map(|&j| qr_caption_span(b, j, wl)) {
        classes.push(WhitelistClass::QrCaptionAdded);
        inss.retain(|&j| !(start..end).contains(&j));
    }

    // 3 — ESC t prologue added or CHANGED (DEV-QA-094).
    //
    // Tightened after review: a LONE `CodePage` deletion is not whitelisted.
    // "The stream stopped declaring a code page" is the defect this lane exists
    // to fix, so it must fail the gate instead of being waved through as
    // prologue churn. A deletion is accepted only when the same hunk also
    // carries a `CodePage` insertion (i.e. the page CHANGED), and only in the
    // prologue itself — `ESC @` is item 0, `ESC t n` item 1.
    const PROLOGUE_MAX_INDEX: usize = 1;
    let code_page_redeclared = inss.iter().any(|&j| matches!(b[j], Item::CodePage(_)));
    if code_page_redeclared {
        dels.retain(|&i| {
            if matches!(a[i], Item::CodePage(_)) && i <= PROLOGUE_MAX_INDEX {
                classes.push(WhitelistClass::CodePagePrologue);
                return false;
            }
            true
        });
    }
    inss.retain(|&j| {
        if matches!(b[j], Item::CodePage(_)) && j <= PROLOGUE_MAX_INDEX {
            classes.push(WhitelistClass::CodePagePrologue);
            return false;
        }
        true
    });

    // 4 — money line re-laid out (DEV-QA-095).
    let mut d = 0usize;
    while d < dels.len() {
        let gi = dels[d];
        let mut paired: Option<usize> = None;
        if let Item::Text(golden_text) = &a[gi] {
            let golden_norm = normalize_money(golden_text, wl.currency);
            for (k, &nj) in inss.iter().enumerate() {
                if let Item::Text(new_text) = &b[nj] {
                    if normalize_money(new_text, wl.currency) == golden_norm {
                        paired = Some(k);
                        break;
                    }
                }
            }
        }
        match paired {
            Some(k) => {
                inss.remove(k);
                dels.remove(d);
                classes.push(WhitelistClass::CurrencyPlacement);
            }
            None => d += 1,
        }
    }

    if let Some(&gi) = dels.first() {
        return Err(failure(a, b, Some(gi), inss.first().copied()));
    }
    if let Some(&nj) = inss.first() {
        return Err(failure(a, b, None, Some(nj)));
    }
    Ok(())
}

/// Diff two decoded streams and return the whitelist classes that explain every
/// difference, or the first unexplained one as an error.
fn classify(a: &[Item], b: &[Item], wl: &Whitelist) -> Result<Vec<WhitelistClass>, String> {
    let ops = diff_items(a, b);
    let mut classes = Vec::new();
    let mut idx = 0usize;

    while idx < ops.len() {
        if matches!(ops[idx], Op::Equal) {
            idx += 1;
            continue;
        }
        let mut dels = Vec::new();
        let mut inss = Vec::new();
        while idx < ops.len() {
            match ops[idx] {
                Op::Del(i) => {
                    dels.push(i);
                    idx += 1;
                }
                Op::Ins(j) => {
                    inss.push(j);
                    idx += 1;
                }
                Op::Equal => break,
            }
        }
        classify_hunk(a, b, &mut dels, &mut inss, wl, &mut classes)?;
    }
    Ok(classes)
}

/// The feed/cut tail and the one-cut-per-buffer structure are NOT whitelisted:
/// `commands/printing.rs:49,60-62` re-sends the SAME buffer `copies` times, so
/// one extra `GS V` here silently doubles every operator's paper consumption.
fn assert_cut_structure(case: &str, golden: &[Item], new: &[Item]) {
    let tail = |items: &[Item]| -> Vec<Item> {
        items
            .iter()
            .filter(|i| matches!(i, Item::Cut(_) | Item::Feed(_)))
            .cloned()
            .collect()
    };
    assert_eq!(
        tail(golden),
        tail(new),
        "{case}: the feed/cut structure changed — the copies loop re-sends this buffer verbatim"
    );
    assert_eq!(
        golden
            .iter()
            .filter(|i| matches!(i, Item::Cut(_)))
            .count(),
        1,
        "{case}: a receipt buffer must carry exactly one GS V cut"
    );
    assert!(
        matches!(new.last(), Some(Item::Cut(1))),
        "{case}: the buffer must end with a partial cut (GS V 1), got {:?}",
        new.last()
    );
}

// ──────────────────────────── capture / compare ──────────────────────────────

fn capture_mode() -> bool {
    matches!(std::env::var("GOLDEN_WRITE"), Ok(v) if !v.is_empty() && v != "0")
}

fn golden_path(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests")
        .join("golden")
        .join(format!("{name}.bin"))
}

fn run_case(name: &str, data: &ReceiptData, settings: &PrintSettings) {
    let bytes = format_receipt_with_settings(data, Some(settings));
    let path = golden_path(name);

    if capture_mode() {
        let dir = path.parent().expect("golden path has a parent");
        std::fs::create_dir_all(dir).expect("create tests/golden");
        std::fs::write(&path, &bytes).expect("write golden file");
        println!(
            "GOLDEN_WRITE: captured {} ({} bytes)",
            path.display(),
            bytes.len()
        );
    }

    let golden = std::fs::read(&path).unwrap_or_else(|e| {
        panic!(
            "{name}: cannot read {} ({e}). Capture the baseline with \
             `GOLDEN_WRITE=1 cargo test --lib printing::golden`",
            path.display()
        )
    });

    let golden_items = parse(&golden);
    let new_items = parse(&bytes);
    assert_cut_structure(name, &golden_items, &new_items);

    match classify(&golden_items, &new_items, &whitelist()) {
        Ok(classes) => println!(
            "{name}: {} golden bytes, {} items, {} whitelisted diff(s) {:?}",
            golden.len(),
            new_items.len(),
            classes.len(),
            classes
        ),
        Err(message) => panic!("{name}: {message}"),
    }
}

#[test]
fn golden_sale_42() {
    run_case("sale-42", &sale_fixture(), &settings(42));
}

#[test]
fn golden_sale_32() {
    run_case("sale-32", &sale_fixture(), &settings(32));
}

#[test]
fn golden_refund_42() {
    run_case("refund-42", &refund_fixture(), &settings(42));
}

#[test]
fn golden_refund_32() {
    run_case("refund-32", &refund_fixture(), &settings(32));
}

#[test]
fn golden_z_42() {
    run_case("z-42", &z_fixture(), &settings(42));
}

#[test]
fn golden_z_32() {
    run_case("z-32", &z_fixture(), &settings(32));
}

// ───────────────── tamper tests for the harness itself (conv. 08) ────────────
//
// A whitelist that never rejects anything is worse than no harness at all, and a
// whitelist that never ACCEPTS the intended fix would be worked around. Both
// halves are proven here on synthetic byte pairs — one per class, plus a diff
// that must be refused.

/// Minimal well-formed stream: init + the given body + feed/cut tail.
fn synthetic(body: impl FnOnce(&mut EscPosBuilder)) -> Vec<u8> {
    synthetic_in(TextEncoding::Cp1252, body)
}

/// Same, under an explicit code page — the builder always opens with
/// `ESC @` + `ESC t <page>`, so this is how a prologue CHANGE is synthesised.
fn synthetic_in(encoding: TextEncoding, body: impl FnOnce(&mut EscPosBuilder)) -> Vec<u8> {
    let mut b = EscPosBuilder::with_columns_and_encoding(42, encoding);
    body(&mut b);
    b.feed_lines(4);
    b.cut(CutMode::Partial);
    b.build()
}

/// Strip the `ESC t n` out of a stream's prologue — the shape of a regression
/// that stops declaring a code page at all (the tamper case for class 3).
fn without_code_page(bytes: &[u8]) -> Vec<u8> {
    assert_eq!(
        &bytes[..4],
        &[0x1B, 0x40, 0x1B, 0x74],
        "expected an ESC @ + ESC t prologue"
    );
    let mut out = bytes[..2].to_vec();
    out.extend_from_slice(&bytes[5..]);
    out
}

fn classify_pair(golden: &[u8], new: &[u8]) -> Result<Vec<WhitelistClass>, String> {
    classify(&parse(golden), &parse(new), &whitelist())
}

#[test]
fn whitelist_accepts_class1_removed_duplicate_vat_line() {
    let golden = synthetic(|b| {
        b.text_line(&format!("MF : {TAX_ID}"));
        b.text_line(&format!("N° TVA : {TAX_ID}"));
    });
    let new = synthetic(|b| {
        b.text_line(&format!("MF : {TAX_ID}"));
    });
    assert_eq!(
        classify_pair(&golden, &new).expect("class 1 must be whitelisted"),
        vec![WhitelistClass::VatDuplicateLineRemoved]
    );
}

#[test]
fn whitelist_accepts_class2_removed_fiscal_hash_qr() {
    let golden = synthetic(|b| {
        b.text_line("Hash: 3f7a1c9e08b542d6...c7e1539a04d8b2c6");
        b.qr_code(FISCAL_HASH, 4, QrErrorCorrection::M);
    });
    let new = synthetic(|b| {
        b.text_line("Hash: 3f7a1c9e08b542d6...c7e1539a04d8b2c6");
    });
    assert_eq!(
        classify_pair(&golden, &new).expect("class 2 must be whitelisted"),
        vec![WhitelistClass::FiscalHashQrRemoved]
    );
}

#[test]
fn whitelist_accepts_class3_codepage_prologue() {
    // A prologue CHANGE: CP437 (ESC t 0) -> CP1252 (ESC t 16), both at item 1.
    let golden = synthetic_in(TextEncoding::Cp437, |b| {
        b.text_line("Cafe Nour");
    });
    let new = synthetic_in(TextEncoding::Cp1252, |b| {
        b.text_line("Cafe Nour");
    });
    assert_eq!(
        classify_pair(&golden, &new).expect("class 3 must be whitelisted"),
        vec![
            WhitelistClass::CodePagePrologue,
            WhitelistClass::CodePagePrologue
        ]
    );
}

#[test]
fn whitelist_accepts_class3_codepage_prologue_added() {
    // A prologue ADDED where the golden had none — the pre-fix cp437 baseline.
    let new = synthetic_in(TextEncoding::Cp1252, |b| {
        b.text_line("Cafe Nour");
    });
    let golden = without_code_page(&new);
    assert_eq!(
        classify_pair(&golden, &new).expect("class 3 must whitelist an added prologue"),
        vec![WhitelistClass::CodePagePrologue]
    );
}

#[test]
fn whitelist_rejects_a_stream_that_stops_declaring_a_code_page() {
    // The tamper case: dropping `ESC t` entirely IS the DEV-QA-094 defect.
    // A lone CodePage deletion must never be waved through as prologue churn.
    let golden = synthetic_in(TextEncoding::Cp1252, |b| {
        b.text_line("Cafe Nour");
    });
    let new = without_code_page(&golden);
    let err = classify_pair(&golden, &new).expect_err("a dropped ESC t must NOT be whitelisted");
    assert!(
        err.contains("NOT covered by any whitelist class"),
        "unexpected failure text: {err}"
    );
}

#[test]
fn whitelist_accepts_class4_currency_placement() {
    let golden = synthetic(|b| {
        b.two_column("Sous-total :", "TND11.000");
        b.two_column("TOTAL :", "TND10.000");
    });
    let new = synthetic(|b| {
        b.two_column("Sous-total :", "11.000 TND");
        b.two_column("TOTAL :", "10.000 TND");
    });
    assert_eq!(
        classify_pair(&golden, &new).expect("class 4 must be whitelisted"),
        vec![
            WhitelistClass::CurrencyPlacement,
            WhitelistClass::CurrencyPlacement
        ]
    );
}

#[test]
fn whitelist_accepts_class5_added_qr_caption() {
    let golden = synthetic(|b| {
        b.qr_code(QR_TOKEN, 4, QrErrorCorrection::M);
    });
    let new = synthetic(|b| {
        // The codebase's labelled-QR idiom: Font B for the caption, back to
        // Font A before the QR (`receipt_template.rs:490-494`).
        b.select_font(true);
        b.text_line("Scanner pour un retour ou un échange");
        b.select_font(false);
        b.qr_code(QR_TOKEN, 4, QrErrorCorrection::M);
    });
    assert_eq!(
        classify_pair(&golden, &new).expect("class 5 must be whitelisted"),
        vec![WhitelistClass::QrCaptionAdded]
    );
}

#[test]
fn whitelist_rejects_an_empty_qr_caption() {
    // A blank line before the QR is not a caption — it explains nothing and
    // must not buy a free text insertion.
    let golden = synthetic(|b| {
        b.qr_code(QR_TOKEN, 4, QrErrorCorrection::M);
    });
    let new = synthetic(|b| {
        b.select_font(true);
        b.text_line("   ");
        b.select_font(false);
        b.qr_code(QR_TOKEN, 4, QrErrorCorrection::M);
    });
    classify_pair(&golden, &new).expect_err("a blank caption must NOT be whitelisted");
}

#[test]
fn whitelist_rejects_a_changed_amount() {
    let golden = synthetic(|b| {
        b.two_column("TOTAL :", "TND10.000");
    });
    let new = synthetic(|b| {
        b.two_column("TOTAL :", "TND12.000");
    });
    let error = classify_pair(&golden, &new)
        .expect_err("a changed amount must NOT be whitelisted");
    assert!(
        error.contains("NOT covered by any whitelist class"),
        "unexpected message: {error}"
    );
    assert!(error.contains("TND10.000"), "missing golden line: {error}");
    assert!(error.contains("TND12.000"), "missing new line: {error}");
    // the hexdump of both sides must be in the message
    assert!(error.contains("54 4E 44 31 30"), "missing golden hex: {error}");
    assert!(error.contains("54 4E 44 31 32"), "missing new hex: {error}");
}

#[test]
fn whitelist_rejects_a_qr_payload_that_is_not_the_fiscal_hash() {
    let golden = synthetic(|b| {
        b.qr_code(QR_TOKEN, 4, QrErrorCorrection::M);
    });
    let new = synthetic(|b| {
        b.text_line("Merci pour votre achat !");
    });
    let error = classify_pair(&golden, &new)
        .expect_err("removing the workflow QR must NOT be whitelisted");
    assert!(
        error.contains("NOT covered by any whitelist class"),
        "unexpected message: {error}"
    );
}

#[test]
fn normalize_money_moves_the_currency_code_and_collapses_padding() {
    assert_eq!(
        normalize_money("Sous-total :          TND11.000", CURRENCY),
        normalize_money("Sous-total :        11.000 TND", CURRENCY)
    );
    assert_eq!(
        normalize_money("Remise :   -TND1.000", CURRENCY),
        normalize_money("Remise :  -1.000 TND", CURRENCY)
    );
    // amounts that differ must NOT normalise to the same string
    assert_ne!(
        normalize_money("TOTAL : TND10.000", CURRENCY),
        normalize_money("TOTAL : 12.000 TND", CURRENCY)
    );
}
