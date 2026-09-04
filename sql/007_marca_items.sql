/* ===========================================================================
   007 — Aliados acotados a una MARCA en vez de a un PROVEEDOR
   ---------------------------------------------------------------------------
   Caso Ibiza: el aliado 'Ibiza' tiene proveedor_items = 'STANTON', asi que los
   informes le arman el universo con ITEMS.PROVEEDOR = 'STANTON' y termina viendo
   el catalogo COMPLETO de Stanton (24.000+ referencias de 21 marcas) en vez de
   las 560 referencias de MARCA = 'IBIZA', que es lo suyo.

   No se puede resolver con proveedor_items porque el filtro de ese campo es por
   PROVEEDOR. Hace falta decir "a este aliado se le filtra por marca".

   La columna es ADITIVA y anulable: el codigo viejo la ignora por completo, asi
   que aplicar esto no cambia nada hasta que se despliegue el codigo nuevo. Se
   puede aplicar en cualquier orden, sin ventana de mantenimiento.

   Idempotente: se puede correr varias veces.
   =========================================================================== */

USE INTEGRACION;
GO

IF COL_LENGTH('dbo.usuarios_portal_aka', 'marca_items') IS NULL
BEGIN
    ALTER TABLE dbo.usuarios_portal_aka ADD marca_items VARCHAR(40) NULL;
    PRINT 'usuarios_portal_aka.marca_items creada.';
END
ELSE
    PRINT 'usuarios_portal_aka.marca_items ya existia.';
GO

/* El valor tiene que coincidir EXACTO con INTEGRACION.dbo.ITEMS.MARCA.
   Comprobado el 2026-09-04: MARCA = 'IBIZA' son 560 referencias, todas del
   proveedor STANTON. */
UPDATE dbo.usuarios_portal_aka
   SET marca_items = 'IBIZA'
 WHERE nombre_usuario = 'Ibiza'
   AND ISNULL(marca_items, '') <> 'IBIZA';
GO

/* --- Comprobacion --- */
SELECT RTRIM(nombre_usuario) AS usuario,
       RTRIM(ISNULL(proveedor_items, '')) AS proveedor_items,
       RTRIM(ISNULL(marca_items, ''))     AS marca_items
  FROM dbo.usuarios_portal_aka
 WHERE nombre_usuario = 'Ibiza';
GO

/* --- Marcha atras ---
UPDATE dbo.usuarios_portal_aka SET marca_items = NULL WHERE nombre_usuario = 'Ibiza';
-- y, si se quiere quitar del todo la columna:
-- ALTER TABLE dbo.usuarios_portal_aka DROP COLUMN marca_items;
*/
