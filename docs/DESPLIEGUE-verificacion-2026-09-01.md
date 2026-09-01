# Despliegue — Módulo de Verificación (auditoría de aliados)

Fecha: 2026-09-01
Rama: `feature/verificacion-auditoria`
Spec: `docs/superpowers/specs/2026-08-31-verificacion-auditoria-design.md`
Plan: `docs/superpowers/plans/2026-08-31-verificacion-auditoria.md`

Este checklist cubre **solo** lo que el módulo de Verificación agrega. No reemplaza a
`DESPLIEGUE-CHECKLIST-2026-07-21.md`.

---

## Los tres pasos que no viajan en el código

Estos son los que se olvidan, porque ninguno está en un manifiesto de dependencias ni
aparece en un `git diff` del servidor.

- [ ] **1. Aplicar el DDL en la RDS.**
      `sql/008_verificacion_auditoria.sql` crea `verificacion_auditoria` y
      `verificacion_auditoria_detalle` en `INTEGRACION`.
      Es idempotente. Se puede pegar en SSMS o correr:
      ```
      php sql/ddl_verificacion_auditoria.php
      ```
      (el runner existe porque `sqlsrv` no acepta lotes separados por `GO`).

      **Ya aplicado el 2026-09-01 desde desarrollo.** Como la RDS es la misma para dev,
      staging y producción, este paso probablemente ya está hecho — el runner lo confirma
      sin romper nada si se corre otra vez.

- [ ] **2. Copiar la carpeta `fpdf/`.**
      FPDF 1.86 está vendorizado a mano porque no hay Composer. Va completa:
      `fpdf/fpdf.php` **y `fpdf/font/`** (sin las métricas de las fuentes base no dibuja
      nada). Si se despliega por ZIP, comprobar que la carpeta viajó:
      ```
      php -r "require 'fpdf/fpdf.php'; echo FPDF::VERSION;"
      ```
      Esperado: `1.86`. (Ojo: es `FPDF::VERSION`, constante de clase; `FPDF_VERSION` no existe.)

- [ ] **3. Definir `MAIL_AUDITORIA_TO` en el `conexion/config_mail.php` del servidor.**
      Ese archivo **no se versiona**, así que la constante no llega sola: hay que
      escribirla a mano en cada entorno.
      ```php
      define('MAIL_AUDITORIA_TO', ['correo1@stanton.co', 'correo2@stanton.co']);
      ```
      Con la lista vacía el módulo **funciona y guarda**, pero no envía: falla con
      "No hay destinatarios configurados" y lo dice en la respuesta. Es deliberado —
      preferible a mandar a nadie en silencio.

---

## Antes del primer envío real

- [ ] Definir `MAIL_TEST_TO` con un correo propio. Mientras esté definido, **todo el correo
      va solo ahí**, sin importar `MAIL_AUDITORIA_TO`. Es el mismo interruptor que ya usa
      Codificación.
- [ ] Cerrar una verificación de prueba y **abrir el correo recibido**: que el asunto nombre
      al aliado, que el PDF venga adjunto y que se abra y se lea bien.
- [ ] **Quitar `MAIL_TEST_TO`** cuando esté verificado. Mientras siga definido, los
      destinatarios reales no reciben nada.
- [ ] Confirmar que el `config_mail.php` real quedó con los destinatarios y **sin**
      `MAIL_TEST_TO`.

---

## Cómo se usa (para Auditoría Interna)

El auditor entra al portal **con las credenciales del aliado que va a auditar** — la sesión
no lo distingue de un aliado real. Por eso:

- La sección se enciende agregando `?auditoria=1` a la URL del dashboard:
  `.../dashboard.php?auditoria=1` → aparece **VERIFICACIÓN** al final del menú lateral.
- Se apaga con `?auditoria=0`, sin cerrar sesión.
- El nombre del auditor **se escribe a mano**, porque no hay forma de deducirlo de la sesión.

`?auditoria=1` es **visibilidad, no seguridad**: cualquiera que conozca el parámetro puede
escribirlo. Lo que protege los endpoints es el guard de sesión y el CSRF, que van en cada
uno de los tres.

---

## Verificación después de desplegar

```
php tests/verificacion_validar_test.php
php tests/verificacion_persistencia_test.php
php tests/verificacion_paquete_test.php
php tests/verificacion_pdf_test.php
php tests/verificacion_guard_test.php
php tests/verificacion_scope_test.php
php tests/verificacion_envio_test.php
```

Esperado: `RESULTADO: OK` en los siete. Ninguno envía correo; los que escriben en la base
usan el proveedor `'__TEST__'` y limpian al terminar.

Comprobar que no quedó basura:
```
php -r "require 'conexion/conexion_integracion.php'; \
  $s=sqlsrv_query($dbConnect,\"SELECT COUNT(*) n FROM verificacion_auditoria WHERE proveedor='__TEST__'\"); \
  echo sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC)['n'], PHP_EOL;"
```
Esperado: `0`.

---

## Rollback

- **Código:** revertir los commits de `feature/verificacion-auditoria`. Nada del módulo se
  ejecuta si `$MODO_AUDITORIA` es falso, y sin `?auditoria=1` nunca lo es: quitar la sección
  del menú basta para desactivarlo sin tocar la base.
- **DDL:** no hace falta rollback. Las dos tablas son nuevas y aditivas; ninguna otra parte
  del portal las consulta.
