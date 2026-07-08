-- Cache del #base granular de O14 (denormalizado) por proveedor+rango-de-ventas.
IF OBJECT_ID('INTEGRACION.dbo.o14_cache_base') IS NOT NULL DROP TABLE INTEGRACION.dbo.o14_cache_base;
CREATE TABLE INTEGRACION.dbo.o14_cache_base (
    cache_key        VARCHAR(64)  NOT NULL,
    cia              VARCHAR(10)  NULL,
    bodega           VARCHAR(20)  NULL,
    negocio          VARCHAR(120) NULL,
    referencia       VARCHAR(50)  NULL,
    color            VARCHAR(40)  NULL,
    talla            VARCHAR(40)  NULL,
    siembra          INT          NULL,
    disponible       INT          NULL,
    hold             INT          NULL,
    ventas           INT          NULL,
    marca            VARCHAR(40)  NULL,
    tipo             VARCHAR(40)  NULL,
    categoria        VARCHAR(60)  NULL,
    subcategoria     VARCHAR(60)  NULL,
    genero           VARCHAR(40)  NULL,
    publico_objetivo VARCHAR(60)  NULL,
    grupo            VARCHAR(40)  NULL,
    nombre           VARCHAR(120) NULL,
    centro_comercial VARCHAR(120) NULL,
    depto            VARCHAR(60)  NULL,
    ciudad           VARCHAR(60)  NULL,
    creado           DATETIME2    NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_o14cb_key ON INTEGRACION.dbo.o14_cache_base (cache_key);
