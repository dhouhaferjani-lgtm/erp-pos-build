/// ESC/POS command builder for thermal receipt printers.
///
/// Implements the standard ESC/POS command set used by Epson, Star, and
/// compatible 80mm thermal printers.

/// Text alignment values for ESC a command.
#[derive(Debug, Clone, Copy)]
pub enum Alignment {
    Left,
    Center,
    Right,
}

impl Alignment {
    fn byte(self) -> u8 {
        match self {
            Alignment::Left => 0,
            Alignment::Center => 1,
            Alignment::Right => 2,
        }
    }
}

/// Font size multipliers (width x height).
#[derive(Debug, Clone, Copy)]
pub enum FontSize {
    Normal,
    DoubleWidth,
    DoubleHeight,
    DoubleWidthHeight,
}

impl FontSize {
    fn byte(self) -> u8 {
        match self {
            FontSize::Normal => 0x00,
            FontSize::DoubleWidth => 0x10,
            FontSize::DoubleHeight => 0x01,
            FontSize::DoubleWidthHeight => 0x11,
        }
    }
}

/// Cut mode for GS V command.
#[derive(Debug, Clone, Copy)]
pub enum CutMode {
    Full,
    Partial,
}

/// Barcode system for GS k command.
#[derive(Debug, Clone, Copy)]
pub enum BarcodeSystem {
    Code128,
    Ean13,
    Code39,
}

impl BarcodeSystem {
    fn id(self) -> u8 {
        match self {
            BarcodeSystem::Code128 => 73, // Function B format
            BarcodeSystem::Ean13 => 67,
            BarcodeSystem::Code39 => 69,
        }
    }
}

/// QR code error correction level.
#[derive(Debug, Clone, Copy)]
pub enum QrErrorCorrection {
    L,
    M,
    Q,
    H,
}

impl QrErrorCorrection {
    fn byte(self) -> u8 {
        match self {
            QrErrorCorrection::L => 48,
            QrErrorCorrection::M => 49,
            QrErrorCorrection::Q => 50,
            QrErrorCorrection::H => 51,
        }
    }
}

/// Character set the printer is told to use (`ESC t n`) AND that the builder
/// transcodes text into. The two MUST stay in lock-step: DEV-QA-094 was
/// exactly this pair coming apart — CP1252 bytes on the wire while the
/// printer was left on its power-on CP437 page, so `é` (0xE9) printed `Θ`
/// and `ç` (0xE7) printed `τ`.
///
/// `code_page()` is the `n` of `ESC t n`; `encode()` produces the bytes that
/// page expects. Anything the page cannot represent becomes `?` — an honest
/// placeholder instead of a silently wrong glyph.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TextEncoding {
    /// CP437 — the printer power-on page. Has no `é` / `è` / `ç` at all.
    Cp437,
    /// CP858 — CP850 with the euro sign at 0xD5.
    Cp858,
    /// CP1252 — Windows Western European. The device default.
    Cp1252,
}

impl TextEncoding {
    /// `n` for the `ESC t n` code-page selection command.
    pub fn code_page(self) -> u8 {
        match self {
            TextEncoding::Cp437 => 0,
            TextEncoding::Cp858 => 19,
            TextEncoding::Cp1252 => 16,
        }
    }

    /// Transcode UTF-8 text into this code page's bytes.
    ///
    /// CP1252 goes through `encoding_rs`; CP437 and CP858 do not ship in
    /// `encoding_rs`, so CP437 is ASCII-only (its accented range is Greek /
    /// box-drawing glyphs, not Latin accents) and CP858 uses the static
    /// table below.
    ///
    /// A character the page cannot represent is TRANSLITERATED to its ASCII
    /// shape when one exists (`é` → `e`, `€` → `EUR`) and only otherwise
    /// becomes `?` — device recette 2026-09-18: a terminal persisted on
    /// `cp437` printed `Re?u` / `Op?rateur` / `Qt?` / `Esp?ces`, which is
    /// honest but unreadable. CP1252 and CP858 carry every French accent
    /// natively, so this path never fires for them and their bytes are
    /// unchanged.
    pub fn encode(self, s: &str) -> Vec<u8> {
        let mut out = Vec::with_capacity(s.len());
        for c in s.chars() {
            match self.encode_char(c) {
                Some(byte) => out.push(byte),
                None => match transliterate(c) {
                    Some(ascii) => out.extend_from_slice(ascii.as_bytes()),
                    None => out.push(b'?'),
                },
            }
        }
        out
    }

    /// The single byte this page uses for `c`, or `None` when the page cannot
    /// represent it at all.
    fn encode_char(self, c: char) -> Option<u8> {
        match self {
            // Per-character round trip through `encoding_rs`: WINDOWS_1252 is
            // a stateless single-byte charset, so this yields exactly the
            // bytes the previous whole-string call produced for every
            // representable character. `had_errors` is how encoding_rs
            // reports "not in this page" (it would otherwise emit an HTML
            // numeric character reference, which would print literally).
            TextEncoding::Cp1252 => {
                let mut buf = [0u8; 4];
                let one = c.encode_utf8(&mut buf);
                let (cow, _encoding_used, had_errors) = encoding_rs::WINDOWS_1252.encode(one);
                if had_errors || cow.len() != 1 {
                    None
                } else {
                    Some(cow[0])
                }
            }
            TextEncoding::Cp437 => c.is_ascii().then_some(c as u8),
            TextEncoding::Cp858 => cp858_byte(c),
        }
    }
}

/// ASCII shape for a character no selected code page can print.
///
/// Letters map 1:1 so the column arithmetic is unaffected; the handful of
/// multi-character entries (`€` → `EUR`, `œ` → `oe`, `…` → `...`) widen the
/// text, which is why every column computation measures ENCODED bytes (see
/// [`EscPosBuilder::printed_width`]).
fn transliterate(c: char) -> Option<&'static str> {
    Some(match c {
        'À' | 'Á' | 'Â' | 'Ã' | 'Ä' | 'Å' => "A",
        'à' | 'á' | 'â' | 'ã' | 'ä' | 'å' => "a",
        'Æ' => "AE",
        'æ' => "ae",
        'Ç' => "C",
        'ç' => "c",
        'È' | 'É' | 'Ê' | 'Ë' => "E",
        'è' | 'é' | 'ê' | 'ë' => "e",
        'Ì' | 'Í' | 'Î' | 'Ï' => "I",
        'ì' | 'í' | 'î' | 'ï' => "i",
        'Ñ' => "N",
        'ñ' => "n",
        'Ò' | 'Ó' | 'Ô' | 'Õ' | 'Ö' | 'Ø' => "O",
        'ò' | 'ó' | 'ô' | 'õ' | 'ö' | 'ø' => "o",
        'Ù' | 'Ú' | 'Û' | 'Ü' => "U",
        'ù' | 'ú' | 'û' | 'ü' => "u",
        'Ý' | 'Ÿ' => "Y",
        'ý' | 'ÿ' => "y",
        'ß' => "ss",
        'Œ' => "OE",
        'œ' => "oe",
        'Š' => "S",
        'š' => "s",
        'Ž' => "Z",
        'ž' => "z",
        '€' => "EUR",
        '\u{00A0}' | '\u{202F}' => " ",
        '\u{2018}' | '\u{2019}' | '\u{2032}' => "'",
        '\u{201C}' | '\u{201D}' => "\"",
        '\u{2013}' | '\u{2014}' => "-",
        '\u{2026}' => "...",
        _ => return None,
    })
}

/// CP858 bytes for U+00A0..=U+00FF, indexed by `code point - 0xA0`.
/// Transcribed from the CP850 layout (CP858 differs only at 0xD5, which is
/// `€` instead of `ı` — handled separately in `cp858_byte`).
const CP858_LATIN1_SUPPLEMENT: [u8; 96] = [
    0xFF, 0xAD, 0xBD, 0x9C, 0xCF, 0xBE, 0xDD, 0xF5, // A0 NBSP ¡ ¢ £ ¤ ¥ ¦ §
    0xF9, 0xB8, 0xA6, 0xAE, 0xAA, 0xF0, 0xA9, 0xEE, // A8 ¨ © ª « ¬ SHY ® ¯
    0xF8, 0xF1, 0xFD, 0xFC, 0xEF, 0xE6, 0xF4, 0xFA, // B0 ° ± ² ³ ´ µ ¶ ·
    0xF7, 0xFB, 0xA7, 0xAF, 0xAC, 0xAB, 0xF3, 0xA8, // B8 ¸ ¹ º » ¼ ½ ¾ ¿
    0xB7, 0xB5, 0xB6, 0xC7, 0x8E, 0x8F, 0x92, 0x80, // C0 À Á Â Ã Ä Å Æ Ç
    0xD4, 0x90, 0xD2, 0xD3, 0xDE, 0xD6, 0xD7, 0xD8, // C8 È É Ê Ë Ì Í Î Ï
    0xD1, 0xA5, 0xE3, 0xE0, 0xE2, 0xE5, 0x99, 0x9E, // D0 Ð Ñ Ò Ó Ô Õ Ö ×
    0x9D, 0xEB, 0xE9, 0xEA, 0x9A, 0xED, 0xE8, 0xE1, // D8 Ø Ù Ú Û Ü Ý Þ ß
    0x85, 0xA0, 0x83, 0xC6, 0x84, 0x86, 0x91, 0x87, // E0 à á â ã ä å æ ç
    0x8A, 0x82, 0x88, 0x89, 0x8D, 0xA1, 0x8C, 0x8B, // E8 è é ê ë ì í î ï
    0xD0, 0xA4, 0x95, 0xA2, 0x93, 0xE4, 0x94, 0xF6, // F0 ð ñ ò ó ô õ ö ÷
    0x9B, 0x97, 0xA3, 0x96, 0x81, 0xEC, 0xE7, 0x98, // F8 ø ù ú û ü ý þ ÿ
];

/// Map one character to its CP858 byte, `None` when the page cannot print it
/// (the caller then transliterates, and only then falls back to `?`).
fn cp858_byte(c: char) -> Option<u8> {
    let cp = c as u32;
    if c.is_ascii() {
        return Some(c as u8);
    }
    if (0xA0..=0xFF).contains(&cp) {
        return Some(CP858_LATIN1_SUPPLEMENT[(cp - 0xA0) as usize]);
    }
    if c == '€' {
        return Some(0xD5); // the one cell where CP858 departs from CP850
    }
    None
}

/// Builder that accumulates ESC/POS commands into a byte buffer.
pub struct EscPosBuilder {
    buffer: Vec<u8>,
    /// Number of printable columns (typically 42 for 80mm paper, 32 for 58mm).
    columns: u8,
    /// Character encoding for text output (default: CP1252 for French accented chars).
    encoding: TextEncoding,
}

impl EscPosBuilder {
    /// Create a new builder for an 80mm (42-column) printer.
    pub fn new() -> Self {
        Self::with_columns_and_encoding(42, TextEncoding::Cp1252)
    }

    /// Create a new builder with a specific column width AND character set.
    ///
    /// The prologue is always `ESC @` followed by `ESC t <code page>` — the
    /// code page is declared UNCONDITIONALLY, including for CP437 (`n = 0`),
    /// so a printer left on another page by a previous job is reset. There is
    /// no way to construct a builder whose declared page disagrees with the
    /// bytes it emits (DEV-QA-094).
    pub fn with_columns_and_encoding(columns: u8, encoding: TextEncoding) -> Self {
        let mut builder = Self {
            buffer: Vec::with_capacity(4096),
            columns,
            encoding,
        };
        builder.initialize();
        builder.set_code_page(encoding.code_page());
        builder
    }

    /// Encode a UTF-8 string into the target code page bytes.
    /// Characters not representable in the target encoding become `?` (lossy).
    fn encode_text(&self, s: &str) -> Vec<u8> {
        self.encoding.encode(s)
    }

    /// ESC @ — Initialize printer (reset to default settings).
    pub fn initialize(&mut self) -> &mut Self {
        self.buffer.extend_from_slice(&[0x1B, 0x40]);
        self
    }

    /// ESC a n — Set alignment.
    pub fn align(&mut self, alignment: Alignment) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1B, 0x61, alignment.byte()]);
        self
    }

    /// ESC E n — Toggle bold mode.
    pub fn bold(&mut self, on: bool) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1B, 0x45, if on { 1 } else { 0 }]);
        self
    }

    /// ESC - n — Toggle underline.
    pub fn underline(&mut self, on: bool) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1B, 0x2D, if on { 1 } else { 0 }]);
        self
    }

    /// GS ! n — Set character size (width/height multiplier).
    pub fn font_size(&mut self, size: FontSize) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1D, 0x21, size.byte()]);
        self
    }

    /// ESC M n — Select font (0 = Font A ~12x24, 1 = Font B ~9x17).
    pub fn select_font(&mut self, font_b: bool) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1B, 0x4D, if font_b { 1 } else { 0 }]);
        self
    }

    /// Print text without a newline.
    pub fn text(&mut self, s: &str) -> &mut Self {
        self.buffer.extend_from_slice(&self.encode_text(s));
        self
    }

    /// Print text followed by a newline (LF).
    pub fn text_line(&mut self, s: &str) -> &mut Self {
        self.buffer.extend_from_slice(&self.encode_text(s));
        self.buffer.push(0x0A);
        self
    }

    /// Print an empty line.
    pub fn empty_line(&mut self) -> &mut Self {
        self.buffer.push(0x0A);
        self
    }

    /// ESC d n — Print and feed n lines.
    pub fn feed_lines(&mut self, n: u8) -> &mut Self {
        self.buffer.extend_from_slice(&[0x1B, 0x64, n]);
        self
    }

    /// Print a horizontal separator line across the full width.
    pub fn separator(&mut self, ch: char) -> &mut Self {
        let line: String = std::iter::repeat(ch).take(self.columns as usize).collect();
        self.text_line(&line)
    }

    /// Printed width of `s` in columns: the number of BYTES the selected code
    /// page produces, since ESC/POS Font A prints one column per byte.
    ///
    /// Not `.chars().count()`: `.len()` would overcount `ç` (2 UTF-8 bytes, 1
    /// CP1252 column) and a char count would UNDERcount a transliterated `€`
    /// (1 char, 3 printed columns). For CP1252 — every tenant after the store
    /// migration, and every golden fixture — the two are identical, because a
    /// representable character is exactly one byte there.
    fn printed_width(&self, s: &str) -> usize {
        self.encoding.encode(s).len()
    }

    /// Print a line with left-aligned and right-aligned text on the same row.
    /// If the combined text exceeds column width, right text is truncated.
    ///
    /// Widths come from [`Self::printed_width`] (encoded bytes = columns).
    pub fn two_column(&mut self, left: &str, right: &str) -> &mut Self {
        let cols = self.columns as usize;
        let left_len = self.printed_width(left);
        let right_len = self.printed_width(right);

        if left_len + right_len >= cols {
            // Truncate: show as much as fits
            let max_left = if cols > right_len + 1 {
                cols - right_len - 1
            } else {
                cols
            };
            let truncated_left = self.truncate_to_width(left, max_left);
            let truncated_left_len = self.printed_width(&truncated_left);
            let remaining = cols.saturating_sub(truncated_left_len);
            let padded_right = if remaining >= right_len {
                format!("{}{}", " ".repeat(remaining - right_len), right)
            } else {
                self.truncate_to_width(right, remaining)
            };
            self.text(&truncated_left);
            self.text_line(&padded_right);
        } else {
            let padding = cols - left_len - right_len;
            self.text(left);
            let spaces: String = std::iter::repeat(' ').take(padding).collect();
            self.text(&spaces);
            self.text_line(right);
        }
        self
    }

    /// Longest prefix of `s` whose printed width is at most `max_width`.
    fn truncate_to_width(&self, s: &str, max_width: usize) -> String {
        let mut out = String::with_capacity(s.len());
        let mut width = 0usize;
        for c in s.chars() {
            let mut one = [0u8; 4];
            let w = self.printed_width(c.encode_utf8(&mut one));
            if width + w > max_width {
                break;
            }
            out.push(c);
            width += w;
        }
        out
    }

    /// Print a three-column line (left, center, right).
    ///
    /// Widths come from [`Self::printed_width`] (see `two_column` doc).
    pub fn three_column(&mut self, left: &str, center: &str, right: &str) -> &mut Self {
        let cols = self.columns as usize;
        let total_content =
            self.printed_width(left) + self.printed_width(center) + self.printed_width(right);

        if total_content >= cols {
            // Fall back to two-column with center+right merged
            let merged_right = format!("{} {}", center, right);
            self.two_column(left, &merged_right);
        } else {
            let remaining_space = cols - total_content;
            let left_pad = remaining_space / 2;
            let right_pad = remaining_space - left_pad;

            self.text(left);
            let lpad: String = std::iter::repeat(' ').take(left_pad).collect();
            self.text(&lpad);
            self.text(center);
            let rpad: String = std::iter::repeat(' ').take(right_pad).collect();
            self.text(&rpad);
            self.text_line(right);
        }
        self
    }

    /// GS V m — Cut paper.
    pub fn cut(&mut self, mode: CutMode) -> &mut Self {
        let m = match mode {
            CutMode::Full => 0,
            CutMode::Partial => 1,
        };
        self.buffer.extend_from_slice(&[0x1D, 0x56, m]);
        self
    }

    /// ESC p m t1 t2 — Generate pulse on cash drawer kick connector.
    /// Pin 2 (m=0) or pin 5 (m=1). t1/t2 are on/off times in 2ms units.
    pub fn cash_drawer_kick(&mut self, pin: u8) -> &mut Self {
        let m = if pin >= 1 { 1 } else { 0 };
        // Standard pulse: 100ms on, 100ms off
        self.buffer.extend_from_slice(&[0x1B, 0x70, m, 50, 50]);
        self
    }

    /// ESC p m t1 t2 — Cash drawer kick with custom pulse timing.
    /// `pin`: 0 = Pin 2, 1 = Pin 5. `t1`/`t2` in 2ms units (0-255).
    pub fn cash_drawer_kick_custom(&mut self, pin: u8, t1: u8, t2: u8) -> &mut Self {
        let m = if pin >= 1 { 1 } else { 0 };
        self.buffer.extend_from_slice(&[0x1B, 0x70, m, t1, t2]);
        self
    }

    /// BEL — Send audible beep (0x07).
    pub fn beep(&mut self) -> &mut Self {
        self.buffer.push(0x07);
        self
    }

    /// ESC t n — Select character code page.
    /// Common values: 0 = CP437, 19 = CP858, 16 = CP1252.
    pub fn set_code_page(&mut self, page: u8) -> &mut Self {
        self.buffer.extend_from_slice(&[0x1B, 0x74, page]);
        self
    }

    /// GS H n — Set HRI (human-readable interpretation) print position for barcodes.
    /// 0=none, 1=above, 2=below, 3=both
    pub fn barcode_hri_position(&mut self, position: u8) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1D, 0x48, position.min(3)]);
        self
    }

    /// GS h n — Set barcode height in dots.
    pub fn barcode_height(&mut self, dots: u8) -> &mut Self {
        self.buffer.extend_from_slice(&[0x1D, 0x68, dots]);
        self
    }

    /// GS w n — Set barcode width multiplier (2-6).
    pub fn barcode_width(&mut self, width: u8) -> &mut Self {
        self.buffer
            .extend_from_slice(&[0x1D, 0x77, width.clamp(2, 6)]);
        self
    }

    /// GS k m n d1..dn — Print barcode.
    pub fn barcode(&mut self, system: BarcodeSystem, data: &str) -> &mut Self {
        let bytes = data.as_bytes();
        let len = bytes.len() as u8;
        self.buffer
            .extend_from_slice(&[0x1D, 0x6B, system.id(), len]);
        self.buffer.extend_from_slice(bytes);
        self
    }

    /// GS ( k — Print QR code using the multi-step GS(k command sequence.
    pub fn qr_code(&mut self, data: &str, module_size: u8, error_correction: QrErrorCorrection) -> &mut Self {
        let bytes = data.as_bytes();

        // Function 165: Select model (Model 2)
        self.buffer
            .extend_from_slice(&[0x1D, 0x28, 0x6B, 4, 0, 0x31, 0x41, 50, 0]);

        // Function 167: Set module size
        self.buffer
            .extend_from_slice(&[0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x43, module_size]);

        // Function 169: Set error correction level
        self.buffer.extend_from_slice(&[
            0x1D,
            0x28,
            0x6B,
            3,
            0,
            0x31,
            0x45,
            error_correction.byte(),
        ]);

        // Function 180: Store QR data
        let store_len = (bytes.len() + 3) as u16;
        let pl = (store_len & 0xFF) as u8;
        let ph = ((store_len >> 8) & 0xFF) as u8;
        self.buffer
            .extend_from_slice(&[0x1D, 0x28, 0x6B, pl, ph, 0x31, 0x50, 0x30]);
        self.buffer.extend_from_slice(bytes);

        // Function 181: Print QR code
        self.buffer
            .extend_from_slice(&[0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x51, 0x30]);

        self
    }

    /// Consume the builder and return the raw ESC/POS byte buffer.
    pub fn build(self) -> Vec<u8> {
        self.buffer
    }

    /// Return the current column width.
    pub fn columns(&self) -> u8 {
        self.columns
    }
}

impl Default for EscPosBuilder {
    fn default() -> Self {
        Self::new()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Every builder opens with `ESC @` + `ESC t <code page>` (5 bytes), so
    /// the body of a stream starts at index 5.
    const PROLOGUE_LEN: usize = 5;

    #[test]
    fn test_initialize_command() {
        let builder = EscPosBuilder::new();
        let data = builder.build();
        // Should start with ESC @
        assert!(data.len() >= 2);
        assert_eq!(data[0], 0x1B);
        assert_eq!(data[1], 0x40);
    }

    #[test]
    fn test_prologue_always_declares_the_code_page() {
        // DEV-QA-094: the code page is declared unconditionally, CP437 (n=0)
        // included — a printer left on another page by a previous job is reset.
        for (encoding, page) in [
            (TextEncoding::Cp437, 0u8),
            (TextEncoding::Cp858, 19u8),
            (TextEncoding::Cp1252, 16u8),
        ] {
            let data = EscPosBuilder::with_columns_and_encoding(42, encoding).build();
            assert_eq!(
                &data[..PROLOGUE_LEN],
                &[0x1B, 0x40, 0x1B, 0x74, page],
                "{encoding:?} must open with ESC @ then ESC t {page}"
            );
        }
    }

    #[test]
    fn test_bold_toggle() {
        let mut builder = EscPosBuilder::new();
        builder.bold(true);
        let data = builder.build();
        // ESC @ + ESC t n (5 bytes) + ESC E 1 (3 bytes)
        assert_eq!(data.len(), PROLOGUE_LEN + 3);
        assert_eq!(data[PROLOGUE_LEN], 0x1B);
        assert_eq!(data[PROLOGUE_LEN + 1], 0x45);
        assert_eq!(data[PROLOGUE_LEN + 2], 1);
    }

    #[test]
    fn test_two_column_formatting() {
        let mut builder = EscPosBuilder::new(); // 42 columns
        builder.two_column("Item", "10.00");
        let data = builder.build();
        let text_start = PROLOGUE_LEN; // skip ESC @ + ESC t n
        let line = String::from_utf8_lossy(&data[text_start..]);
        assert!(line.contains("Item"));
        assert!(line.contains("10.00"));
        // Total should be 42 chars + LF
        let line_bytes = &data[text_start..];
        // Find LF
        let lf_pos = line_bytes.iter().position(|&b| b == 0x0A).unwrap();
        assert_eq!(lf_pos, 42);
    }

    #[test]
    fn test_cut_command() {
        let mut builder = EscPosBuilder::new();
        builder.cut(CutMode::Partial);
        let data = builder.build();
        let cut_start = data.len() - 3;
        assert_eq!(data[cut_start], 0x1D);
        assert_eq!(data[cut_start + 1], 0x56);
        assert_eq!(data[cut_start + 2], 1);
    }

    #[test]
    fn test_cash_drawer_kick() {
        let mut builder = EscPosBuilder::new();
        builder.cash_drawer_kick(0);
        let data = builder.build();
        // ESC p m t1 t2
        let cmd_start = PROLOGUE_LEN; // after ESC @ + ESC t n
        assert_eq!(data[cmd_start], 0x1B);
        assert_eq!(data[cmd_start + 1], 0x70);
        assert_eq!(data[cmd_start + 2], 0); // pin 2
        assert_eq!(data[cmd_start + 3], 50);
        assert_eq!(data[cmd_start + 4], 50);
    }

    #[test]
    fn test_cash_drawer_kick_custom() {
        let mut builder = EscPosBuilder::new();
        builder.cash_drawer_kick_custom(1, 100, 75);
        let data = builder.build();
        let cmd_start = PROLOGUE_LEN;
        assert_eq!(data[cmd_start], 0x1B);
        assert_eq!(data[cmd_start + 1], 0x70);
        assert_eq!(data[cmd_start + 2], 1); // pin 5
        assert_eq!(data[cmd_start + 3], 100);
        assert_eq!(data[cmd_start + 4], 75);
    }

    #[test]
    fn test_beep() {
        let mut builder = EscPosBuilder::new();
        builder.beep();
        let data = builder.build();
        assert_eq!(data[PROLOGUE_LEN], 0x07);
    }

    #[test]
    fn test_set_code_page() {
        let mut builder = EscPosBuilder::new();
        builder.set_code_page(19); // CP858
        let data = builder.build();
        assert_eq!(data[PROLOGUE_LEN], 0x1B);
        assert_eq!(data[PROLOGUE_LEN + 1], 0x74);
        assert_eq!(data[PROLOGUE_LEN + 2], 19);
    }

    #[test]
    fn test_encode_french_accented_text_cp1252() {
        let mut builder = EscPosBuilder::new(); // defaults to CP1252
        builder.text("Café crème");
        let data = builder.build();
        // The declared page and the bytes must agree: ESC t 16 then CP1252.
        assert_eq!(&data[..PROLOGUE_LEN], &[0x1B, 0x40, 0x1B, 0x74, 0x10]);
        let text_bytes = &data[PROLOGUE_LEN..];
        // In CP1252: C=0x43, a=0x61, f=0x66, é=0xE9, space=0x20,
        // c=0x63, r=0x72, è=0xE8, m=0x6D, e=0x65
        assert_eq!(
            text_bytes,
            &[0x43, 0x61, 0x66, 0xE9, 0x20, 0x63, 0x72, 0xE8, 0x6D, 0x65]
        );
    }

    #[test]
    fn test_encode_french_accented_text_cp858() {
        // CP858 (= CP850 + €) has its own slots for the French accents; the
        // old code emitted CP1252 bytes under an ESC t 19 header, which is
        // what printed `é` as `Ú` (DEV-QA-094).
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp858);
        builder.text("éçè€");
        let data = builder.build();
        assert_eq!(&data[..PROLOGUE_LEN], &[0x1B, 0x40, 0x1B, 0x74, 19]);
        assert_eq!(&data[PROLOGUE_LEN..], &[0x82, 0x87, 0x8A, 0xD5]);
    }

    #[test]
    fn test_encode_cp437_transliterates_unprintable_accents() {
        // CP437 genuinely cannot print é/è/ç — 0xE9 there is `Θ`. Device
        // recette 2026-09-18: the honest `?` is still unreadable French
        // ("Re?u", "Op?rateur"), so an unrepresentable Latin-1 LETTER is
        // transliterated to its ASCII shape instead.
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp437);
        builder.text("Cafe é");
        let data = builder.build();
        assert_eq!(&data[..PROLOGUE_LEN], &[0x1B, 0x40, 0x1B, 0x74, 0x00]);
        assert_eq!(&data[PROLOGUE_LEN..], b"Cafe e");
    }

    #[test]
    fn test_encode_cp437_transliterates_the_whole_french_sample() {
        // The four words from the terminal photo, plus the euro sign.
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp437);
        builder.text("Café crème Reçu Opérateur Qté Espèces €");
        let data = builder.build();
        assert_eq!(
            &data[PROLOGUE_LEN..],
            b"Cafe creme Recu Operateur Qte Especes EUR"
        );
    }

    #[test]
    fn test_encode_cp1252_is_unchanged_by_transliteration() {
        // CP1252 CAN represent every French accent, so transliteration must
        // never fire there: the bytes stay the ones DEV-QA-094 asserted.
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp1252);
        builder.text("Café crème");
        let data = builder.build();
        assert_eq!(&data[..PROLOGUE_LEN], &[0x1B, 0x40, 0x1B, 0x74, 0x10]);
        assert_eq!(
            &data[PROLOGUE_LEN..],
            &[0x43, 0x61, 0x66, 0xE9, 0x20, 0x63, 0x72, 0xE8, 0x6D, 0x65]
        );
    }

    #[test]
    fn test_encode_cp858_is_unchanged_by_transliteration() {
        // Same guarantee for CP858, which has its own accent slots.
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp858);
        builder.text("éçè€");
        let data = builder.build();
        assert_eq!(&data[PROLOGUE_LEN..], &[0x82, 0x87, 0x8A, 0xD5]);
    }

    #[test]
    fn test_encode_keeps_question_mark_for_untransliterable_chars() {
        // No ASCII shape exists for a CJK ideograph: `?` stays the fallback.
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp437);
        builder.text("价格");
        let data = builder.build();
        assert_eq!(&data[PROLOGUE_LEN..], b"??");
    }

    #[test]
    fn test_two_column_width_counts_encoded_bytes_not_source_chars() {
        // Transliteration can turn one source char into several printed
        // columns (€ → EUR). The padding must be computed on what the printer
        // receives, or the row overflows its column count.
        let mut builder = EscPosBuilder::with_columns_and_encoding(42, TextEncoding::Cp437);
        builder.two_column("Total", "11.000 €");
        let data = builder.build();
        let line = &data[PROLOGUE_LEN..];
        let lf_pos = line.iter().position(|&b| b == 0x0A).unwrap();
        assert_eq!(lf_pos, 42, "row must be exactly 42 printed columns");
        assert!(line[..lf_pos].ends_with(b"11.000 EUR"));
    }

    #[test]
    fn test_encode_ascii_passthrough() {
        let mut builder = EscPosBuilder::new();
        builder.text("Hello World");
        let data = builder.build();
        let text_bytes = &data[PROLOGUE_LEN..];
        assert_eq!(text_bytes, b"Hello World");
    }

    #[test]
    fn test_encode_unmappable_chars_no_panic() {
        // Chinese characters are in none of the three pages — every encoder
        // must produce replacement bytes, not panic.
        for encoding in [
            TextEncoding::Cp437,
            TextEncoding::Cp858,
            TextEncoding::Cp1252,
        ] {
            let mut builder = EscPosBuilder::with_columns_and_encoding(42, encoding);
            builder.text("价格");
            let data = builder.build();
            assert!(data.len() > PROLOGUE_LEN, "{encoding:?} produced no bytes");
        }
    }

    #[test]
    fn test_with_columns_and_encoding_sets_both_width_and_text_encoding() {
        let mut builder = EscPosBuilder::with_columns_and_encoding(32, TextEncoding::Cp1252);
        assert_eq!(builder.columns(), 32);
        builder.text("à");
        let data = builder.build();
        // à in CP1252 = 0xE0
        assert_eq!(data[PROLOGUE_LEN], 0xE0);
    }

    #[test]
    fn test_text_line_encodes_accented_chars() {
        let mut builder = EscPosBuilder::new();
        builder.text_line("Pâté");
        let data = builder.build();
        // Skip ESC @ (2 bytes): P=0x50, â=0xE2, t=0x74, é=0xE9, LF=0x0A
        assert_eq!(&data[PROLOGUE_LEN..], &[0x50, 0xE2, 0x74, 0xE9, 0x0A]);
    }

    #[test]
    fn test_two_column_accented_french_alignment() {
        // "Reçu :" has 6 chars but 7 UTF-8 bytes (ç = 2 bytes in UTF-8).
        // In CP1252, ç is 1 byte, so the printed width is 6 columns.
        // The column output must be exactly 42 encoded bytes (+ LF), not 43.
        let mut builder = EscPosBuilder::new(); // 42 columns
        builder.two_column("Reçu :", "12345");
        let data = builder.build();
        let text_start = PROLOGUE_LEN; // skip ESC @ + ESC t n
        let line_bytes = &data[text_start..];
        let lf_pos = line_bytes.iter().position(|&b| b == 0x0A).unwrap();
        // Encoded line should be exactly 42 bytes: 6 (left) + 31 (spaces) + 5 (right)
        assert_eq!(
            lf_pos, 42,
            "Accented text line should be exactly 42 encoded bytes, got {}",
            lf_pos
        );

        // Compare with ASCII-only equivalent: "Recu :" (also 6 chars)
        let mut builder_ascii = EscPosBuilder::new();
        builder_ascii.two_column("Recu :", "12345");
        let data_ascii = builder_ascii.build();
        let line_ascii = &data_ascii[PROLOGUE_LEN..];
        let lf_pos_ascii = line_ascii.iter().position(|&b| b == 0x0A).unwrap();
        assert_eq!(
            lf_pos, lf_pos_ascii,
            "Accented and ASCII lines must have the same encoded width"
        );
    }

    #[test]
    fn test_three_column_accented_alignment() {
        let mut builder = EscPosBuilder::new(); // 42 columns
        builder.three_column("Réf", "Désignation", "Prix");
        let data = builder.build();
        let text_start = PROLOGUE_LEN;
        let line_bytes = &data[text_start..];
        let lf_pos = line_bytes.iter().position(|&b| b == 0x0A).unwrap();
        assert_eq!(
            lf_pos, 42,
            "Three-column accented text should be exactly 42 encoded bytes, got {}",
            lf_pos
        );
    }
}
