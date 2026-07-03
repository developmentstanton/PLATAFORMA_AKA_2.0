-- Dimensión de producto materializada (snapshot de la vista ITEMS, denormalizada).
-- Paridad exacta con el SELECT de getRefsCached (lib_refs.php). Refrescada de noche por usp_Refresh_Items_Mat.
IF OBJECT_ID('INTEGRACION.dbo.Items_Mat') IS NULL
BEGIN
    CREATE TABLE INTEGRACION.dbo.Items_Mat (
        PROVEEDOR        varchar(100) NULL,
        REFERENCIA       varchar(50)  NOT NULL,
        MARCA            varchar(40)  NULL,
        TIPO             varchar(40)  NULL,
        LINEA            varchar(40)  NULL,
        SUBLINEA         varchar(40)  NULL,
        CATEGORIA        varchar(40)  NULL,
        SUBCATEGORIA     varchar(60)  NULL,
        GENERO           varchar(40)  NULL,
        PUBLICO_OBJETIVO varchar(60)  NULL
    );
    CREATE CLUSTERED INDEX CIX_Items_Mat_prov_ref
        ON INTEGRACION.dbo.Items_Mat (PROVEEDOR, REFERENCIA);
END;
