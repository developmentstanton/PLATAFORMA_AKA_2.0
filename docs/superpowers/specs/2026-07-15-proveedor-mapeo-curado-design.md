# Mapeo curado usuario→proveedor (arreglar informes vacíos por mismatch de nombres)

**Fecha:** 2026-07-15
**Autor:** Rafael Lancheros (+ Claude)
**Estado:** Diseño para aprobación

## Problema

~11 de 17 aliados del portal ven **informes vacíos** (y el prebuild reporta `o14c=failed`, evol/o45 se cachean vacíos). Se detectó al agendar el prebuild nocturno en WMS-LAB (2026-07-15).

## Causa raíz (diagnosticada contra la BD)

Los informes filtran por `INTEGRACION.dbo.ITEMS.PROVEEDOR` (materializado en `Items_Mat.PROVEEDOR`). `buildRefsFromMat` hace `WHERE PROVEEDOR = ?` (**match exacto**). El nombre que entra (`$_SESSION['proveedor']`) lo produce `login_resolver_proveedor`, que lo saca de la **razón social de SIESA t202** (`f202_descripcion_sucursal`). Pero:

- `ITEMS.PROVEEDOR` NO es el maestro de terceros: viene de `INTEGRACION.dbo.PROVEEDOR` (vista) → `stanton.dbo.t106_mc_criterios_item_mayores` (`f106_descripcion`), un **"criterio mayor" de merchandising** tecleado por catálogo. Ej.: `BRAHMA CONCEPT`, `KRONOTIME`, `DISANDINA S.A.`.
- La razón social de t202 es otra forma: `BRAHMA CONCEPT S A S`, `KRONO TIME SAS`, `DISANDINA S.A.S.`.
- **No existe llave que una ambos sistemas.** El NIT vive solo en t200/t202; ITEMS usa su propio código `COD_PROVEEDOR` (`f106_id`, ej. `0179`) que NO es el NIT. Verificado: ni `Items_Mat` ni `ITEMS` ni `INTEGRACION.dbo.PROVEEDOR` tienen NIT.

Resultado: para esos aliados, `razon (t202) ≠ ITEMS.PROVEEDOR` → `buildRefsFromMat` da `#refs = 0` → informes vacíos / o14c falla.

## Diseño propuesto (Enfoque A — columna curada, aprobado)

Como no hay puente automático, el mapeo se **cura una vez** por aliado, reusando el patrón ya existente (`usuarios_portal_aka.link2` = NIT curado a mano).

### 1. DDL (una vez, RDS compartida)

```sql
ALTER TABLE INTEGRACION.dbo.usuarios_portal_aka
  ADD proveedor_items VARCHAR(120) NULL;
```

(`link1` ya está ocupado con la URL de PowerBI, por eso columna nueva. Nombre `proveedor_items` = autoexplicativo: "el valor de ITEMS.PROVEEDOR de este aliado".)

### 2. Código — `api/lib_login.php`

`login_resolver_proveedor($conn, $usuario)` gana un **paso 0** que prioriza la columna curada. Al ir DENTRO del resolver, arregla **login (index.php), prebuild y prewarm** de una sola vez (los tres llaman a este resolver):

```php
// Paso 0: nombre canónico curado (usuarios_portal_aka.proveedor_items). Prioridad máxima.
$sqlCurado = "SELECT TOP 1 RTRIM(proveedor_items) AS prov, RTRIM(link2) AS nit
              FROM usuarios_portal_aka WHERE nombre_usuario = ?";
$st0 = sqlsrv_query($conn, $sqlCurado, array($usuario));
if ($st0 !== false) {
    $r0 = sqlsrv_fetch_array($st0, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($st0);
    if ($r0 && trim((string)$r0['prov']) !== '') {
        return array(
            'proveedor' => trim((string)$r0['prov']),
            'nit'       => (!empty($r0['nit'])) ? trim((string)$r0['nit']) : null,
            'fuente'    => 'curado',
        );
    }
}
// (si no hay curado -> sigue la lógica actual: t202, luego fallback ITEMS)
```

El resto del resolver (t202 → fallback ITEMS) queda **intacto** como respaldo para usuarios sin curar. `index.php` NO cambia (ya usa el resolver para `$_SESSION['proveedor']`; su manejo de `link2`→NIT sigue igual).

### 3. Datos de curación (`proveedor_items` por aliado)

Curar TODOS los aliados reales (no solo los rotos), para no depender nunca del match frágil. Mapeo verificado contra el catálogo real de `ITEMS.PROVEEDOR`:

| nombre_usuario | proveedor_items |
|---|---|
| Brahma Concept | `BRAHMA CONCEPT` |
| C.I Hermeco | `C.I HERMECO` |
| Colombiana de Tenis | `COLOMBIANA DE TENIS SA` |
| Cueros Velez | `CUEROS VELEZ` |
| Disandina | `DISANDINA S.A.` |
| Dynamo_new_era | `DYNAMO DISTRIBUTION S.A` |
| Dynamo_vans | `DYNAMO DISTRIBUTION S.A` |
| Estudio de Moda | `ESTUDIO DE MODA` |
| Fashion Fitness Colombia sas | `FASHION FITNESS COLOMBIA S.A.S.` |
| Guauta shoes | `GUAUTA SHOES` |
| Krono Time Sas | `KRONOTIME` |
| Mc Cawleys | `MC CAWLEYS S.A.S.` |
| Ultra Sport | `ULTRA SPORT L&A S.A.S.` |
| M&L de Colombia | `MYL DE COLOMBIA` |
| Shoes Master | `SHOEMASTERS S.A.S` |
| Beltrany sas | `BELTRANY SAS` |
| BH Brands | `BH BRANDS SAS` |
| D&E Olam Sas | `D&E OLAM SAS` |
| Intertenis | `INTERTENIS S.A.S` |
| Parana | `PARANA DISTRIBUCIONES SAS` |
| Planeta Sport 6 | `PLANETA SPORT 6 SAS` |

Confirmado por Rafael 2026-07-15: `M&L de Colombia` = `MYL DE COLOMBIA`, `Shoes Master` = `SHOEMASTERS S.A.S`.

**Sin curar (a propósito):**
- `Everlast`, `Maison Botter`: **no existen en el catálogo de ITEMS** → se dejan sin curar (seguirán vacíos hasta que existan o Rafael dé el nombre real).
- `jdgiraldo`, `Rafael Lancheros`: cuentas internas/admin, no aliados → sin curar.

La curación se hará con `UPDATE`s parametrizados (uno por fila) sobre la RDS, revisados por Rafael.

## Pruebas y verificación

- **Reproducción del bug (antes):** para `Brahma Concept`, `buildRefsFromMat` con el nombre resuelto hoy da `#refs = 0`.
- **Después (por proveedor curado):** el resolver devuelve `fuente='curado'` con el nombre exacto → `buildRefsFromMat` da `#refs > 0` → `o14cBuildPayloadC` ok con datos → informes con datos.
- **Regresión del resolver:** un usuario SIN `proveedor_items` sigue resolviendo igual que hoy (t202 / fallback ITEMS). Cubrir con un caso curado y uno no-curado.
- **End-to-end en prod:** tras DDL + curación + deploy de código, re-correr `schtasks /Run` del prebuild en WMS-LAB → esperar `o14c=warmed` en los aliados curados (antes `failed`), y conteos de `.gz` completos.

## Despliegue

- **DDL** una vez en la RDS compartida (sirve dev/staging/prod).
- **Código** (`lib_login.php`): main → staging → copia a `E:\WMS\www\plataforma_20` (mismo flujo que evol).
- **Curación** (`UPDATE`s) una vez en la RDS.
- Re-correr el prebuild para recachear los aliados curados.

## Fuera de alcance

- Completar la curación de `link2` (NIT) para los aliados con NIT vacío — es para Análisis de Pagos, frente aparte.
- Resolver `Everlast`/`Maison Botter` (no existen en ITEMS todavía).
- Cambiar la materialización de `Items_Mat` o la vista `ITEMS` (prohibido tocar SIESA; no hace falta).
