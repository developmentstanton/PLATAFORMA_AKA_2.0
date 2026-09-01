# Módulo de Verificación (Auditoría de aliados)

Fecha: 2026-08-31
Estado: diseño aprobado, pendiente de plan de implementación

## Problema

Auditoría Interna necesita revisar que la plataforma funcione: en concreto, que las
cifras de los cinco informes cuadren contra el ERP para un aliado dado. Hoy esa
revisión no deja rastro en ninguna parte. Se necesita un registro formal, guardado
en base de datos, que al cerrarse se comunique por correo con un PDF adjunto.

El auditor **entra con las credenciales del aliado que audita**. Esa restricción no
es negociable (viene del proceso, no del software) y condiciona todo el diseño:
para la plataforma, el auditor es indistinguible de un aliado real.

## Alcance

Cinco puntos de control, uno por informe:

| clave | Informe |
|-------|---------|
| `g00`  | Ventas |
| `o14`  | Siembra / Stock / Ventas |
| `o45`  | Índice de Ventas |
| `evol` | Evolución Histórica |
| `geo`  | Georreferenciación |

Cada uno se marca **Aprobado / No aprobado / No aplica**, con observación opcional.
Al final, un bloque de comentarios generales para toda la auditoría.

**Fuera de alcance:** Codificación y Documentación no se auditan (decisión explícita
del 2026-08-31). El módulo de Análisis de Pagos tampoco: está oculto del menú.

## Decisiones y sus porqués

### El modo auditoría se enciende por URL

`dashboard.php?auditoria=1` marca `$_SESSION['modo_auditoria'] = true` y con eso
aparece la sección VERIFICACIÓN al final del menú lateral.

Se descartaron dos alternativas. Una tabla de "aliados bajo auditoría" haría que el
aliado marcado viera la sección si entra esos días — justo lo que hay que evitar. Un
usuario auditor con rol propio sería lo más limpio, pero obliga a que los cinco
informes acepten un proveedor distinto al de la sesión, y eso es un refactor
transversal que no paga por sí solo en este momento.

El parámetro no es un control de seguridad y no se pretende que lo sea: cualquiera
que lo conozca puede escribirlo. Es un interruptor de visibilidad, y basta, porque
lo que hay detrás no expone dato alguno del aliado — es un formulario en blanco. Lo
que sí protege el endpoint es el guard de sesión y el CSRF, igual que el resto del
portal.

### El nombre del auditor se pide, no se deduce

La sesión dice que el usuario es el aliado, así que firmar con ella produciría un
registro donde el auditado se audita a sí mismo. El auditor escribe su nombre al
abrir la sección; queda en la cabecera de la auditoría y en el PDF. Se guarda además
`usuario_portal` (con qué credenciales se entró), que es un dato distinto y también
interesa: dice a nombre de quién se navegó.

### Dos tablas, no una con cinco columnas

Cabecera y detalle. Con cinco columnas fijas (`g00_resultado`, `g00_observacion`, …)
sumar un informe mañana sería un `ALTER TABLE` y tocar todas las consultas. Con
detalle en filas, es una fila más.

### Guardado punto por punto

Cada informe se guarda en cuanto el auditor lo marca. Una revisión de cinco informes
contra el ERP no se hace en diez minutos, y perder el avance por un timeout de
sesión (30 min de inactividad en este portal) sería inaceptable.

### Armar y enviar son dos funciones separadas

**El envío de correo es irreversible.** Los tests corren antes que cualquier
validación, así que la única defensa real es que el dato de prueba sea imposible de
enviar por sí mismo:

- `verif_armar_paquete()` compone auditoría + PDF y **no envía nada**. Es lo único
  que ejercitan los tests.
- `verif_enviar()` recibe ese paquete y lo manda. Ningún test la invoca.
- Las filas de prueba usan el proveedor centinela `__TEST__`, que
  `verif_destinatarios()` rechaza devolviendo lista vacía; `verif_enviar()` con
  lista vacía es un error, no un envío silencioso.

No se confía en una bandera "modo test" que alguien pueda olvidar apagar.

## Modelo de datos

`sql/008_verificacion_auditoria.sql`, idempotente, en `INTEGRACION.dbo`.

```sql
CREATE TABLE dbo.verificacion_auditoria (
    id              INT IDENTITY(1,1) NOT NULL,
    proveedor       NVARCHAR(120)  NOT NULL,  -- aliado auditado ($_SESSION['proveedor'])
    usuario_portal  NVARCHAR(50)   NOT NULL,  -- credenciales con las que se entró
    auditor         NVARCHAR(120)  NOT NULL,  -- nombre escrito por el auditor
    comentarios     NVARCHAR(MAX)  NULL,      -- bloque general
    estado          NVARCHAR(20)   NOT NULL DEFAULT 'en_curso'
                    CHECK (estado IN ('en_curso','cerrada')),
    creado_en       DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    cerrado_en      DATETIME2      NULL,
    correo_enviado  BIT            NOT NULL DEFAULT 0,
    CONSTRAINT PK_verificacion_auditoria PRIMARY KEY (id)
);

CREATE TABLE dbo.verificacion_auditoria_detalle (
    id             INT IDENTITY(1,1) NOT NULL,
    auditoria_id   INT            NOT NULL,
    informe        NVARCHAR(10)   NOT NULL
                   CHECK (informe IN ('g00','o14','o45','evol','geo')),
    resultado      NVARCHAR(20)   NOT NULL
                   CHECK (resultado IN ('aprobado','no_aprobado','no_aplica')),
    observacion    NVARCHAR(1000) NULL,
    actualizado_en DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT PK_verificacion_detalle PRIMARY KEY (id),
    CONSTRAINT FK_verificacion_detalle FOREIGN KEY (auditoria_id)
        REFERENCES dbo.verificacion_auditoria (id),
    CONSTRAINT UQ_verificacion_detalle UNIQUE (auditoria_id, informe)
);

CREATE INDEX idx_verificacion_proveedor
    ON dbo.verificacion_auditoria (proveedor, creado_en DESC);
```

`UQ_verificacion_detalle` es lo que hace que re-marcar un informe actualice en vez
de duplicar: el guardado es un `MERGE` sobre esa llave.

`correo_enviado` se separa de `estado` a propósito. Una auditoría puede cerrarse
correctamente y fallar el SMTP; sin esa columna no habría forma de saber cuáles
quedaron sin comunicar ni de reintentarlas.

## Componentes

| Archivo | Responsabilidad |
|---------|-----------------|
| `sql/008_verificacion_auditoria.sql` | DDL idempotente |
| `api/lib_verificacion.php` | Lógica pura: validar, abrir, guardar, armar paquete, resolver destinatarios. Sin efectos de red. No ejecuta nada al incluirse |
| `api/lib_verificacion_pdf.php` | Construcción del PDF sobre FPDF |
| `api/verificacion_abrir.php` | POST: crea o recupera la auditoría en curso del aliado |
| `api/verificacion_guardar.php` | POST: guarda un punto (informe + resultado + observación) |
| `api/verificacion_cerrar.php` | POST: valida completitud, cierra, arma PDF y envía |
| `informes/verificacion.php` | Vista, incluida por `dashboard.php` |
| `fpdf/fpdf.php` | Librería, traída a mano (no hay Composer) |
| `tests/verificacion_*_test.php` | Pruebas |

`lib_verificacion.php` sigue el contrato ya establecido por `api/lib_login.php` y
`admin/lib_admin_auth.php`: lógica pura, testeable, sin ejecutar nada al incluirse.

## Flujo

1. El auditor entra a `dashboard.php?auditoria=1`. Se marca la sesión y aparece
   VERIFICACIÓN al final del menú lateral.
2. Abre la sección. Si no hay auditoría en curso para ese aliado, se le pide el
   nombre y se crea la cabecera (`estado='en_curso'`). Si ya hay una en curso, se
   recupera con lo que llevaba marcado y **se conserva el auditor original**: no se
   vuelve a preguntar, para que una auditoría no termine firmada por dos personas.
   Si la última auditoría del aliado está `cerrada`, se pide el nombre y se abre una
   **nueva** — eso es lo que produce el historial.
3. Ve los cinco informes. Marca cada uno y escribe observación si aplica. Cada
   marca se guarda al vuelo contra `verificacion_guardar.php`.
4. Escribe los comentarios generales.
5. Pulsa **Cerrar auditoría y enviar**. El servidor exige que los cinco puntos estén
   marcados; si falta alguno, responde con la lista de los que faltan y no cierra.
6. Cierra, arma el PDF, envía el correo y marca `correo_enviado`.

La validación de completitud vive en el servidor, no solo en el navegador: es la
condición que da sentido al registro.

## PDF

FPDF, elegido porque es un archivo único sin dependencias — Dompdf y mPDF sin
Composer significan decenas de archivos enlazados a mano, frágiles de desplegar a
WMS-LAB.

Contenido: encabezado con el logo AKA (`img/logo_aka.png`, el mismo del portal —
es el sistema que se está auditando) y el título; ficha con aliado auditado,
auditor, usuario del portal y fechas; tabla de los cinco informes con resultado y
observación; bloque de comentarios; pie con la fecha de generación.

El resultado se distingue por color además de por texto (aprobado verde, no aprobado
rojo, no aplica gris), pero **nunca solo por color**: la palabra siempre está
escrita, porque el PDF se imprime en blanco y negro para archivo físico.

Observaciones largas: la celda crece en alto en vez de recortar el texto. Un hallazgo
de auditoría truncado es peor que una tabla fea.

## Correo

Reusa PHPMailer y `conexion/config_mail.php`, igual que `api/codificacion_cargar.php`.

Los destinatarios van en una constante nueva de `config_mail.php`
(`MAIL_AUDITORIA_TO`), pendiente de que Rafael los indique. Mientras tanto queda con
la lista vacía, y **`verif_enviar()` con lista vacía es un error explícito**, no un
envío a nadie: si se despliega sin configurar, se nota de inmediato.

Asunto: `VERIFICACIÓN DE PLATAFORMA — <aliado> — <fecha>`.
Cuerpo HTML breve anunciando el diligenciamiento e identificando el tercero
auditado; el detalle va en el PDF adjunto.

## Errores

| Situación | Respuesta |
|-----------|-----------|
| Sesión ausente o expirada | HTTP 401, JSON `{ok:false}` (igual que el resto de `api/`) |
| CSRF inválido en escritura | HTTP 403, no se escribe |
| Cerrar con puntos sin marcar | HTTP 422 + lista de los informes que faltan |
| Cerrar una auditoría ya cerrada | HTTP 409, no se reenvía el correo |
| Falla el SMTP | La auditoría queda cerrada con `correo_enviado=0`; se informa al auditor que el registro se guardó pero el correo falló |
| Destinatarios sin configurar | Error explícito, nunca un envío silencioso |

El caso del SMTP es deliberado: la auditoría es el dato valioso, el correo es la
notificación. Perder el registro porque falló un servidor de correo sería absurdo.

## Pruebas

Todas ejercitan `lib_verificacion.php` sin tocar la red.

| Prueba | Qué fija |
|--------|----------|
| `verificacion_validar_test.php` | Resultados fuera del enum se rechazan; informes desconocidos se rechazan; observación sobre el límite se rechaza |
| `verificacion_completitud_test.php` | Cerrar con 4 de 5 puntos falla y nombra el que falta; con 5 pasa |
| `verificacion_guardar_test.php` | Re-marcar un informe actualiza y no duplica (verifica `UQ_verificacion_detalle`) |
| `verificacion_paquete_test.php` | El paquete armado lleva los 5 puntos, el aliado y el auditor; y **no envía nada** |
| `verificacion_destinatarios_test.php` | El proveedor centinela `__TEST__` devuelve lista vacía; lista vacía es error, no envío |
| `verificacion_guard_test.php` | Los tres endpoints responden 401 sin sesión y 403 sin CSRF |

Las filas de prueba se crean con `proveedor='__TEST__'` y se limpian al final, con
la limpieza a prueba de abortos — el mismo patrón de `admin_planillas`.

## Riesgos

**El auditor pierde la sesión a media auditoría.** Mitigado por el guardado punto a
punto; al volver, `?auditoria=1` recupera la auditoría en curso de ese aliado.

**Dos auditorías en curso del mismo aliado.** `verificacion_abrir.php` recupera la
que ya exista en lugar de crear otra. No se impone restricción en base de datos:
auditar dos veces el mismo aliado es legítimo, lo que no queremos es duplicar por
accidente dentro de la misma revisión.

**FPDF hay que traerlo a mano** y no queda registrado en ningún manifiesto de
dependencias. Va al repo (como PHPMailer) y se anota en el checklist de despliegue,
para que no se olvide al copiar a WMS-LAB.

## Pendiente de Rafael

- Los correos destinatarios (`MAIL_AUDITORIA_TO`). Es lo único que bloquea el envío
  real; todo lo demás queda terminado y probado sin ese dato.
