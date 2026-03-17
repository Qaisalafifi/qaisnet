<!doctype html>
<html lang="ar">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4; margin: 10mm; }
        body { 
            font-family: DejaVu Sans, sans-serif; 
            font-size: 10pt; 
            direction: rtl; 
            color: #111; 
            margin: 0;
            padding: 0;
        }
        .page { 
            page-break-after: always; 
        }
        table.grid { 
            width: {{ number_format($columns * $cellWidthMm, 2) }}mm; 
            border-collapse: collapse; 
            border-spacing: 0; 
            table-layout: fixed; 
            margin: 0 auto;
        }
        table.grid, table.grid tr, table.grid td { 
            border: 0; 
            padding: 0;
            margin: 0;
        }
        td.card-cell {
            border: none;
            padding: 0;
            vertical-align: top;
            width: {{ number_format($cellWidthMm, 2) }}mm;
            height: {{ number_format($cellHeightMm, 2) }}mm;
            box-sizing: border-box;
        }
        .card {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-repeat: no-repeat;
            background-position: 0 0;
            background-size: 100% 100%;
            background-color: #fff;
            box-sizing: border-box;
        }
        .code {
            position: absolute;
            left: {{ number_format($template->code_x_mm, 2) }}mm;
            top: {{ number_format($template->code_y_mm, 2) }}mm;
            font-size: {{ number_format($template->code_font_size ?? 12, 2) }}pt;
            font-weight: bold;
            text-align: center;
            width: auto;
            min-width: 20mm;
        }
        .password {
            position: absolute;
            left: {{ number_format($template->password_x_mm ?? $template->code_x_mm, 2) }}mm;
            top: {{ number_format($template->password_y_mm ?? ($template->code_y_mm + 6), 2) }}mm;
            font-size: {{ number_format($template->password_font_size ?? 11, 2) }}pt;
            font-weight: bold;
            text-align: center;
            width: auto;
            min-width: 20mm;
        }
        .ltr { direction: ltr; text-align: left; }
    </style>
</head>
<body>
@php
    $pages = $cards->chunk($cardsPerPage);
@endphp

@foreach ($pages as $page)
    @php $pageCards = $page->values(); @endphp
    <div class="page">
        <table class="grid">
            @for ($r = 0; $r < $rows; $r++)
                <tr>
                    @for ($c = 0; $c < $columns; $c++)
                        @php
                            $index = ($r * $columns) + $c;
                            $card = $pageCards[$index] ?? null;
                        @endphp
                        <td class="card-cell">
                            @if ($card)
                                <div class="card" @if($imageData) style="background-image: url('{{ $imageData }}');" @endif>
                                    <div class="code ltr">{{ $card->code }}</div>
                                    @if ($template->include_password)
                                        <div class="password ltr">{{ $card->password ?: '-' }}</div>
                                    @endif
                                </div>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
    </div>
@endforeach
</body>
</html>
