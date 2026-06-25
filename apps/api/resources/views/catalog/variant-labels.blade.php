@php
    /** @var \App\Modules\Catalog\Domain\Support\LabelSheetFormat $format */
    /** @var array<int, array{blank: bool, product_name: string, name_suffix: string, effective_price: string, barcode_data_uri: string, barcode_value: string, shop_name: string}> $cells */
    $perPage = max(1, $format->rows * $format->cols);
    $pages = array_chunk($cells, $perPage);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
@foreach ($pages as $pageIndex => $pageCells)
    @php
        $rowsOfCells = array_chunk($pageCells, $format->cols);
        $isLastPage = $pageIndex === count($pages) - 1;
    @endphp
    <div style="{{ $isLastPage ? '' : 'page-break-after:always;' }} padding-top:{{ $format->marginTopMm }}mm; padding-left:{{ $format->marginLeftMm }}mm;">
        <table style="table-layout:fixed; border-collapse:collapse;">
            @foreach ($rowsOfCells as $rowCells)
                @php
                    // Vertical gutter: bottom spacing between rows. The final row gets
                    // none so the grid does not overflow past its last row (M1).
                    $rowGutterYmm = $loop->last ? 0 : $format->gutterYmm;
                @endphp
                <tr>
                    @foreach ($rowCells as $cell)
                        <td style="width:{{ $format->labelWidthMm }}mm; height:{{ $format->labelHeightMm }}mm; padding:1mm 1mm {{ $rowGutterYmm + 1 }}mm {{ $loop->first ? '0' : $format->gutterXmm }}mm; vertical-align:top; overflow:hidden;">
                            @if (! $cell['blank'])
                                <div style="font-size:8pt; font-weight:bold;">{{ $cell['product_name'] }}</div>
                                @if ($cell['name_suffix'] !== '')
                                    <div style="font-size:7pt;">{{ $cell['name_suffix'] }}</div>
                                @endif
                                <div style="font-size:9pt; font-weight:bold;">{{ $cell['effective_price'] }}</div>
                                <img src="{{ $cell['barcode_data_uri'] }}" style="max-width:100%; height:10mm;">
                                <div style="font-size:6pt; font-family:DejaVu Sans Mono, monospace;">{{ $cell['barcode_value'] }}</div>
                                <div style="font-size:6pt;">{{ $cell['shop_name'] }}</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
@endforeach
</body>
</html>
