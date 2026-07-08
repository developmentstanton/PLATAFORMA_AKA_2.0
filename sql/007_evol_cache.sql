-- Cache del #base granular de EVOL (denormalizado) por proveedor+rango-de-meses.
IF OBJECT_ID('INTEGRACION.dbo.evol_cache_base') IS NOT NULL DROP TABLE INTEGRACION.dbo.evol_cache_base;
CREATE TABLE INTEGRACION.dbo.evol_cache_base (
    cache_key        VARCHAR(64)  NOT NULL,
    negocio          VARCHAR(120) NULL,
    mes              CHAR(7)      NULL,
    cia              VARCHAR(10)  NULL,
    bodega           VARCHAR(20)  NULL,
    referencia       VARCHAR(50)  NULL,
    color            VARCHAR(40)  NULL,
    ventas           INT          NULL,
    compras          INT          NULL,
    stock            INT          NULL,
    marca            VARCHAR(40)  NULL,
    tipo             VARCHAR(40)  NULL,
    categoria        VARCHAR(60)  NULL,
    subcategoria     VARCHAR(60)  NULL,
    genero           VARCHAR(40)  NULL,
    publico_objetivo VARCHAR(60)  NULL,
    grupo            VARCHAR(40)  NULL,
    nombre           VARCHAR(120) NULL,
    creado           DATETIME2    NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_evolcb_key ON INTEGRACION.dbo.evol_cache_base (cache_key);
