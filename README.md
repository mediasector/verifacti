# Verifacti Integration Module for Perfex CRM

Integración avanzada con la API de Verifacti (facturación electrónica española) para Perfex CRM.

## Características principales
- Envío controlado (no automático) de facturas al marcar como enviadas o al enviarlas por email (con forzado para borradores en "Guardar & Enviar" para obtener QR antes del PDF).
- Clasificación automática F1 / F2 (simplificadas) según normativa (≤ 3000€ sin NIF => F2, >3000€ sin NIF bloqueado).
- Soporte de notas de crédito (abonos) como rectificativas tipo R1 con inversión de signo en bases y cuotas.
- Agrupación inteligente de líneas por tipo impositivo y soporte de operaciones exentas con códigos E* (E1–E...); sin remapeo forzado.
- Hash completo e independiente hash fiscal para controlar idempotencia y bloquear modificaciones fiscales (obliga a emitir rectificativa).
- Polling síncrono para esperar la generación del QR antes de adjuntar/enviar el PDF (facturas y notas de crédito).
- Pre‐chequeo remoto (estado) para evitar duplicados antes de crear.
- Soporte de cancelación con motivo y persistencia de respuesta.
- Esquema auto‐sanable: crea/añade columnas si faltan (fallback si install.php no corrió).
- Logging enriquecido con http_status, estado remoto, códigos de error y trazas debug.
- Inyección del QR en múltiples hooks PDF (facturas y notas de crédito) para compatibilidad entre versiones de Perfex.

## Requisitos
- Perfex CRM (probado con versiones recientes 3.x; puede requerir ajustes menores en otras versiones).
- PHP 7.4+ (ideal 8.0+).
- Acceso a la API de Verifacti (clave API válida).

## Instalación
1. Copiar la carpeta `verifacti` dentro de `modules/` de tu instalación de Perfex CRM.
2. Verificar permisos correctos (lectura para el usuario del servidor web).
3. Iniciar sesión como administrador y activar el módulo desde el panel de módulos; el hook de activación ejecutará `install.php` (creación de tabla `verifacti_invoices` y `verifacti_api_logs`).
4. Ir a Configuración > (Sección del módulo) e introducir la API Key de Verifacti.
5. (Opcional) Definir constantes en `application/config/my_constants.php`:
   ```php
   define('VERIFACTI_MANUAL_SEND_ENABLED', false); // Si true, añade checkbox en formulario de envío email factura
   define('VERIFACTI_F2_MAX_TOTAL', 3000);         // Umbral para factura simplificada F2
   define('VERIFACTI_QR_WAIT_SECONDS', 18);        // Tiempo máximo de espera para QR
   ```

## Flujo de Envío
1. El usuario marca la factura como enviada o pulsa "Enviar por Email".
2. Si está en borrador y es un envío inmediato (no programado), se fuerza el registro para obtener el QR antes de generar el PDF.
3. Se construye payload (agregando líneas) y se calcula doble hash (`last_payload_hash`, `last_fiscal_hash`).
4. Si existe ya un registro remoto (pre‐check) se sincroniza localmente y se omite envío.
5. En caso de nuevo envío se hace POST. Si cambia algo no fiscal y hay `verifacti_id`, se intenta PUT (modify) sólo si el hash fiscal no cambió.
6. Se hace polling para obtener QR (local y endpoint de estado) antes de enviar email/PDF.

## Notas de Crédito (Rectificativas R1)
- Se envían siempre como `tipo_factura = R1`.
- Líneas y totales se convierten a valores negativos (bases y cuotas) para reflejar abono.
- Se referencia la factura original si se creó a partir de una existente (cuando Perfex provee el ID original).
- Usa el mismo mecanismo de polling y QR.

## Reglas Fiscales Implementadas
- Bloqueo factura > 3000€ sin NIF destinatario (no puede ser simplificada F2).
- Clasificación F2 automática (sin NIF y total ≤ umbral).
- No se envían borradores salvo forzado en envío directo para obtener QR.
- Cambios fiscales tras registro: NO se hace modify; se debe emitir rectificativa.

## Estructura de Tabla Principal (`verifacti_invoices`)
Campos clave: `invoice_id`, `credit_note_id`, `verifacti_id`, `status`, `qr_url`, `qr_image_base64`, `last_payload_hash`, `last_fiscal_hash`, `mod_count`, `estado_api`, `error_code`, `error_message`, fechas de control, cancelación (`canceled_at`, `cancel_reason`, `cancel_response`).

## Hooks Principales Utilizados
- Envío: `invoice_object_before_send_to_client`, variantes `after_invoice_sent` / `invoice_sent_to_client` / `invoice_email_sent`.
- Notas de crédito: múltiples `after_create_credit_note`, `after_credit_note_added`, `after_credit_note_updated`, `credit_note_sent`, etc.
- PDF Injection: `invoice_pdf_after_invoice_header_number` y variedad de `credit_note_pdf_after_*`.
- Validaciones: `pre_controller` (bloqueo >3000 sin NIF, bloqueo merge).
- Cron: `after_cron_run` (procesos pendientes).

## Códigos de Exención
Detecta tokens `E*` en el nombre del impuesto 0%. Ejemplos aceptados:
- `IVA 0% EXENTO E2|0.00`
- `Servicio Exportación (E5)|0.00`
- `IVA 0% [E3]|0.00`
Si no se encuentra token se asigna `E1` por defecto.

## Cancelación
`cancelInvoice($invoice_id, $reason)` realiza llamada a la API y actualiza estado local (`canceled_at`). Mantiene respuesta completa para auditoría.

## Actualizaciones / Migraciones
El método `ensureInvoiceSchema()` agrega columnas faltantes en caliente. Para despliegues limpios se utiliza `install.php`.

## Registro de Cambios
Ver `CHANGELOG.md`.

## Roadmap (Ideas Futuras)
- Webhooks para estados asincrónicos AEAT.
- Retries/backoff en errores 500.
- UI para reintento manual de obtención de QR.
- Configuración de tipo_rectificativa variable (I/S, etc.).
- Validación explícita de máximo 12 líneas agregadas (normativa TicketBAI en algunas jurisdicciones / escenarios SII).

## Desarrollo / Contribución
Ver `CONTRIBUTING.md`.

## Seguridad
Ver `SECURITY.md`.

## Licencia
Distribuido bajo licencia MIT (ver `LICENSE`).

---
© 2025 Media Sector y contribuidores. Este módulo no es oficial de Perfex CRM ni de Verifacti.