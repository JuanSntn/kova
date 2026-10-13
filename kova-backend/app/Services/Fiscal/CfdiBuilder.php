<?php

namespace App\Services\Fiscal;

use App\Models\Invoice;
use CfdiUtils\Certificado\Certificado;
use CfdiUtils\CfdiCreator40;
use DomainException;
use DOMDocument;
use Illuminate\Support\Facades\Log;
use PhpCfdi\Credentials\Credential;

final class CfdiBuilder
{
    public function build(
        Invoice $invoice,
        Credential $credential,
    ): string {
        if (! $invoice->place_of_issue_postal_code || ! $invoice->issued_timezone) {
            throw new DomainException('La factura no tiene un contexto fiscal de sucursal completo.');
        }
        $invoice->loadMissing(
            'items',
            'order',
        );

        $issuer =
            $invoice->issuer_snapshot;

        $receiver =
            $invoice->receiver_snapshot;

        $creator =
            new CfdiCreator40([
                'Serie' => $invoice->series,

                'Folio' => (string) $invoice->folio,

                'Fecha' => now($invoice->issued_timezone ?: 'UTC')
                    ->format('Y-m-d\TH:i:s'),

                'FormaPago' => $this->paymentForm(
                    $invoice->order->payment_method,
                ),

                'MetodoPago' => 'PUE',

                'Moneda' => 'MXN',

                'TipoDeComprobante' => 'I',

                'Exportacion' => '01',

                'LugarExpedicion' => $invoice->place_of_issue_postal_code,
            ]);

        $creator->putCertificado(
            new Certificado(
                $credential
                    ->certificate()
                    ->pem(),
            ),
            true,
        );

        $comprobante =
            $creator->comprobante();

        $comprobante->addEmisor([
            'RegimenFiscal' => $issuer['tax_regime_code'],
        ]);

        $comprobante->addReceptor([
            'Rfc' => $receiver['rfc'],

            'Nombre' => $receiver['legal_name'],

            'DomicilioFiscalReceptor' => $receiver['postal_code'],

            'RegimenFiscalReceptor' => $receiver['tax_regime_code'],

            'UsoCFDI' => $receiver['cfdi_use_code'],
        ]);

        foreach (
            $invoice->items as $item
        ) {
            $concept =
                $comprobante->addConcepto([
                    'ClaveProdServ' => $item->product_service_code,

                    'Cantidad' => number_format(
                        (float) $item->quantity,
                        6,
                        '.',
                        '',
                    ),

                    'ClaveUnidad' => $item->unit_code,

                    'Unidad' => $item->unit_name,

                    'Descripcion' => $item->description,

                    'ValorUnitario' => number_format(
                        (float) $item->unit_value,
                        2,
                        '.',
                        '',
                    ),

                    'Importe' => number_format(
                        (float) $item->amount,
                        6,
                        '.',
                        '',
                    ),

                    'ObjetoImp' => $item->tax_object_code,
                ]);

            if (
                $item->tax_object_code
                === '02'
            ) {
                $factor =
                    $item->tax_factor_type
                    === 'exempt'
                    ? 'Exento'
                    : 'Tasa';

                $attributes = [
                    'Base' => number_format(
                        (float) $item->tax_base,
                        6,
                        '.',
                        '',
                    ),

                    'Impuesto' => $item->tax_code
                        ?: '002',

                    'TipoFactor' => $factor,
                ];

                if (
                    $factor === 'Tasa'
                ) {
                    $attributes['TasaOCuota'] =
                        number_format(
                            ((float) $item->tax_rate) / 100,
                            6,
                            '.',
                            '',
                        );

                    $attributes['Importe'] =
                        number_format(
                            (float) $item->tax_amount,
                            6,
                            '.',
                            '',
                        );
                }

                $concept->addTraslado(
                    $attributes,
                );
            }
        }

        $creator->addSumasConceptos(
            null,
            2,
        );

        $creator->addSello(
            $credential
                ->privateKey()
                ->pem(),

            $credential
                ->privateKey()
                ->passPhrase(),
        );

        $creator
            ->moveSatDefinitionsToComprobante();

        $xml =
            $creator->asXml();

        $this->validate(
            invoice: $invoice,
            creator: $creator,
            xml: $xml,
        );

        return $xml;
    }

    private function validate(
        Invoice $invoice,
        CfdiCreator40 $creator,
        string $xml,
    ): void {
        $assertions =
            $creator->validate();

        if (
            ! $assertions->hasErrors()
        ) {
            return;
        }

        $errors = [];

        foreach (
            $assertions->errors() as $error
        ) {
            $errors[] = [
                'code' => $error->getCode(),

                'title' => $error->getTitle(),

                'explanation' => $error->getExplanation(),
            ];
        }

        /*
         * CfdiUtils 3.x puede reportar XSD01 aun cuando
         * el XML sí valida correctamente contra el
         * esquema oficial local de CFDI 4.0.
         *
         * Sólo permitimos continuar cuando:
         *
         * 1. Todos los errores son XSD01.
         * 2. DOM/libxml valida el mismo XML contra
         *    el cfdv40.xsd local.
         *
         * Cualquier otra validación de CfdiUtils
         * continúa siendo bloqueante.
         */
        $onlyXsdErrors =
            $errors !== []
            &&
            collect(
                $errors,
            )->every(
                fn (array $error): bool => $error['code'] === 'XSD01',
            );

        if (
            $onlyXsdErrors
            &&
            $this->validateLocalXsd(
                $xml,
            )
        ) {
            Log::warning(
                'CFDI XSD01 descartado tras validación local exitosa.',
                [
                    'invoice_id' => $invoice->id,

                    'invoice_public_id' => $invoice->public_id,
                ],
            );

            return;
        }

        Log::error(
            'CFDI validation failed',
            [
                'invoice_id' => $invoice->id,

                'invoice_public_id' => $invoice->public_id,

                'errors' => $errors,
            ],
        );

        throw new DomainException(
            'El CFDI no pasó la validación estructural de Kova.',
        );
    }

    private function validateLocalXsd(
        string $xml,
    ): bool {
        $schema =
            base_path(
                'vendor/eclipxe/cfdiutils/build/resources/www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd',
            );

        if (
            ! is_file(
                $schema,
            )
        ) {
            return false;
        }

        $previous =
            libxml_use_internal_errors(
                true,
            );

        libxml_clear_errors();

        try {
            $document =
                new DOMDocument;

            if (
                ! $document->loadXML(
                    $xml,
                    LIBXML_NONET,
                )
            ) {
                return false;
            }

            return $document
                ->schemaValidate(
                    $schema,
                );
        } finally {
            libxml_clear_errors();

            libxml_use_internal_errors(
                $previous,
            );
        }
    }

    private function paymentForm(
        string $method,
    ): string {
        return match ($method) {
            'cash' => '01',

            'card' => '04',

            'transfer' => '03',

            default => '99',
        };
    }
}
