<?php

namespace App\Services\Inventory;

use Spipu\Html2Pdf\Html2Pdf;

final class InventoryIntelligencePdfRenderer
{
    public function render(array $report): string
    {
        $escape = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $number = fn (mixed $value): string => number_format((float) $value, 2, '.', ',');
        $stockRows = collect($report['stock_snapshot'])->map(function (array $stock) use ($escape): string {
            $status = $stock['negative'] ? 'Existencia negativa' : ($stock['below_minimum'] ? 'Por debajo del mínimo' : 'Disponible');
            $target = $stock['suggested_restock'] === null ? '—' : $escape($stock['suggested_restock']).' '.$escape($stock['unit']);

            return '<tr><td>'.$escape($stock['name']).'</td><td>'.$escape($stock['category']).'</td><td class="right">'.$escape($stock['quantity']).' '.$escape($stock['unit']).'</td><td class="right">'.($stock['minimum'] === null ? '—' : $escape($stock['minimum']).' '.$escape($stock['unit'])).'</td><td>'.$status.'</td><td class="right">'.$target.'</td></tr>';
        })->join('');
        $shortageRows = collect($report['shortages'])->map(fn (array $stock): string => '<li><strong>'.$escape($stock['name']).'</strong>: surtir '.$escape($stock['suggested_restock']).' '.$escape($stock['unit']).' para llegar a '.($stock['ideal'] ?? $stock['minimum']).' '.$escape($stock['unit']).'.</li>')->join('');
        $consumedRows = collect($report['top_consumed'])->map(fn (array $item): string => '<tr><td>'.$escape($item['name']).'</td><td class="right">'.$escape($item['quantity']).' '.$escape($item['unit']).'</td></tr>')->join('');
        $productRows = collect($report['top_products'])->map(fn (array $item): string => '<tr><td>'.$escape($item['name']).'</td><td class="right">'.$item['units'].'</td><td class="right">$'.$number($item['sales_total']).'</td></tr>')->join('');
        $alerts = collect($report['alerts'])->map(fn (string $alert): string => '<li>'.$escape($alert).'</li>')->join('');

        $html = '<style>
            body { font-family: dejavusans; color: #202124; font-size: 9pt; }
            h1 { font-size: 18pt; margin: 0 0 4px; } h2 { font-size: 12pt; margin: 20px 0 8px; }
            .muted { color: #6b7280; } .metrics { width: 100%; margin: 16px 0; } .metrics td { width: 25%; padding: 10px; border: 1px solid #e5e7eb; }
            .metric-label { color: #6b7280; font-size: 8pt; } .metric-value { font-size: 14pt; font-weight: bold; }
            table { width: 100%; border-collapse: collapse; } th { background: #f3f4f6; text-align: left; } th, td { border: 1px solid #e5e7eb; padding: 7px; vertical-align: top; } .right { text-align: right; }
            .alerts { background: #fff7ed; border: 1px solid #fed7aa; padding: 8px 14px; } ul { margin: 4px 0; padding-left: 18px; }
        </style><page backtop="12mm" backbottom="10mm" backleft="10mm" backright="10mm">
            <h1>Reporte inteligente de inventario</h1>
            <p class="muted">Sucursal: '.$escape($report['branch']['name']).' · Período: '.$escape($report['period']['date_from']).' al '.$escape($report['period']['date_to']).' · Generado: '.$escape($report['generated_at']).'</p>
            <table class="metrics"><tr>
                <td><div class="metric-label">Insumos</div><div class="metric-value">'.$report['summary']['supplies'].'</div></td>
                <td><div class="metric-label">Por debajo del mínimo</div><div class="metric-value">'.$report['summary']['below_minimum'].'</div></td>
                <td><div class="metric-label">Ventas del período</div><div class="metric-value">$'.$number($report['summary']['sales_total']).'</div></td>
                <td><div class="metric-label">Ticket promedio</div><div class="metric-value">$'.$number($report['summary']['average_ticket']).'</div></td>
            </tr></table>
            '.($alerts === '' ? '' : '<div class="alerts"><strong>Alertas para revisar</strong><ul>'.$alerts.'</ul></div>').'
            <h2>Faltantes y sugerencia de surtido</h2>'.($shortageRows === '' ? '<p>No hay insumos por debajo de su mínimo.</p>' : '<ul>'.$shortageRows.'</ul>').'
            <h2>Existencias actuales</h2><p class="muted">Esta sección es una fotografía al momento de generar el reporte. El período aplica a ventas y movimientos.</p>
            <table><tr><th>Insumo</th><th>Categoría</th><th class="right">Existencia</th><th class="right">Mínimo</th><th>Estado</th><th class="right">Surtir</th></tr>'.$stockRows.'</table>
            <h2>Consumo registrado en el período</h2>'.($consumedRows === '' ? '<p>Sin consumos registrados.</p>' : '<table><tr><th>Insumo</th><th class="right">Cantidad</th></tr>'.$consumedRows.'</table>').'
            <h2>Productos más vendidos</h2>'.($productRows === '' ? '<p>Sin ventas cobradas en este período.</p>' : '<table><tr><th>Producto</th><th class="right">Unidades</th><th class="right">Ventas</th></tr>'.$productRows.'</table>').'
        </page>';

        $pdf = new Html2Pdf('P', 'A4', 'es');
        $pdf->writeHTML($html);

        return $pdf->output('', 'S');
    }
}
