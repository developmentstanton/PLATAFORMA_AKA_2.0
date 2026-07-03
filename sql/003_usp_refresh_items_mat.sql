CREATE OR ALTER PROCEDURE dbo.usp_Refresh_Items_Mat
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    -- 1) Staging fresca (la parte lenta: leer la vista ITEMS). NO toca la tabla viva.
    IF OBJECT_ID('INTEGRACION.dbo.Items_Mat_stg') IS NOT NULL
        DROP TABLE INTEGRACION.dbo.Items_Mat_stg;
    CREATE TABLE INTEGRACION.dbo.Items_Mat_stg (
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

    INSERT INTO INTEGRACION.dbo.Items_Mat_stg
        (PROVEEDOR, REFERENCIA, MARCA, TIPO, LINEA, SUBLINEA, CATEGORIA, SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO)
    SELECT PROVEEDOR, REFERENCIA,
        ISNULL(MARCA,'SIN MARCA'), ISNULL(TIPO,'SIN TIPO'), ISNULL(LINEA,'SIN LINEA'), ISNULL(SUBLINEA,''),
        ISNULL(CATEGORIA,''), ISNULL(SUBCATEGORIA,''), ISNULL(GENERO,''), ISNULL(PUBLICO_OBJETIVO,'')
    FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK);

    CREATE CLUSTERED INDEX CIX_Items_Mat_prov_ref
        ON INTEGRACION.dbo.Items_Mat_stg (PROVEEDOR, REFERENCIA);

    -- 2) Swap atómico (rápido). La tabla viva sirve el dataset anterior hasta este instante.
    BEGIN TRY
        BEGIN TRAN;
            IF OBJECT_ID('INTEGRACION.dbo.Items_Mat') IS NOT NULL
                DROP TABLE INTEGRACION.dbo.Items_Mat;
            EXEC sp_rename 'INTEGRACION.dbo.Items_Mat_stg', 'Items_Mat';
        COMMIT;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        THROW;
    END CATCH;
END;
