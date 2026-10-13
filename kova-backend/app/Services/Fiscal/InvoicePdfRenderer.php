<?php

namespace App\Services\Fiscal;

use CfdiUtils\Nodes\XmlNodeUtils;
use DOMDocument;
use PhpCfdi\CfdiCleaner\Cleaner;
use PhpCfdi\CfdiToPdf\Builders\Html2PdfBuilder;
use PhpCfdi\CfdiToPdf\CfdiDataBuilder;
use RuntimeException;

final class InvoicePdfRenderer
{
    public function __construct(
        private readonly FiscalNumberFormatter $numberFormatter
    ) {}

    public function render(
        string $stampedXml
    ): string {
        /*
         * Limpiamos el XML original recibido del PAC.
         *
         * IMPORTANTE:
         * El XML fiscal real NO se modifica ni se vuelve
         * a guardar. Solamente trabajamos con una copia
         * en memoria destinada a la representación PDF.
         */
        $cleanXml =
            Cleaner::staticClean(
                $stampedXml
            );

        /*
         * El CFDI real conserva:
         *
         * Serie="FAC"
         * Folio="2"
         *
         * Para la representación impresa usamos:
         *
         * Serie="FAC"
         * Folio="000002"
         *
         * De esta forma CfdiToPdf mostrará visualmente:
         *
         * FAC-000002
         *
         * sin alterar el CFDI timbrado.
         */
        $displayXml =
            $this->withFormattedFolio(
                $cleanXml
            );

        $node =
            XmlNodeUtils::nodeFromXmlString(
                $displayXml
            );

        $data =
            (new CfdiDataBuilder())
            ->build(
                $node
            );

        return (
            new Html2PdfBuilder()
        )->buildPdf(
            $data
        );
    }

    private function withFormattedFolio(
        string $xml
    ): string {
        $previousUseInternalErrors =
            libxml_use_internal_errors(
                true
            );

        try {
            $document =
                new DOMDocument(
                    '1.0',
                    'UTF-8'
                );

            $loaded =
                $document->loadXML(
                    $xml,
                    LIBXML_NONET
                );

            if (! $loaded) {
                throw new RuntimeException(
                    'No fue posible preparar el CFDI para generar el PDF.'
                );
            }

            $root =
                $document
                ->documentElement;

            if (! $root) {
                throw new RuntimeException(
                    'El CFDI no contiene un nodo Comprobante válido.'
                );
            }

            $folio =
                trim(
                    $root->getAttribute(
                        'Folio'
                    )
                );

            /*
             * Kova administra el folio como entero.
             *
             * Si por alguna razón recibimos un CFDI
             * externo con un folio alfanumérico,
             * no intentamos transformarlo.
             */
            if (
                $folio === '' ||
                ! ctype_digit(
                    $folio
                )
            ) {
                return $xml;
            }

            $root->setAttribute(
                'Folio',
                $this
                    ->numberFormatter
                    ->formatFolio(
                        (int) $folio
                    )
            );

            $displayXml =
                $document->saveXML();

            if (
                $displayXml === false
            ) {
                throw new RuntimeException(
                    'No fue posible preparar el CFDI para generar el PDF.'
                );
            }

            return $displayXml;
        } finally {
            libxml_clear_errors();

            libxml_use_internal_errors(
                $previousUseInternalErrors
            );
        }
    }
}
