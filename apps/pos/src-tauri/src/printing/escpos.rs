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

/// Builder that accumulates ESC/POS commands into a byte buffer.
pub struct EscPosBuilder {
    buffer: Vec<u8>,
    /// Number of printable columns (typically 42 for 80mm paper, 32 for 58mm).
    columns: u8,
    /// Character encoding for text output (default: Windows-1252 for French accented chars).
    encoding: &'static encoding_rs::Encoding,
}

impl EscPosBuilder {
    /// Create a new builder for an 80mm (42-column) printer.
    pub fn new() -> Self {
        let mut builder = Self {
            buffer: Vec::with_capacity(4096),
            columns: 42,
            encoding: encoding_rs::WINDOWS_1252,
        };
        builder.initialize();
        builder
    }

    /// Create a new builder with a specific column width.
    pub fn with_columns(columns: u8) -> Self {
        let mut builder = Self {
            buffer: Vec::with_capacity(4096),
            columns,
            encoding: encoding_rs::WINDOWS_1252,
        };
        builder.initialize();
        builder
    }

    /// Set the character encoding used for text output.
    pub fn set_encoding(&mut self, enc: &'static encoding_rs::Encoding) -> &mut Self {
        self.encoding = enc;
        self
    }

    /// Encode a UTF-8 string into the target code page bytes.
    /// Characters not representable in the target encoding become `?` (lossy).
    fn encode_text(&self, s: &str) -> Vec<u8> {
        let (cow, _encoding_used, _had_errors) = self.encoding.encode(s);
        cow.into_owned()
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

    /// Print a line with left-aligned and right-aligned text on the same row.
    /// If the combined text exceeds column width, right text is truncated.
    ///
    /// Uses `.chars().count()` for width measurement because the printer receives
    /// single-byte encoded text (CP1252/CP437), where each character = 1 column.
    /// Using `.len()` would overcount non-ASCII chars (e.g., 'ç' is 2 bytes in
    /// UTF-8 but 1 byte/column in CP1252).
    pub fn two_column(&mut self, left: &str, right: &str) -> &mut Self {
        let cols = self.columns as usize;
        let left_len = left.chars().count();
        let right_len = right.chars().count();

        if left_len + right_len >= cols {
            // Truncate: show as much as fits
            let max_left = if cols > right_len + 1 {
                cols - right_len - 1
            } else {
                cols
            };
            let truncated_chars = left.chars().take(left_len.min(max_left));
            let truncated_left: String = truncated_chars.collect();
            let truncated_left_len = truncated_left.chars().count();
            let remaining = cols.saturating_sub(truncated_left_len);
            let padded_right = if remaining >= right_len {
                format!("{:>width$}", right, width = remaining)
            } else {
                right.chars().take(remaining).collect::<String>()
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

    /// Print a three-column line (left, center, right).
    ///
    /// Uses `.chars().count()` for width measurement (see `two_column` doc).
    pub fn three_column(&mut self, left: &str, center: &str, right: &str) -> &mut Self {
        let cols = self.columns as usize;
        let total_content = left.chars().count() + center.chars().count() + right.chars().count();

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
    fn test_bold_toggle() {
        let mut builder = EscPosBuilder::new();
        builder.bold(true);
        let data = builder.build();
        // ESC @ (2 bytes) + ESC E 1 (3 bytes)
        assert_eq!(data.len(), 5);
        assert_eq!(data[2], 0x1B);
        assert_eq!(data[3], 0x45);
        assert_eq!(data[4], 1);
    }

    #[test]
    fn test_two_column_formatting() {
        let mut builder = EscPosBuilder::new(); // 42 columns
        builder.two_column("Item", "10.00");
        let data = builder.build();
        let text_start = 2; // skip ESC @
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
        let cmd_start = 2; // after ESC @
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
        let cmd_start = 2;
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
        assert_eq!(data[2], 0x07);
    }

    #[test]
    fn test_set_code_page() {
        let mut builder = EscPosBuilder::new();
        builder.set_code_page(19); // CP858
        let data = builder.build();
        assert_eq!(data[2], 0x1B);
        assert_eq!(data[3], 0x74);
        assert_eq!(data[4], 19);
    }

    #[test]
    fn test_encode_french_accented_text_cp1252() {
        let mut builder = EscPosBuilder::new(); // defaults to WINDOWS_1252
        builder.text("Café crème");
        let data = builder.build();
        // Skip ESC @ (2 bytes), then check encoded text
        let text_bytes = &data[2..];
        // In CP1252: C=0x43, a=0x61, f=0x66, é=0xE9, space=0x20,
        // c=0x63, r=0x72, è=0xE8, m=0x6D, e=0x65
        assert_eq!(
            text_bytes,
            &[0x43, 0x61, 0x66, 0xE9, 0x20, 0x63, 0x72, 0xE8, 0x6D, 0x65]
        );
    }

    #[test]
    fn test_encode_ascii_passthrough() {
        let mut builder = EscPosBuilder::new();
        builder.text("Hello World");
        let data = builder.build();
        let text_bytes = &data[2..];
        assert_eq!(text_bytes, b"Hello World");
    }

    #[test]
    fn test_encode_unmappable_chars_no_panic() {
        let mut builder = EscPosBuilder::new(); // WINDOWS_1252
        // Chinese characters are not in CP1252 — should produce replacement bytes, not panic
        builder.text("价格");
        let data = builder.build();
        // Should have ESC @ + some bytes (replacements), and not panic
        assert!(data.len() > 2);
    }

    #[test]
    fn test_set_encoding() {
        let mut builder = EscPosBuilder::new();
        builder.set_encoding(encoding_rs::WINDOWS_1252);
        builder.text("à");
        let data = builder.build();
        // à in CP1252 = 0xE0
        assert_eq!(data[2], 0xE0);
    }

    #[test]
    fn test_text_line_encodes_accented_chars() {
        let mut builder = EscPosBuilder::new();
        builder.text_line("Pâté");
        let data = builder.build();
        // Skip ESC @ (2 bytes): P=0x50, â=0xE2, t=0x74, é=0xE9, LF=0x0A
        assert_eq!(&data[2..], &[0x50, 0xE2, 0x74, 0xE9, 0x0A]);
    }

    #[test]
    fn test_two_column_accented_french_alignment() {
        // "Reçu :" has 6 chars but 7 UTF-8 bytes (ç = 2 bytes in UTF-8).
        // In CP1252, ç is 1 byte, so the printed width is 6 columns.
        // The column output must be exactly 42 encoded bytes (+ LF), not 43.
        let mut builder = EscPosBuilder::new(); // 42 columns
        builder.two_column("Reçu :", "12345");
        let data = builder.build();
        let text_start = 2; // skip ESC @
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
        let line_ascii = &data_ascii[2..];
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
        let text_start = 2;
        let line_bytes = &data[text_start..];
        let lf_pos = line_bytes.iter().position(|&b| b == 0x0A).unwrap();
        assert_eq!(
            lf_pos, 42,
            "Three-column accented text should be exactly 42 encoded bytes, got {}",
            lf_pos
        );
    }
}
