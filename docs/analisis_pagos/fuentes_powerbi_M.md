# Análisis de Pagos — Fuentes de datos del Power BI (P78.pbix)

Origen: `P78.pbix` (descargado del workspace de Power BI). Las tablas del modelo
`Flujo de Egresos` y `Nombre_razon Social` se alimentan de varias consultas M.
Todas leen del **SQL Server SIESA en RDS**:

- Servidor: `siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com`
- Bases: `Stanton` (SIESA) e `Integracion` (tablas/vistas pre-armadas `*_PBI`)

> Las consultas M envuelven SQL crudo vía `Sql.Database(server, db, [Query="..."])`.
> Los `#(lf)` = salto de línea y `#(tab)` = tabulación (codificación M).

⚠️ **ALERTAS al replicar:**
1. `Anticipos` ejecuta **DDL/DML** en cada refresh (`DROP TABLE`, `CREATE TABLE`,
   `EXEC sp_cons_cxp_w`, `DELETE`). En la versión nativa NO debemos correr DDL desde
   una consulta de lectura; aislar la generación de `Anticipos_PBI` en un proceso
   controlado (job/endpoint) y solo LEER desde el informe.
2. Varias dependen de tablas intermedias en `Integracion.dbo`:
   `Doc_Compra_PBI`, `FLUJO_OC_PBI`, `Anticipos_PBI`. Hay que ver si son tablas
   materializadas por otro proceso o vistas.
3. Lógica de **fecha de pago** repetida (clases de proveedor QUINCENA / SEMANA /
   PUNTUAL, corrimiento por día de semana). Centralizar en una función/vista.

Faltan por recibir: la consulta que arma **`Flujo de Egresos`** (probable append/union
de estas), **`Nombre_razon Social`**, y las demás consultas M + medidas DAX.

---

## Consulta: Documentos_Compra
Lee de `integracion.dbo.Doc_Compra_PBI` + joins SIESA (`t208_mm_condiciones_pago`,
`t202_mm_proveedores`, `t200_mm_terceros`). Calcula FECHA_VENCIMIENTO y FECHA_PAGO.

```m
// Documentos_Compra
let
    Origen = Sql.Database("siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com", "Stanton", [Query="WITH Base AS (
    SELECT
        RTRIM(f200_id) AS NIT,
        RTRIM(f202_descripcion_sucursal) AS Razon_Social,
        RTRIM(f208_id) AS ID,
        RTRIM(f208_descripcion) AS DESCRIPCION_PAGO,
        f208_dias_vcto AS DIAS
    FROM t208_mm_condiciones_pago
    LEFT JOIN t202_mm_proveedores ON f202_id_cond_pago = f208_id AND f202_id_cia = f208_id_cia
    LEFT JOIN t200_mm_terceros ON f200_rowid = f202_rowid_tercero
    WHERE f208_id_cia = '1' and f200_id IS NOT NULL
), BaseFinal AS (
    SELECT
        RTRIM(C.CIA) AS CIA,
        RTRIM(C.Nro_Docto) AS DOCUMENTO,
        RTRIM(C.nit) AS NIT,
        RTRIM(C.Proveedor) AS PROVEEDOR,
        'SEMANA' AS CLASE_PROVEEDOR,
        RTRIM(C.Tipo_Proveedor) AS TIPO_PROVEEDOR,
        'NO' AS CAUSADO,
        RTRIM(C.Moneda) AS MONEDA,
        C.Valor_Neto AS VALOR,
        CASE
            WHEN C.Fecha_Entrega IS NULL THEN CONVERT(DATE, GETDATE())
            ELSE CONVERT(DATE, C.Fecha_Entrega)
        END AS Fecha_Entrega,
        CASE
            WHEN B.DIAS IS NULL THEN 0
            ELSE B.DIAS
        END AS DIAS
    FROM integracion.dbo.Doc_Compra_PBI AS C
    LEFT JOIN Base AS B ON B.NIT = C.nit
    WHERE C.Consignacion = 'No' AND C.Estado = 'Contabilizado' AND C.CIA = 'Stanton S.A.S.'
), FechasCalculadas AS (
    SELECT
        *,
        DATEADD(DAY, DIAS, Fecha_Entrega) AS FECHA_VENCIMIENTO_REAL,
        CASE DATENAME(DW, DATEADD(DAY, DIAS, Fecha_Entrega))
            WHEN 'Monday' THEN 'Lunes'
            WHEN 'Tuesday' THEN 'Martes'
            WHEN 'Wednesday' THEN 'Miercoles'
            WHEN 'Thursday' THEN 'Jueves'
            WHEN 'Friday' THEN 'Viernes'
            WHEN 'Saturday' THEN 'Sabado'
            WHEN 'Sunday' THEN 'Domingo'
        END AS DIA_CORTE
    FROM BaseFinal
), DiasCalculados AS (
    SELECT
        *,
        CASE
            WHEN CLASE_PROVEEDOR = 'QUINCENA' AND DAY(FECHA_VENCIMIENTO_REAL) = 15 THEN DATENAME(dw, FECHA_VENCIMIENTO_REAL)
            WHEN CLASE_PROVEEDOR = 'QUINCENA' AND DAY(FECHA_VENCIMIENTO_REAL) = 1 THEN DATENAME(dw, DATEADD(day, -1, FECHA_VENCIMIENTO_REAL))
            ELSE DATENAME(dw, FECHA_VENCIMIENTO_REAL)
        END AS DIA_CORTE2
    FROM FechasCalculadas
), FechaDePago AS (
    SELECT
        *,
        DATEADD(day,
            CASE DATENAME(dw, FECHA_VENCIMIENTO_REAL)
                WHEN 'Monday' THEN 4
                WHEN 'Tuesday' THEN 3
                WHEN 'Wednesday' THEN 2
                WHEN 'Thursday' THEN 1
                WHEN 'Friday' THEN 0
                WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 5
            END,
            FECHA_VENCIMIENTO_REAL) AS FECHA_PAGO_CALCULADA
    FROM DiasCalculados
)
SELECT
    CIA,
    DOCUMENTO,
	NIT,
    PROVEEDOR,
    CLASE_PROVEEDOR,
    TIPO_PROVEEDOR,
    CAUSADO,
    MONEDA,
    VALOR,
    CONVERT(VARCHAR(10), FECHA_VENCIMIENTO_REAL, 103) AS FECHA_VENCIMIENTO,
    CONVERT(VARCHAR(10), FECHA_VENCIMIENTO_REAL, 103) AS FECHA_CORTE,
    DIA_CORTE,
    DIA_CORTE2,
    CASE
        WHEN FECHA_PAGO_CALCULADA < GETDATE() THEN
            DATEADD(day,
                CASE DATENAME(dw, GETDATE())
                    WHEN 'Monday' THEN 4
                    WHEN 'Tuesday' THEN 3
                    WHEN 'Wednesday' THEN 2
                    WHEN 'Thursday' THEN 1
                    WHEN 'Friday' THEN 0
                    WHEN 'Saturday' THEN 6
                    WHEN 'Sunday' THEN 5
                END,
                GETDATE())
        ELSE FECHA_PAGO_CALCULADA
    END AS FECHA_PAGO
FROM FechaDePago
ORDER BY DOCUMENTO;"]),
    #"Texto en mayúsculas" = Table.TransformColumns(Origen,{{"CIA", Text.Upper, type text}})
in
    #"Texto en mayúsculas"
```

---

## Consulta: Anticipos  ⚠️ (ejecuta DDL/DML en cada refresh)
Reconstruye `Integracion.dbo.Anticipos_PBI` con `sp_cons_cxp_w` (cuenta 133005) y
borra NITs excluidos / anticipos en 0.

```m
// Anticipos
let
    Origen = Sql.Database("siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com", "Stanton", [Query="--ANTICIPOS
DROP table Integracion.dbo.Anticipos_PBI
create table Integracion.dbo.Anticipos_PBI(
	CIA Varchar (25),
	CUENTA Varchar (50),
	NIT Varchar (20),
	RAZON_SOCIAL Varchar (100),
	NOTAS Varchar (300),
	TIPO_PROVEEDOR Varchar (50),
	ANTICIPO MONEY,
	DOCTO VARCHAR(20),
	FECHA DATE,
	VENCIMIENTO DATE,
	PRONTO_PAGO DATE
)

Insert into Integracion.dbo.Anticipos_PBI
exec sp_cons_cxp_w 1,NULL,'PLA','133005',... (parámetros largos; ver pbix)
-- (se repite exec para varios IDs: 1,2,3,7,...)

delete from INTEGRACION.dbo.Anticipos_PBI
where NIT in ('444444444','444444077','555555555','800166656','800231774','800231774',
 '805002434','830029689','860009034','860029964','890114924','890501078','890937066',
 '900332388','900464619','900718628','900818008','901092482','901167559','901845617')
	OR ANTICIPO = 0
Select * from Integracion.dbo.Anticipos_PBI"])
in
    Origen
```

---

## Consulta: Flujo Proyectado
Lee de `INTEGRACION.DBO.FLUJO_OC_PBI`. Clasifica proveedor (QUINCENA/SEMANA/PUNTUAL,
`PROV.%`→SEMANA, vacío→SEMANA) y calcula FECHA_CORTE_REAL + FECHA_PAGO con corrimiento
por día de semana. (SQL completo guardado tal cual del pbix; lógica de fecha de pago
extensa con CASE anidados por clase de proveedor.)

```m
// Flujo Proyectado  — fuente: INTEGRACION.DBO.FLUJO_OC_PBI
// SELECT final: CIA, FECHA, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
//   CAUSADO, DESCRIPCION, MONEDA, VALOR, DIA_CORTE, FECHA_CORTE, DIA_CORTE2, FECHA_PAGO
// Reglas:
//   CLASE_PROVEEDOR: '' -> SEMANA ; LIKE 'PROV.%' -> SEMANA ; resto = RTRIM(clase)
//   FECHA_CORTE_REAL:
//     QUINCENA & DAY<=15 -> día 15 del mes
//     PUNTUAL/SEMANA     -> DATEADD al viernes de esa semana
//     otro (mensual)     -> día 1 del mes siguiente
//   FECHA_PAGO_CALCULADA:
//     QUINCENA & corte jue/vie -> +6/+7/+8 días según día 1 o 15
//     resto -> corrimiento a viernes
//   FECHA_PAGO: si < GETDATE() -> reprograma al viernes de la semana actual
// (Ver pbix para el SQL literal completo con todos los CASE anidados.)
```

---

## Consulta: TERCEROS  → alimenta dim `Nombre_razon Social`
Maestro de proveedores (NIT + RAZON_SOCIAL), distinct por NIT.

```sql
SELECT DISTINCT RTRIM(f200_nit) NIT, RTRIM(f200_razon_social) RAZON_SOCIAL
FROM t200_mm_terceros
WHERE f200_nit <> '' AND f200_ID_CIA in ('51','53','49','2','7','3','1','52')
-- M: filtra fuera NIT '63252594' y Table.Distinct por {NIT}
```

## Consulta: Consignacion
De `t420_cm_oc_docto`. Arma `Llave = Compañia-CO-Tipo_Docto-Nro_Doc` y marca CONSIGNACION.

```sql
SELECT RTRIM(f420_id_cia) Compañia,
       RTRIM(f420_id_CO) CO,
       RTRIM(f420_id_tipo_docto) Tipo_Docto,
       RIGHT('00000000' + RTRIM(f420_consec_docto), 8) Nro_Doc,
       CASE WHEN f420_ind_consignacion = 1 THEN 'CONSIGNACION' ELSE '' END AS CONSIGNACION
FROM t420_cm_oc_docto
WHERE f420_id_tipo_docto <> 'REQ' AND f420_fecha >= '2023-01-01'
-- M: combina las 4 primeras cols en "Llave" con separador "-"
```

## Consulta: Valor DIVISAS  (⚠️ API web externa)
Trae USD→COP y EUR→COP de `api.exchangerate-api.com` (el comentario dice Superfinanciera,
pero en realidad usa exchangerate-api). Tabla {Divisa, Valor, Fecha=hoy}. Divisas: "USD", "EU".
→ En la versión nativa: decidir si tomar TRM oficial (Superfinanciera/Datos Abiertos) o
mantener este API; cachear por día.

## Lookups estáticos embebidos en el pbix (tablas hardcodeadas, no SQL)
- **GRUPO_CXP**: mapea `TIPO CXP` → `GRUPO_CXP` (agrupa tipos de cuenta por pagar). Datos
  comprimidos dentro del M (Binary.Decompress). Hay que extraer el contenido para tener el mapeo.
- **Tipo Detalle Proveedor**: mapea `NIT` → `Detalle`. Igual, tabla embebida comprimida.
- Valores ya decodificados en `lookups_estaticos.json`.

---

## Tabla calculada (DAX): `Flujo de Egresos`  ← LA CENTRAL
`UNION` de 3 `SELECTCOLUMNS` (Flujo Proyectado + Documentos_Compra + Anticipos), normalizando
a un esquema común de 17 columnas. Columna `Base` etiqueta el origen de cada fila.

**Esquema unificado:** Compañia, Fecha vencimiento, Documento, Nit, Proveedor, clase Proveedor,
Tipo CTA, Causado, Descripcion, Moneda, Fecha Pago, Valor, En Pesos, Detalle Compañia,
Tipo Proveedor (BLANK), Corte (TODAY), Base.

**Derivaciones clave (replicar en SQL):**
- **En Pesos** = `IF(Moneda="COP", Valor, Valor * RELATED('Valor DIVISAS'[VALOR]))`.
  En Anticipos = ANTICIPO directo (ya viene en COP). OJO: DIVISAS usa "EU" (no "EUR") y "USD".
- **Detalle Compañia** = `IF(RELATED('Tipo Detalle Proveedor'[Detalle])=BLANK(), "Proveedor", RELATED(...))`
  (cruce por NIT; default "Proveedor").
- Por base:
  - *Flujo Proyectado*: clase/tipo/causado/descripcion vienen de la fuente; Fecha venc = FECHA; Base="Flujo Proyectado".
  - *Documentos Compra*: Descripcion fija "ENTRADA POR CAUSAR"; Fecha venc = FECHA_VENCIMIENTO; Base="Documentos Compra".
  - *Anticipos*: clase="PUNTUAL", Causado="SI", Descripcion="ANTICIPO", Moneda="COP",
    Proveedor=RAZON_SOCIAL, Documento=DOCTO, Fecha venc=FECHA, Base="Anticipos".
    **Fecha Pago = próximo viernes desde TODAY()**:
    `daysUntilFriday = IF(weekday<=5, 5-weekday, 7-weekday+5)` (weekday lunes=1), `RETURN today+daysUntilFriday`.

**Columnas calculadas sobre `Flujo de Egresos`:**
```dax
Grupo Ctas * Pagar = RELATED(GRUPO_CXP[GRUPO_CXP])
Año Pago           = YEAR([Fecha Pago])
Dia-Mes Pago       = DAY([Fecha Pago]) & "-" & LEFT([Mes Pago],3)   // ej "15-jun"
Dias               = DATEDIFF([Fecha vencimiento], TODAY(), DAY)
```
⚠️ `Dia-Mes Pago` referencia `[Mes Pago]` (otra col calculada, NO recibida; prob.
`Mes Pago = FORMAT([Fecha Pago],"MMMM")` en español). Confirmar.

**Relaciones del modelo (todas *:1, activas):**
| Desde (muchos) | Hacia (uno) |
|---|---|
| Anticipos[NIT] | Tipo Detalle Proveedor[NIT] |
| Documentos_Compra[MONEDA] | Valor DIVISAS[Divisa] |
| Documentos_Compra[NIT] | Tipo Detalle Proveedor[NIT] |
| Flujo Proyectado[MONEDA] | Valor DIVISAS[Divisa] |
| Flujo Proyectado[NIT] | Tipo Detalle Proveedor[NIT] |
| **Flujo de Egresos[Nit]** | **Nombre_razon Social[NIT]** |
| **Flujo de Egresos[Tipo CTA]** | **GRUPO_CXP[TIPO CXP]** |

> Nota: el `RELATED('Valor DIVISAS')` y `RELATED('Tipo Detalle Proveedor')` dentro del UNION
> funcionan porque la relación existe en las tablas fuente (Flujo Proyectado / Documentos_Compra /
> Anticipos), no en Flujo de Egresos. En SQL eso es simplemente un JOIN por MONEDA y por NIT en
> cada subconsulta antes del UNION.

**Pendiente menor:** col `Mes Pago`, y medidas `Titulo 1` / `Titulo 2` (títulos de tarjetas).

```dax
Flujo de Egresos =
UNION(
    SELECTCOLUMNS('Flujo Proyectado',
        "Compañia",'Flujo Proyectado'[CIA], "Fecha vencimiento",'Flujo Proyectado'[FECHA],
        "Documento",'Flujo Proyectado'[DOCUMENTO], "Nit",'Flujo Proyectado'[NIT],
        "Proveedor",'Flujo Proyectado'[PROVEEDOR], "clase Proveedor",'Flujo Proyectado'[CLASE_PROVEEDOR],
        "Tipo CTA",'Flujo Proyectado'[TIPO_PROVEEDOR], "Causado",'Flujo Proyectado'[CAUSADO],
        "Descripcion",'Flujo Proyectado'[DESCRIPCION], "Moneda",'Flujo Proyectado'[MONEDA],
        "Fecha Pago",'Flujo Proyectado'[FECHA_PAGO], "Valor",'Flujo Proyectado'[VALOR],
        "En Pesos",IF('Flujo Proyectado'[MONEDA]="COP",[VALOR],[VALOR]*RELATED('Valor DIVISAS'[VALOR])),
        "Detalle Compañia",IF(RELATED('Tipo Detalle Proveedor'[Detalle])=BLANK(),"Proveedor",RELATED('Tipo Detalle Proveedor'[Detalle])),
        "Tipo Proveedor",BLANK(), "Corte",TODAY(), "Base","Flujo Proyectado"),
    SELECTCOLUMNS('Documentos_Compra',
        "Compañia",'Documentos_Compra'[CIA], "Fecha vencimiento",'Documentos_Compra'[FECHA_VENCIMIENTO],
        "Documento",'Documentos_Compra'[DOCUMENTO], "Nit",'Documentos_Compra'[NIT],
        "Proveedor",'Documentos_Compra'[PROVEEDOR], "clase Proveedor",'Documentos_Compra'[CLASE_PROVEEDOR],
        "Tipo CTA",'Documentos_Compra'[TIPO_PROVEEDOR], "Causado",'Documentos_Compra'[CAUSADO],
        "Descripcion","ENTRADA POR CAUSAR", "Moneda",'Documentos_Compra'[MONEDA],
        "Fecha Pago",'Documentos_Compra'[FECHA_PAGO], "Valor",'Documentos_Compra'[VALOR],
        "En Pesos",IF('Documentos_Compra'[MONEDA]="COP",[VALOR],[VALOR]*RELATED('Valor DIVISAS'[VALOR])),
        "Detalle Compañia",IF(RELATED('Tipo Detalle Proveedor'[Detalle])=BLANK(),"Proveedor",RELATED('Tipo Detalle Proveedor'[Detalle])),
        "Tipo Proveedor",BLANK(), "Corte",TODAY(), "Base","Documentos Compra"),
    SELECTCOLUMNS('Anticipos',
        "Compañia",'Anticipos'[CIA], "Fecha vencimiento",'Anticipos'[FECHA],
        "Documento",'Anticipos'[DOCTO], "Nit",'Anticipos'[NIT],
        "Proveedor",'Anticipos'[RAZON_SOCIAL], "clase Proveedor","PUNTUAL",
        "Tipo CTA",'Anticipos'[TIPO_PROVEEDOR], "Causado","SI",
        "Descripcion","ANTICIPO", "Moneda","COP",
        "Fecha Pago",
            VAR today = TODAY()
            VAR currentWeekday = WEEKDAY(today, 2)
            VAR daysUntilFriday = IF(currentWeekday <= 5, 5 - currentWeekday, 7 - currentWeekday + 5)
            RETURN today + daysUntilFriday,
        "Valor",'Anticipos'[ANTICIPO], "En Pesos",Anticipos[ANTICIPO],
        "Detalle Compañia",IF(RELATED('Tipo Detalle Proveedor'[Detalle])=BLANK(),"Proveedor",RELATED('Tipo Detalle Proveedor'[Detalle])),
        "Tipo Proveedor",BLANK(), "Corte",TODAY(), "Base","Anticipos")
)
```
