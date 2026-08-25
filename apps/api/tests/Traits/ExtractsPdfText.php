<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Pulls the human-readable text out of a dompdf-produced PDF.
 *
 * WHY THIS EXISTS. Lane C-F0 has to assert that a word does NOT appear on a
 * printed document, and the HTML is only half of the output: the PDF is what the
 * customer and the auditor actually receive. No PDF parser is installed
 * (`composer.json` carries `barryvdh/laravel-dompdf` and nothing else), so the
 * text is extracted here.
 *
 * HOW. dompdf emits FlateDecode'd streams. The page content streams are the ones
 * that carry `BT ` (begin-text) and ` Tf ` (set-font); font programs are skipped by
 * that filter — they inflate too, and a font stream happens to contain the byte
 * pair `TJ`, which is why the filter is on `BT`/`Tf` and not on `TJ`. Inside a
 * content stream every run of glyphs is a `[ (…) … ] TJ` array whose string
 * literals are UTF-16BE (dompdf writes two-byte codes for the embedded DejaVu
 * subset), so each literal is PDF-unescaped and then transcoded to UTF-8.
 *
 * The `[^\]]*` (rather than `.*?`) inner match is deliberate: PCRE blows its
 * backtrack limit on a lazy match over an inflated 1.7 MB stream.
 */
trait ExtractsPdfText
{
    protected function extractPdfText(string $pdf): string
    {
        $text = '';

        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams) === 0) {
            return '';
        }

        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            if ($inflated === false || ! str_contains($inflated, 'BT ') || ! str_contains($inflated, ' Tf ')) {
                continue;
            }

            if (preg_match_all('/\[([^\]]*)\]\s*TJ/', $inflated, $arrays) === 0) {
                continue;
            }

            foreach ($arrays[1] as $array) {
                if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $array, $literals) === 0) {
                    continue;
                }

                foreach ($literals[0] as $literal) {
                    $text .= $this->decodePdfString(substr($literal, 1, -1));
                }

                $text .= "\n";
            }
        }

        return $text;
    }

    private function decodePdfString(string $body): string
    {
        $unescaped = preg_replace_callback(
            '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
            static function (array $match): string {
                $map = [
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                    '(' => '(', ')' => ')', '\\' => '\\',
                ];

                return $map[$match[1]] ?? chr((int) octdec($match[1]));
            },
            $body,
        );

        return mb_convert_encoding($unescaped ?? $body, 'UTF-8', 'UTF-16BE');
    }
}
