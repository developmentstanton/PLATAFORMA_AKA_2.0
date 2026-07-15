-- Columna curada: el valor EXACTO de ITEMS.PROVEEDOR de cada aliado del portal.
-- Idempotente. RDS compartida (dev/staging/prod). Inofensiva para el código viejo.
IF COL_LENGTH('INTEGRACION.dbo.usuarios_portal_aka','proveedor_items') IS NULL
    ALTER TABLE INTEGRACION.dbo.usuarios_portal_aka ADD proveedor_items VARCHAR(120) NULL;
