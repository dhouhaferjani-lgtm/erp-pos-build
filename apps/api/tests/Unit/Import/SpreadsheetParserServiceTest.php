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
}
