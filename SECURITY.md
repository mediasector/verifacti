# Política de Seguridad

## Reporte de Vulnerabilidades
Si encuentras una vulnerabilidad:
1. NO abras un issue público.
2. Envía un email a security@mediasector.example (sustituir por correo real) con:
   - Descripción detallada.
   - Pasos de reproducción.
   - Impacto potencial.
   - CVSS estimado (opcional).
3. Recibirás acuse en 72h y un plan de respuesta en 7 días.

## Alcance
Incluye:
- Inyección de código en hooks / payloads.
- Exposición de datos (NIF, QR, UUID) no autorizada.
- Escalada de privilegios vía endpoints internos.
- Omisión de validaciones fiscales (ej. envío de borradores sin forzar / bypass >3000 sin NIF).

Excluye:
- Versiones antiguas no soportadas (<2.0.0).
- Problemas derivados de configuración incorrecta del servidor (permisos, PHP viejo, etc.).

## Buenas Prácticas Internas
- Hash fiscal inmutable para prevenir alteraciones silenciosas.
- Validación y bloqueo merge de facturas.
- Limpieza de NIF y tokens exentos.
- Uso de HTTPS obligatorio para la API Verifacti (delegado a cURL config por defecto de Perfex).

## Divulgación
Se practica divulgación responsable: se corrige primero, se publica el aviso en CHANGELOG después de liberar parche.

## Ciclo de Parche
1. Recepción y triage.
2. Reproducción interna.
3. Rama `security/fix-<issue>` privada.
4. Revisión y merge.
5. Release con incremento de patch version.
6. Aviso en CHANGELOG y comunicación al reportante.

Gracias por ayudar a mantener el proyecto seguro.
