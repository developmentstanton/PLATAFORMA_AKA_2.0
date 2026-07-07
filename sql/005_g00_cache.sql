-- Cache del dataset granular de G00 (ventas denormalizado) por proveedor+periodo.
IF OBJECT_ID('INTEGRACION.dbo.g00_cache_ventas') IS NOT NULL DROP TABLE INTEGRACION.dbo.g00_cache_ventas;
CREATE TABLE INTEGRACION.dbo.g00_cache_ventas (
    cache_key       VARCHAR(64)  NOT NULL,
    FECHA           DATETIME     NULL,
    anio            INT          NULL,
    mes             INT          NULL,
    dia             INT          NULL,
    BODEGA          VARCHAR(20)  NULL,
    REFERENCIA      VARCHAR(50)  NULL,
    COLOR           VARCHAR(40)  NULL,
    TALLA           VARCHAR(40)  NULL,
    MARCA           VARCHAR(40)  NULL,
    TIPO            VARCHAR(40)  NULL,
    CATEGORIA       VARCHAR(60)  NULL,
    SUBCATEGORIA    VARCHAR(60)  NULL,
    GENERO          VARCHAR(40)  NULL,
    PUBLICO_OBJETIVO VARCHAR(60) NULL,
    GRUPO           VARCHAR(40)  NULL,
    NOMBRE          VARCHAR(120) NULL,
    CENTRO_COMERCIAL VARCHAR(120) NULL,
    DEPTO           VARCHAR(60)  NULL,
    CIUDAD          VARCHAR(60)  NULL,
    CANTIDAD        INT          NULL,
    VALOR           FLOAT        NULL,
    MARGEN          FLOAT        NULL,
    creado          DATETIME2    NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_g00cv_key ON INTEGRACION.dbo.g00_cache_ventas (cache_key);

-- Cache de siembra (snapshot por proveedor), granular por (bodega,ref,color,talla)+dims de bodega.
IF OBJECT_ID('INTEGRACION.dbo.g00_cache_siembra') IS NOT NULL DROP TABLE INTEGRACION.dbo.g00_cache_siembra;
CREATE TABLE INTEGRACION.dbo.g00_cache_siembra (
    cache_key   VARCHAR(64) NOT NULL,   -- hash de proveedor (siembra = snapshot, sin fechas)
    BODEGA      VARCHAR(20) NULL,
    REFERENCIA  VARCHAR(50) NULL,
    COLOR       VARCHAR(40) NULL,
    TALLA       VARCHAR(40) NULL,
    MARCA VARCHAR(40) NULL, TIPO VARCHAR(40) NULL, CATEGORIA VARCHAR(60) NULL,
    SUBCATEGORIA VARCHAR(60) NULL, GENERO VARCHAR(40) NULL, PUBLICO_OBJETIVO VARCHAR(60) NULL,
    GRUPO VARCHAR(40) NULL, NOMBRE VARCHAR(120) NULL, CENTRO_COMERCIAL VARCHAR(120) NULL,
    DEPTO VARCHAR(60) NULL, CIUDAD VARCHAR(60) NULL,
    q           INT NULL,
    creado      DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_g00cs_key ON INTEGRACION.dbo.g00_cache_siembra (cache_key);
