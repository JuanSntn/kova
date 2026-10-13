# Validación del ciclo de suscripciones de Kova

Fecha: 17 de septiembre de 2026. Alcance: código backend/frontend, dependencias instaladas, pruebas aisladas y documentación de Stripe. Esta revisión no certifica la configuración remota del Dashboard, el scheduler desplegado ni recorridos reales de Stripe Test Clocks.

## Dictamen

La separación de catálogo versionado, contratos, beneficios y base/extras es adecuada. La implementación todavía tiene defectos que impiden dar por cerrado el ciclo comercial. Que la suite existente pase no equivale a haber validado todos los escenarios temporales o concurrentes.

En esta revisión sólo se añadieron pruebas de diagnóstico y este documento; no se cambiaron reglas de producción, contratos, datos locales ni recursos remotos.

## Evidencia ejecutada

- Suite existente: **173 pruebas, 839 assertions, aprobada**.
- Frontend: `npm run build` aprobado; advertencia de bundle superior a 500 kB.
- Diagnósticos adicionales: **5 pruebas fallan** al exigir las invariantes descritas abajo.
- Base de pruebas: SQLite `:memory:`, forzada en `Tests/TestCase.php`. Las pruebas de conexiones usan archivo temporal propio.
- Archivo de reproducción: `kova-backend/tests/Audit/StripeLifecycleAuditTest.php`.
- Las reproducciones están fuera de Unit/Feature para poder ejecutarlas explícitamente y no confundirlas con la suite anterior. Deben convertirse en regresiones de la suite normal al corregir cada defecto.

```bash
cd kova-backend
php artisan test tests/Audit/StripeLifecycleAuditTest.php --filter=test_audit
```

## Hallazgos prioritarios

### P1 — Extras puede adelantarse a un upgrade no pagado

`app/Services/Subscriptions/StripeContractSynchronizer.php:182` busca cualquier cambio efectivo pendiente cuando llega EXTRAS sin pending_update. Puede crear el contrato destino y moverle el enlace de extras sin que BASE haya confirmado el nuevo precio. Un webhook normal o la reconciliación periódica de extras puede activar este camino durante un upgrade pendiente de pago.

Reproducción: contrato 2, upgrade processing, EXTRAS remoto todavía del contrato original. El enlace termina apuntando al contrato 3. No demuestra por sí sola acceso superior gratuito: demuestra corrupción de la coordinación base/extras y una transición prematura. Puede bloquear la recuperación y provocar que BASE encuentre que le faltan extras.

Corrección requerida: distinguir confirmación del nuevo componente de una actualización ordinaria del anterior. Para upgrade inmediato, mover extras sólo tras confirmar base y la operación remota de transición. Para schedules, verificar fase/oferta efectiva; no basta comparar fecha y status.

### P1 — El consumo incluido se reinicia al cambiar de contrato durante el mes

`ConsumptionPeriod` conserva el ancla; `StampUsageSummaryService` y `StampCapacityService` buscan el contador por el nuevo `plan_subscription_id`. No se trasladan ni se comparten los consumos del período con el contrato anterior.

Reproducción: 10 timbres consumidos, upgrade confirmado en el mismo período, resumen nuevo devuelve `used = 0`. La reserva usa la misma partición y permite un cupo nuevo completo. Conservar el ancla temporal no conserva el contador.

Corrección requerida: establecer continuidad del consumo por período de derecho comercial o un arrastre explícito, sin duplicar usage_events ni los cargos ya generados. Mantener los snapshots financieros históricos.

### P1 — La expiración de un pending update no está correlacionada con el intento

`StripeWebhookProcessor.php:176–214` recibe pending_update_expired y cancela el primer cambio inmediato processing del contrato. No comprueba rol base/extras, invoice del intento, fecha del evento ni si Stripe tiene ahora otro pending_update.

La prueba de unidad del handler muestra que cancela un cambio sin evidencia de que sea el intento expirado. El escenario peligroso es un evento antiguo entregado después de iniciar otro upgrade; no se ejecutó ese recorrido completo contra Stripe. También un pending update de extras puede entrar a esta rama destinada a planes.

Corrección requerida: persistir/correlacionar el intento remoto y consultar estado actual. Terminar sólo el cambio al que pertenece la expiración; preservar intentos posteriores y transiciones ya aplicadas. Si falta evidencia, dejar reconciliación explícita en lugar de cancelar arbitrariamente.

### P1 — Verificación parcial borra el inicio de la indisponibilidad

`StripeContractSynchronizer::refreshAccess()` y `ContractService::activate()` limpian `billing_verification_failed_since` al sincronizar un componente. `BillingReconciler::run()` decide al final si ambos componentes pudieron verificarse.

Reproducción: fallo iniciado hace 12 horas; se consulta BASE correctamente; la fecha de fallo se convierte en null antes de comprobar EXTRAS. Si EXTRAS continúa fallando, el reconciliador vuelve a escribir ahora como inicio. Se puede reiniciar la ventana de tolerancia, o suspender prematuramente al evaluar gracia con información parcial.

Corrección requerida: registrar éxito de verificación únicamente tras comprobar todos los componentes exigidos. Separar la sincronización de cada componente de la decisión agregada de acceso.

### P1 — incomplete_expired no libera el intento inicial

`StripeContractSynchronizer.php:175` clasifica incomplete_expired como deuda. El cierre terminal está reservado a canceled. El contrato inicial conserva `pending_tenant_id`.

Reproducción: primera contratación termina remotamente incomplete_expired; el slot pendiente permanece ocupado. Si Checkout ya estaba complete, no necesariamente llegará un checkout.session.expired que lo libere. Puede impedir una nueva contratación.

Corrección requerida: cierre terminal idempotente del intento inicial, sin modificar el contrato Free vigente ni tratar un intento no pagado como renovación adeudada.

### P1 — Falta barrera antes de liquidar el consumo final

Inspección de `BillingProcessor::closeExtras()` y `StripeWebhookProcessor::processReadyOperations()`: el cierre usa invoice_now, pero no espera a que todos los movimientos meter del contrato estén reportados, ni considera reservas fiscales inciertas. El webhook procesa close_extras sin incluir meter en esa cola inmediata.

Riesgo: facturar/cerrar extras mientras quedan timbres por reportar. Además, Meters procesa consumo de forma asíncrona. No se comprobó una pérdida monetaria real; sí falta la coordinación local para garantizar la liquidación. Se necesita probar consumo pendiente, cierre y conciliación de la factura final.

### P1/P2 — Concurrencia contractual y leases incompletos

- `StripeBillingGateway::setCancellation()` busca una operación y luego encola con UUID sin una transacción común ni exclusión de la operación opuesta. Dos solicitudes pueden crear operaciones distintas. Un reintento conserva el payload anterior aunque el usuario cambie la selección de sucursales.
- El lock_token del outbox protege la escritura final del outbox, pero los handlers modifican contratos, enlaces o consumo antes de `completeLease()`. Un worker que perdió su lease todavía puede alcanzar esas escrituras comerciales.
- `BillingWebhookEvent` no tiene token de ownership; un worker viejo puede finalizar el processing de otro.
- Las dos pruebas de SQLite existentes usan dos conexiones secuencialmente y tablas simplificadas. No lanzan procesos simultáneos ni ejecutan el servicio real bajo competencia. No demuestran ausencia de carreras en checkout/cancelación/upgrade.

Son hallazgos de inspección; no se ejecutó una prueba multiproceso de estos caminos en esta revisión. Requieren exclusión por contrato y validación del propietario antes de aplicar resultados locales, además de idempotencia remota.

### P2 — Recuperación y experiencia del pago pendiente

- Upgrade guarda invoice_id/invoice_url en el outbox, pero `SubscriptionChange::publicSummary()` y Actividad pendiente no los exponen para el upgrade; el botón Revisar pago está implementado para addons. El historial de facturas sí ofrece otra vía, pero falta una acción junto al upgrade pendiente/3DS.
- Operaciones `reconciliation_required` por superar 23 horas no tienen un despachador general que resuelva su resultado y las termine. Consultar Stripe no implica resolver todas esas operaciones automáticamente.
- Si se pierde el webhook de expiración del upgrade, el reconciliador genérico no termina el cambio processing por sí solo.
- No se encontró auditoría de actor/antes/después para todas las mutaciones principales de billing; el outbox conserva operaciones, pero no sustituye auditoría de usuario.

## Cobertura de los escenarios solicitados

### Contratación y acceso

- Alta inicial: Checkout, pending comercial y activación con base/extras implementados; pruebas de pago no confirmado. Pendiente resolver incomplete_expired.
- Free y Customer: separados correctamente; Customer no concede un plan. Transiciones Free/Paid implementadas, con los riesgos de coordinación anteriores.
- Trials: enum existente; no se encontró un flujo completo para ofrecer/configurar/finalizar trials. No se debe anunciar como soportado.
- Activación de beneficios: EntitlementService/CapacityService y versión contratada existen. La política writable no clasifica explícitamente todos los estados terminales y depende de mantener bien current_tenant_id; debe endurecerse junto con las transiciones.
- Renovación mensual: Stripe renueva y el synchronizer actualiza períodos. Falta certificar ciclos reales con Test Clocks.
- Métodos asíncronos: Checkout complete/unpaid no concede acceso en la prueba existente. No se certificaron todos los métodos habilitados en Dashboard ni su compatibilidad con pending updates.

### Cambios de plan

- Upgrade y prorrateo: pending_if_incomplete + always_invoice implementados.
- Upgrade pagado/recuperado: transición base/extras implementada; falla la protección frente a extras adelantados y continuidad del consumo.
- Upgrade fallido/abandonado: mantiene precio remoto previo; expiración tiene handler pero no correlación suficiente. No debe declararse resuelto en todos los órdenes de entrega.
- Downgrade: schedules y oferta fijada implementados. Hay prueba de renovación con ambos componentes. Falta probar ambos órdenes reales, pagos fallidos y fallos parciales de programación/cancelación.
- Reducción de capacidad: selección de sucursales y bloqueo de catálogo existen. Asignación/activación de usuarios debe incluirse en regresión completa de capacidades.
- Cancelar downgrade: release de schedules implementado; requiere pruebas de fallo parcial y reintentos remotos.
- Mensual/anual: cambio al renovar modelado; annual_enabled está desactivado por defecto. No se habilitó ni certificó anual.
- Precios/versiones: versiones y precios contratados inmutables; pruebas de nueva publicación/retiro sin alterar contrato existente.

### Complementos y consumo

- Addons recurrentes y medidos: modelados; elegibilidad por versión validada. No se encontró flujo de addon de pago único.
- Aumentos: inmediatos, prorrateados y condicionados al pago. Falta certificar recuperación/expiración de todos los pendientes.
- Reducciones: programadas; validan capacidad y sucursales retenidas.
- Uso medido: reservas, eventos, consentimiento de excedentes e idempotencia implementados. Pendientes continuidad de cupo tras upgrade y liquidación final.

### Cancelación y recuperación

- Cancelación al cierre: implementada, conserva acceso contratado y coordina cancel_at de extras.
- Reactivación antes del cierre: implementada; prueba parcial de base/extras e idempotencia. Persisten carreras entre intenciones incompatibles.
- Cancelación inmediata administrativa: no se encontró un flujo propio con política explícita de devolución/crédito.
- Resuscripción después de cancelación voluntaria: hay retorno a Free y contratación nueva. No se certificó el recorrido terminal por deuda, pago posterior y nueva contratación.
- Pago recurrente fallido/gracia/suspensión: siete días implementados y pruebas básicas aprobadas. La tolerancia de 24 h tiene el fallo agregado descrito.
- Pago recuperado: limpieza de deuda/activación implementada; probar facturas múltiples, deuda de cada componente y recuperación después de canceled.
- Cancelación automática por reintentos agotados: la decisión de Stripe depende del Dashboard; no se verificó. El cierre de extras por BASE terminada ya se encola antes del retorno por deuda.
- Dunning: reconciliación de impagos y portal disponibles. Smart Retries, correos y acción final deben verificarse en la cuenta Stripe; código local no demuestra su configuración.
- Pausa: no se encontró política/flujo completo; paused y pause_collection no deben habilitarse sin definir cómo afectan acceso y extras.

### Operación y funciones comerciales adicionales

- 3DS: manejo indirecto por Checkout/facturas; falta acción contextual del upgrade pendiente.
- Renovación próxima: no se encontró handler/avisos propios de invoice.upcoming.
- Historial: listado de invoices de Stripe y enlaces alojados/PDF; no se instala Dompdf.
- Reembolsos/créditos: no se encontró un flujo comercial propio ni política que determine sus efectos en acceso.
- Disputas: no se encontraron handlers de charge.dispute.* ni política comercial.
- Cupones/promociones: no se encontró flujo integral de descuentos y expiración; no equivale a un defecto si no se ofrecen.
- Impuestos: tasa configurada en alta/extras/schedules; validar facturas reales de prorrateo, consumo y redondeo. No se certificó la configuración remota. No activar Stripe Tax automáticamente: requiere registros fiscales y una decisión de migración de la tasa manual.
- Webhooks: firma mediante Cashier, clasificación Received/Handled, deduplicación y CurrentTenant corregidos; pendientes correlación temporal y lease.
- Redirect: no se considera comprobante de pago.
- Fuente financiera: StripeStateReader vuelve a consultar Stripe; contratos y permisos permanecen locales. La lectura remota debe acompañarse de correlación correcta de la operación.
- Estado actual/futuro: se exponen por separado; falta mejor información del pago del upgrade.
- Auditoría: parcial, no completa.
- Idempotencia: hay claves y restricciones; no garantiza serialización de intenciones distintas.
- Resiliencia: retries, outbox y scheduler definidos; falta demostrar su ejecución en despliegue y arreglar los casos descritos.
- Test Clocks: no ejecutados. Pruebas con Carbon y clientes falsos no sustituyen recorridos de Stripe.

## Orden de corrección recomendado

1. Correlación de intentos y confirmación explícita de base/extras, incluido incomplete_expired.
2. Continuidad del consumo durante upgrades y barrera de liquidación final.
3. Decisión agregada de acceso y deuda; tolerancia real de 24 h.
4. Exclusión de intenciones concurrentes, fencing de escrituras y recuperación de operaciones inciertas.
5. UI de pago pendiente, auditoría y configuración de dunning.
6. Stripe Test Clocks y recorridos separados de prueba: alta, renovación pagada/fallida, recuperación, upgrade pagado/fallido/expirado, downgrade, addons, cancelación, reactivación y nueva contratación.

Trials, cupones, pausas y devoluciones son decisiones de producto adicionales. No hace falta implementarlas todas para un SaaS mensual sólido; sí decidir explícitamente cuáles no se ofrecen.

## Referencias contrastadas

- [Pending updates](https://docs.stripe.com/billing/subscriptions/pending-updates): pago condicionado, expiración y métodos compatibles.
- [Cancelaciones](https://docs.stripe.com/billing/subscriptions/cancel): terminalidad, cancelación al cierre y liquidación.
- [Webhooks](https://docs.stripe.com/webhooks): duplicación, reintentos y ausencia de garantía de orden.
- [Test Clocks](https://docs.stripe.com/billing/testing/test-clocks): pruebas temporales remotas.

El CLI instalado no dispone del plugin docs; se consultaron las páginas oficiales mediante la herramienta web. No se cambió la versión del SDK ni la API del proyecto.
