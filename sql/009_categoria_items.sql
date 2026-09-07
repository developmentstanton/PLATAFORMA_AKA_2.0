/* ===========================================================================
   009 — Aliados acotados ademas a una CATEGORIA
   ---------------------------------------------------------------------------
   Sigue el patron de 007_marca_items.sql. Aquel dice "a este aliado se le filtra
   por MARCA en vez de por PROVEEDOR"; este dice, encima, "y solo esta CATEGORIA".

   Caso Ibiza: se pide que en TODOS sus informes vea unicamente CATEGORIA='ZAPATOS'.

   >>> LEER ANTES DE APLICAR — el numero no es intuitivo <<<
   De las 560 referencias de MARCA='IBIZA', solo 7 tienen CATEGORIA='ZAPATOS':

       SANDALIA  552
       ZAPATOS     7
       BOTAS       1

   O sea que este acote deja a Ibiza con 7 referencias, no con "su calzado": en
   este modelo CATEGORIA='ZAPATOS' NO significa calzado, convive con SANDALIA y
   BOTAS, y lo que abarca todo su calzado es TIPO='CALZADO' (las 560).
   Se aplica asi porque asi se pidio expresamente, a la vista de estos numeros
   (Rafael, 2026-09-07). Si algun dia los informes de Ibiza "se ven vacios", la
   causa es esta linea, no un fallo.

   La columna es ADITIVA y anulable: el codigo viejo la ignora por completo, asi
   que aplicar esto no cambia nada hasta que se despliegue el codigo nuevo. Se
   puede aplicar en cualquier orden, sin ventana de mantenimiento.

   Idempotente: se puede correr varias veces.
   =========================================================================== */

USE INTEGRACION;
GO

IF COL_LENGTH('dbo.usuarios_portal_aka', 'categoria_items') IS NULL
BEGIN
    ALTER TABLE dbo.usuarios_portal_aka ADD categoria_items VARCHAR(40) NULL;
    PRINT 'usuarios_portal_aka.categoria_items creada.';
END
ELSE
    PRINT 'usuarios_portal_aka.categoria_items ya existia.';
GO

/* El valor tiene que coincidir EXACTO con INTEGRACION.dbo.ITEMS.CATEGORIA. */
UPDATE dbo.usuarios_portal_aka
   SET categoria_items = 'ZAPATOS'
 WHERE nombre_usuario = 'Ibiza'
   AND ISNULL(categoria_items, '') <> 'ZAPATOS';
GO

/* --- Comprobacion: que aliados quedan acotados y con cuantas referencias --- */
SELECT RTRIM(u.nombre_usuario)               AS usuario,
       RTRIM(ISNULL(u.proveedor_items, ''))  AS proveedor_items,
       RTRIM(ISNULL(u.marca_items, ''))      AS marca_items,
       RTRIM(ISNULL(u.categoria_items, ''))  AS categoria_items,
       (SELECT COUNT(*)
          FROM INTEGRACION.dbo.Items_Mat m WITH (NOLOCK)
         WHERE RTRIM(m.MARCA) = RTRIM(u.marca_items)
           AND (u.categoria_items IS NULL
                OR RTRIM(m.CATEGORIA) = RTRIM(u.categoria_items))) AS refs_que_vera
  FROM dbo.usuarios_portal_aka u
 WHERE ISNULL(u.marca_items, '') <> ''
    OR ISNULL(u.categoria_items, '') <> '';
GO

/* --- Marcha atras ---
   Quitar el acote de categoria (Ibiza vuelve a ver las 560 de su marca):

UPDATE dbo.usuarios_portal_aka SET categoria_items = NULL WHERE nombre_usuario = 'Ibiza';

   Y, si se quiere quitar del todo la columna:
-- ALTER TABLE dbo.usuarios_portal_aka DROP COLUMN categoria_items;

   OJO: despues de cualquier marcha atras hay que BORRAR LA CACHE, o se sigue
   sirviendo el universo viejo hasta 25 h. Ver LEEME del paquete de despliegue:
       del cache\g00_refs_*.json  cache\o14c_*  cache\evol_*  cache\o45_*
*/
