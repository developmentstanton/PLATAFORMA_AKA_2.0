-- ============================================================
-- Módulo de Verificación (auditoría de aliados) — 2026-08-31
-- Idempotente: la RDS es compartida (dev/staging/prod).
-- ============================================================
USE INTEGRACION;
GO

IF OBJECT_ID('dbo.verificacion_auditoria', 'U') IS NULL
CREATE TABLE dbo.verificacion_auditoria (
    id              INT IDENTITY(1,1) NOT NULL,
    proveedor       NVARCHAR(120)  NOT NULL,  -- aliado auditado ($_SESSION['proveedor'])
    usuario_portal  NVARCHAR(50)   NOT NULL,  -- credenciales con las que se entró
    auditor         NVARCHAR(120)  NOT NULL,  -- nombre escrito por el auditor
    comentarios     NVARCHAR(MAX)  NULL,
    estado          NVARCHAR(20)   NOT NULL CONSTRAINT DF_verif_estado DEFAULT 'en_curso',
    creado_en       DATETIME2      NOT NULL CONSTRAINT DF_verif_creado DEFAULT SYSDATETIME(),
    cerrado_en      DATETIME2      NULL,
    correo_enviado  BIT            NOT NULL CONSTRAINT DF_verif_correo DEFAULT 0,
    CONSTRAINT PK_verificacion_auditoria PRIMARY KEY (id),
    CONSTRAINT CK_verif_estado CHECK (estado IN ('en_curso','cerrada'))
);
GO

IF OBJECT_ID('dbo.verificacion_auditoria_detalle', 'U') IS NULL
CREATE TABLE dbo.verificacion_auditoria_detalle (
    id             INT IDENTITY(1,1) NOT NULL,
    auditoria_id   INT            NOT NULL,
    informe        NVARCHAR(10)   NOT NULL,
    resultado      NVARCHAR(20)   NOT NULL,
    observacion    NVARCHAR(1000) NULL,
    actualizado_en DATETIME2      NOT NULL CONSTRAINT DF_verif_det_act DEFAULT SYSDATETIME(),
    CONSTRAINT PK_verificacion_detalle PRIMARY KEY (id),
    CONSTRAINT FK_verificacion_detalle FOREIGN KEY (auditoria_id)
        REFERENCES dbo.verificacion_auditoria (id),
    CONSTRAINT CK_verif_det_informe   CHECK (informe   IN ('g00','o14','o45','evol','geo')),
    CONSTRAINT CK_verif_det_resultado CHECK (resultado IN ('aprobado','no_aprobado','no_aplica')),
    -- Es lo que hace que re-marcar un informe actualice en vez de duplicar.
    CONSTRAINT UQ_verificacion_detalle UNIQUE (auditoria_id, informe)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'idx_verificacion_proveedor')
CREATE INDEX idx_verificacion_proveedor
    ON dbo.verificacion_auditoria (proveedor, creado_en DESC);
GO
