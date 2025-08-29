# Changelog

Todas las versiones notables de este módulo se documentarán en este archivo.

## [0.2] - 2025-08-29
### Añadido
- Captura y persistencia de `http_status` en logs API.
- Polling para QR en notas de crédito (paridad con facturas).
- Múltiples hooks PDF para insertar QR en notas de crédito.
- Campos de cancelación (`canceled_at`, `cancel_reason`, `cancel_response`).
- Hash fiscal (`last_fiscal_hash`) para bloquear modify cuando hay cambios fiscales.
- Bloqueo de merge de facturas por normativa.

### Cambiado
- Envío ya no automático al crear/actualizar; sólo al enviar o marcar como enviada.
- Lógica de clasificación F2 sin necesidad de NIF y dentro de umbral.
- Destinatario ahora es el cliente (no la propia empresa).
- Reglas modify vs create basadas en hash completo vs fiscal.

### Corregido
- Inserción de QR en PDF faltante en notas de crédito.
- Signo negativo en R1 (bases y cuotas) aplicado para rectificativas.
- Duplicado require eliminado en `verifacti.php`.

### Eliminado
- Remapeo forzado de exenciones E2/E3 a E6.
- Envío automático al crear factura.

---
## [0.1] - 2025-08-10
Versión base inicial con integración funcional, envío de facturas y soporte básico de notas de crédito.

