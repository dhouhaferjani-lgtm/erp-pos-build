<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Modules\Import\Services\SpreadsheetParserService;
use PHPUnit\Framework\TestCase;

class SpreadsheetParserServiceTest extends TestCase
{
    private SpreadsheetParserService $parser;

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SpreadsheetParserService;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function makeCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'parser_test_');
        if ($path === false) {
            $this->fail('Could not create temp file');
        }
        $csvPath = $path.'.csv';
        rename($path, $csvPath);
        file_put_contents($csvPath, $content);
        $this->tempFiles[] = $csvPath;

        return $csvPath;
    }

    public function test_parses_comma_delimited_csv(): void
    {
        $path = $this->makeCsv("name,sku,sale_price\nWidget,W-1,10.00\n");

        $result = $this->parser->parse($path);

        $this->assertSame(['name', 'sku', 'sale_price'], $result['headers']);
        $this->assertSame('Widget', $result['rows'][1]['name']);
        $this->assertSame('10.00', $result['rows'][1]['sale_price']);
    }

    public function test_parses_semicolon_delimited_csv(): void
    {
        $path = $this->makeCsv("name;sku;sale_price\nProduit;P-1;10,00\n");

        $result = $this->parser->parse($path);

        $this->assertSame(['name', 'sku', 'sale_price'], $result['headers']);
        $this->assertSame('Produit', $result['rows'][1]['name']);
        $this->assertSame('10,00', $result['rows'][1]['sale_price']);
    }

    public function test_parses_tab_delimited_csv(): void
    {
        $path = $this->makeCsv("name\tsku\tsale_price\nWidget\tW-1\t10.00\n");

        $result = $this->parser->parse($path);

        $this->assertSame(['name', 'sku', 'sale_price'], $result['headers']);
        $this->assertSame('Widget', $result['rows'][1]['name']);
    }

    public function test_strips_utf8_bom_from_first_header(): void
    {
        $path = $this->makeCsv("\u{FEFF}name,sku\nWidget,W-1\n");

        $result = $this->parser->parse($path);

        $this->assertSame(['name', 'sku'], $result['headers']);
    }

    public function test_crlf_line_endings_do_not_pollute_last_column(): void
    {
        $path = $this->makeCsv("name,sku,is_active\r\nWidget,W-1,true\r\n");

        $result = $this->parser->parse($path);

        $this->assertSame('true', $result['rows'][1]['is_active']);
    }

    public function test_quoted_field_with_embedded_newline_stays_in_one_row(): void
    {
        $path = $this->makeCsv("name,description,sku\n\"Widget\",\"line one\nline two\",W-1\n");

        $result = $this->parser->parse($path);

        $this->assertCount(1, $result['rows']);
        $this->assertSame("line one\nline two", $result['rows'][1]['description']);
        $this->assertSame('W-1', $result['rows'][1]['sku']);
    }

    public function test_quoted_field_containing_delimiter_does_not_confuse_detection(): void
    {
        // Semicolon-delimited file whose first data cell contains commas
        $path = $this->makeCsv("name;description;sku\nWidget;\"a, b, c, d, e\";W-1\n");

        $result = $this->parser->parse($path);

        $this->assertSame(['name', 'description', 'sku'], $result['headers']);
        $this->assertSame('a, b, c, d, e', $result['rows'][1]['description']);
    }

    public function test_skips_blank_lines(): void
    {
        $path = $this->makeCsv("name,sku\nWidget,W-1\n\n\nGadget,G-1\n");

        $result = $this->parser->parse($path);

        $this->assertCount(2, $result['rows']);
    }

    public function test_pads_short_rows_to_header_length(): void
    {
        $path = $this->makeCsv("name,sku,barcode\nWidget,W-1\n");

        $result = $this->parser->parse($path);

        $this->assertSame('', $result['rows'][1]['barcode']);
    }

    public function test_parses_cp1252_encoded_csv_by_converting_to_utf8(): void
    {
        $accentedName = "Crème solaire à l'abricot é è ç";
        $accentedHeader = 'désignation';
        $cp1252Header = mb_convert_encoding($accentedHeader, 'Windows-1252', 'UTF-8');
        $cp1252Name = mb_convert_encoding($accentedName, 'Windows-1252', 'UTF-8');
        $content = "{$cp1252Header};sku;type\n{$cp1252Name};SKU-1;\"Home; Garden\"\n";
        $path = $this->makeCsv($content);

        $result = $this->parser->parse($path);

        $this->assertSame([$accentedHeader, 'sku', 'type'], $result['headers']);
        $this->assertTrue(mb_check_encoding($result['headers'][0], 'UTF-8'));
        $this->assertSame($accentedName, $result['rows'][1][$accentedHeader]);
        $this->assertTrue(mb_check_encoding($result['rows'][1][$accentedHeader], 'UTF-8'));
        $this->assertSame('Home; Garden', $result['rows'][1]['type']);
    }

    public function test_utf8_file_with_accented_characters_is_not_double_converted(): void
    {
        $path = $this->makeCsv("name,sku\nCafé,C-1\n");

        $result = $this->parser->parse($path);

        $this->assertSame('Café', $result['rows'][1]['name']);
    }

    public function test_mixed_encoding_rows_convert_per_value_without_corrupting_valid_utf8_rows(): void
    {
        // Row 1 is already valid UTF-8 ("Café"); row 2 is raw CP1252 bytes
        // ("Crème" with 0xE8 for "è" etc). A whole-file encoding check would
        // see the file as "not valid UTF-8" (because of row 2) and re-decode
        // EVERYTHING as CP1252, mangling the already-correct "Café" into
        // "CafÃ©". Per-value conversion must leave row 1 untouched while
        // still fixing row 2.
        $cp1252Name = mb_convert_encoding('Crème', 'Windows-1252', 'UTF-8');
        $content = "name,sku\nCafé,C-1\n{$cp1252Name},C-2\n";
        $path = $this->makeCsv($content);

        $result = $this->parser->parse($path);

        $this->assertSame('Café', $result['rows'][1]['name']);
        $this->assertSame('Crème', $result['rows'][2]['name']);
        $this->assertTrue(mb_check_encoding($result['rows'][1]['name'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($result['rows'][2]['name'], 'UTF-8'));
    }
}
