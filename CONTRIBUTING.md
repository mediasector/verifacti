# Guía de Contribución
¡Gracias por tu interés en contribuir!

## Flujo de trabajo recomendado
1. Haz un fork del repositorio.
2. Crea una rama descriptiva: `feature/nueva-funcionalidad` o `fix/bug-descripcion`.
3. Añade/actualiza tests si aplica (si se introducen helpers nuevos o lógica compleja, crea tests unitarios en el futuro directorio `tests/`).
4. Asegúrate de que el código sigue el estilo de Perfex/CodeIgniter (PSR-12 aproximado, snake_case en helpers, PascalCase en clases).
5. Actualiza `README.md` y `CHANGELOG.md` si introduces cambios visibles.
6. Crea Pull Request explicando el porqué y el cómo del cambio.

## Estándares de Código
- No introducir dependencias externas sin debatir primero (issue / discusión).
- Evitar lógica duplicada; centralizar en helpers o librerías.
- Prefiere funciones puras donde sea posible para facilitar test.
- Usa logs con prefijo `[Verifacti]` para facilitar filtrado.

## Commits
- Usa mensajes tipo Conventional Commits cuando sea posible:
  - `feat: añade soporte webhook`
  - `fix: corrige cálculo base imponible en exentas`
  - `refactor: extrae hashing fiscal`
  - `docs: aclara sección instalación`
  - `chore: bump versión`

## Issues
Al crear un issue incluir:
- Versión de Perfex.
- Versión del módulo.
- Pasos para reproducir.
- Logs relevantes (si corresponde).

## Seguridad
No publiques claves API reales en ejemplos o issues. Para vulnerabilidades ver `SECURITY.md`.

## Roadmap / Discusión
Revisa el apartado Roadmap en `README.md` y comenta / propone nuevas funciones en issues etiquetados como `enhancement`.

## Licencia
Al contribuir aceptas que tu código se publique bajo licencia MIT del proyecto.
