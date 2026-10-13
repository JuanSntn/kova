# Prueba real del ciclo de suscripciones

Este recorrido usa Stripe **Test**, nunca Stripe Live, y una copia temporal de la base SQLite local. No forma parte de la suite normal porque crea recursos reales de prueba en Stripe.

```bash
cd kova-backend
php tests/Support/stripe-subscription-lifecycle.php --test
```

Para ejecutar sólo un tramo durante una depuración, agrega `--scenario=checkout`, `upgrade`, `success-renewal`, `cancellation` o `failed-renewal`.

La prueba se niega a ejecutarse cuando `BILLING_LIVEMODE=true` o la clave no es `sk_test_`/`rk_test_`. Etiqueta los clientes y el reloj con `kova_lifecycle_test`, cancela sus suscripciones y los borra al terminar; la copia de SQLite se elimina siempre.

Verifica, contra la API de Stripe y la base temporal de Kova:

1. La creación de una sesión Checkout y la liberación segura de un intento sin pagar.
2. La adquisición pagada de Business, incluida la provisión de sus extras obligatorios.
3. La cancelación al final de periodo y su reversión.
4. El cambio inmediato Business → Restaurante y el contrato que queda vigente.
5. Una renovación cobrada correctamente al adelantar el reloj de prueba.
6. La segunda cancelación, el fin de periodo y el regreso al plan gratuito sin borrar historial.
7. Una renovación rechazada con tarjeta de prueba, registrada como `past_due` con periodo de gracia.

Stripe emite los eventos durante los avances del reloj. El script usa el mismo sincronizador que usan los webhooks para validar los estados persistidos de Kova; la validación de firma del endpoint está cubierta por `tests/Feature/Billing/StripeWebhookSignatureTest.php`.

Para cargos recurrentes reales, mantén los webhooks de `customer.subscription.*`, `invoice.paid` e `invoice.payment_failed` activos y verifica siempre su firma. Si aplicas impuestos recurrentes en producción, Stripe Tax requiere una configuración y registros fiscales activos antes de que pueda recaudar impuestos.
