-- ============================================================
-- AKA 2.0 — Tablas nuevas en INTEGRACION.DBO
-- Servidor: SQL Server compartido con SIESA (STANTON.DBO)
-- Basado en Plantilla 270 (Excel actual de codificación)
-- Fecha: 2026-03-20
-- ============================================================

USE INTEGRACION;
GO

-- ============================================================
-- 1. ALIADO_MARCAS
-- Relación entre aliados y marcas que representan
-- ============================================================
CREATE TABLE dbo.aliado_marcas (
    id              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    codigo_aliado   NVARCHAR(50)     NOT NULL,   -- ref t9994_informacion_aliados
    codigo_marca    NVARCHAR(50)     NOT NULL,   -- ref criterios STANTON
    activo          BIT              NOT NULL DEFAULT 1,
    created_at      DATETIME2        NOT NULL DEFAULT GETDATE(),
    CONSTRAINT PK_aliado_marcas PRIMARY KEY (id),
    CONSTRAINT UQ_aliado_marca UNIQUE (codigo_aliado, codigo_marca)
);
GO

CREATE INDEX idx_aliado_marcas_aliado ON dbo.aliado_marcas (codigo_aliado);
GO

-- ============================================================
-- 2. SOLICITUDES_CODIFICACION
-- Cabecera de la solicitud (equivale a hoja DATOS REFERENCIA)
-- Flujo: aliado → cuidaduría/comité AKA → comité técnico → SIESA
-- ============================================================
CREATE TABLE dbo.solicitudes_codificacion (
    id                          UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    codigo_aliado               NVARCHAR(50)     NOT NULL,
    nit_aliado                  NVARCHAR(20),
    estado                      NVARCHAR(30)     NOT NULL DEFAULT 'borrador'
                                CHECK (estado IN (
                                    'borrador',
                                    'pendiente',
                                    'cuidaduria',
                                    'aprobado_cuidaduria',
                                    'rechazado_cuidaduria',
                                    'en_revision',
                                    'aprobado',
                                    'rechazado',
                                    'enviado_siesa',
                                    'sincronizado'
                                )),

    -- Datos de la referencia
    referencia                  NVARCHAR(50)     NOT NULL,
    descripcion                 NVARCHAR(500)    NOT NULL,       -- máx 40 chars en plantilla
    descripcion_abreviada       NVARCHAR(100),                   -- máx 20 chars en plantilla
    rango_talla                 NVARCHAR(20),                    -- ej: "7 al 11", "5 al 9"

    -- 11 criterios de clasificación (códigos de STANTON)
    codigo_marca                NVARCHAR(50),
    codigo_tipo_producto        NVARCHAR(50),                    -- calzado, confección, accesorios
    codigo_genero               NVARCHAR(50),                    -- femenino, masculino, unisex, no aplica
    codigo_publico_objetivo     NVARCHAR(50),                    -- adulto, juvenil, infantil, precaminador
    codigo_tipo_fabricacion     NVARCHAR(50),                    -- inyectado, montado, ensamblado, etc.
    codigo_linea                NVARCHAR(50),
    codigo_sublinea             NVARCHAR(50),
    codigo_categoria            NVARCHAR(50),                    -- botas, sandalia, zapatos, etc.
    codigo_subcategoria         NVARCHAR(50),                    -- botín, media caña, casual, etc.
    codigo_calidad              NVARCHAR(50),
    codigo_agrupador_imperfectas NVARCHAR(50),

    -- Datos adicionales de la plantilla
    unidad_medida               NVARCHAR(20),                    -- PAR, UNIDAD, CAJA, METRO
    origen                      NVARCHAR(20),                    -- NACIONAL, IMPORTADO
    tipo_recepcion              NVARCHAR(20)     DEFAULT 'PRIMER AVISO'
                                CHECK (tipo_recepcion IN ('PRIMER AVISO', 'REPOSICION')),

    -- Seguimiento
    codigo_item_siesa           NVARCHAR(50),                    -- se llena al sincronizar con SIESA
    fecha_comite_aka            DATETIME2,                       -- fecha validación cuidaduría
    fecha_comite_tecnico        DATETIME2,                       -- fecha validación admin
    revisado_por                NVARCHAR(50),
    notas_revision              NVARCHAR(MAX),

    created_at                  DATETIME2        NOT NULL DEFAULT GETDATE(),
    updated_at                  DATETIME2        NOT NULL DEFAULT GETDATE(),

    CONSTRAINT PK_solicitudes_codificacion PRIMARY KEY (id)
);
GO

CREATE INDEX idx_solicitudes_aliado ON dbo.solicitudes_codificacion (codigo_aliado, estado);
CREATE INDEX idx_solicitudes_estado ON dbo.solicitudes_codificacion (estado);
GO

-- ============================================================
-- 3. SOLICITUD_DETALLE
-- Detalle por SKU (equivale a hoja DATOS DETALLE)
-- Un registro por cada combinación referencia + color + talla
-- ============================================================
CREATE TABLE dbo.solicitud_detalle (
    id                  UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    solicitud_id        UNIQUEIDENTIFIER NOT NULL,
    color               NVARCHAR(100)    NOT NULL,       -- descripción del color: "NEGRO/BLANCO", "CAFÉ"
    talla               NVARCHAR(20)     NOT NULL,       -- "7", "8.5", "M", "XL"
    cantidad_recibir    INT              NOT NULL DEFAULT 0,
    unidad_empaque      INT,                             -- unidades por caja sólida
    numero_cajas        INT,                             -- número de cajas sólidas/bultos
    ean_proveedor       NVARCHAR(20),                    -- código EAN del proveedor o "ASIGNAR"
    precio_costo        DECIMAL(14,2),                   -- precio fábrica / costo agencia
    precio_venta        DECIMAL(14,2),                   -- P.V.S.P
    porcentaje_iva      DECIMAL(5,2)     DEFAULT 0.19,   -- % IVA
    precio_sin_iva      DECIMAL(14,2),                   -- precio público sin IVA

    CONSTRAINT PK_solicitud_detalle PRIMARY KEY (id),
    CONSTRAINT FK_detalle_solicitud FOREIGN KEY (solicitud_id)
        REFERENCES dbo.solicitudes_codificacion (id)
);
GO

CREATE INDEX idx_detalle_solicitud ON dbo.solicitud_detalle (solicitud_id);
GO

-- ============================================================
-- 4. SOLICITUD_DISPERSION
-- Distribución a tiendas AKA (equivale a hoja Dispersión)
-- Un registro por cada negocio + bodega + talla
-- ============================================================
CREATE TABLE dbo.solicitud_dispersion (
    id                  UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    solicitud_id        UNIQUEIDENTIFIER NOT NULL,
    negocio             NVARCHAR(100)    NOT NULL,       -- ref+color: "06-160650-3-NEGRO/BLANCO"
    codigo_bodega       NVARCHAR(50)     NOT NULL,       -- ref t150_mc_bodegas en STANTON
    talla               NVARCHAR(20)     NOT NULL,
    cantidad            INT              NOT NULL DEFAULT 0,

    CONSTRAINT PK_solicitud_dispersion PRIMARY KEY (id),
    CONSTRAINT FK_dispersion_solicitud FOREIGN KEY (solicitud_id)
        REFERENCES dbo.solicitudes_codificacion (id)
);
GO

CREATE INDEX idx_dispersion_solicitud ON dbo.solicitud_dispersion (solicitud_id);
CREATE INDEX idx_dispersion_bodega ON dbo.solicitud_dispersion (codigo_bodega);
GO

-- ============================================================
-- 5. CODIFICACION_FOTOS
-- Fotos del producto (obligatorias). Alimentan catálogo IA.
-- ============================================================
CREATE TABLE dbo.codificacion_fotos (
    id              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    solicitud_id    UNIQUEIDENTIFIER NOT NULL,
    archivo_url     NVARCHAR(500)    NOT NULL,
    archivo_nombre  NVARCHAR(255),
    orden           INT              NOT NULL DEFAULT 1,   -- 1 = foto principal
    created_at      DATETIME2        NOT NULL DEFAULT GETDATE(),

    CONSTRAINT PK_codificacion_fotos PRIMARY KEY (id),
    CONSTRAINT FK_fotos_solicitud FOREIGN KEY (solicitud_id)
        REFERENCES dbo.solicitudes_codificacion (id)
);
GO

CREATE INDEX idx_fotos_solicitud ON dbo.codificacion_fotos (solicitud_id);
GO

-- ============================================================
-- 6. CUIDADURIA_DICTAMEN
-- Dictamen del Comité AKA (cuidaduría)
-- Evalúa competencia/canibalización y curva de tallas
-- ============================================================
CREATE TABLE dbo.cuidaduria_dictamen (
    id                          UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    solicitud_id                UNIQUEIDENTIFIER NOT NULL,
    resultado                   NVARCHAR(20)     NOT NULL
                                CHECK (resultado IN ('aprobado', 'rechazado')),

    -- Análisis de competencia/canibalización
    hay_canibalizacion          BIT              NOT NULL DEFAULT 0,
    productos_similares         NVARCHAR(MAX),   -- JSON: refs de productos similares
    observaciones_competencia   NVARCHAR(MAX),

    -- Inspección de curva de tallas
    curva_correcta              BIT              NOT NULL DEFAULT 1,
    observaciones_curva         NVARCHAR(MAX),

    -- IA (fase 2 — se llenan cuando la IA esté activa)
    ia_score_similitud          DECIMAL(5,2),    -- % similitud con producto más cercano
    ia_productos_sugeridos      NVARCHAR(MAX),   -- JSON: productos detectados por IA
    ia_curva_validada           BIT,
    ia_observaciones            NVARCHAR(MAX),

    -- Quién dictaminó
    cuidador                    NVARCHAR(50)     NOT NULL,
    created_at                  DATETIME2        NOT NULL DEFAULT GETDATE(),

    CONSTRAINT PK_cuidaduria_dictamen PRIMARY KEY (id),
    CONSTRAINT FK_dictamen_solicitud FOREIGN KEY (solicitud_id)
        REFERENCES dbo.solicitudes_codificacion (id)
);
GO

CREATE INDEX idx_dictamen_solicitud ON dbo.cuidaduria_dictamen (solicitud_id);
GO

-- ============================================================
-- 7. CODIFICACION_HISTORIAL
-- Log de cambios de estado de cada solicitud
-- ============================================================
CREATE TABLE dbo.codificacion_historial (
    id                  UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    solicitud_id        UNIQUEIDENTIFIER NOT NULL,
    estado_anterior     NVARCHAR(30),
    estado_nuevo        NVARCHAR(30)     NOT NULL,
    usuario             NVARCHAR(50)     NOT NULL,
    comentario          NVARCHAR(MAX),
    created_at          DATETIME2        NOT NULL DEFAULT GETDATE(),

    CONSTRAINT PK_codificacion_historial PRIMARY KEY (id),
    CONSTRAINT FK_historial_solicitud FOREIGN KEY (solicitud_id)
        REFERENCES dbo.solicitudes_codificacion (id)
);
GO

CREATE INDEX idx_historial_solicitud ON dbo.codificacion_historial (solicitud_id);
GO

-- ============================================================
-- 8. DOCUMENTACION_ALIADOS
-- Repositorio de documentos (contratos, certificados, etc.)
-- ============================================================
CREATE TABLE dbo.documentacion_aliados (
    id                  UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    codigo_aliado       NVARCHAR(50)     NOT NULL,
    tipo                NVARCHAR(50)     NOT NULL,
    titulo              NVARCHAR(255)    NOT NULL,
    archivo_url         NVARCHAR(500)    NOT NULL,
    archivo_nombre      NVARCHAR(255),
    fecha_inicio        DATE,
    fecha_fin           DATE,
    estado              NVARCHAR(20)     NOT NULL DEFAULT 'vigente'
                        CHECK (estado IN ('vigente','vencido','cancelado')),
    notas               NVARCHAR(MAX),
    subido_por          NVARCHAR(50),
    created_at          DATETIME2        NOT NULL DEFAULT GETDATE(),
    updated_at          DATETIME2        NOT NULL DEFAULT GETDATE(),

    CONSTRAINT PK_documentacion_aliados PRIMARY KEY (id)
);
GO

CREATE INDEX idx_documentacion_aliado ON dbo.documentacion_aliados (codigo_aliado, estado);
GO

-- ============================================================
-- 9. ALERTAS
-- Alertas automáticas (rotación, inventario bajo, documentos)
-- ============================================================
CREATE TABLE dbo.alertas (
    id                  UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
    codigo_aliado       NVARCHAR(50)     NOT NULL,
    tipo                NVARCHAR(30)     NOT NULL
                        CHECK (tipo IN ('rotacion','inventario_bajo','documento_vencimiento')),
    titulo              NVARCHAR(255)    NOT NULL,
    mensaje             NVARCHAR(MAX),
    referencia          NVARCHAR(50),
    severidad           NVARCHAR(10)     NOT NULL DEFAULT 'media'
                        CHECK (severidad IN ('baja','media','alta')),
    leida               BIT              NOT NULL DEFAULT 0,
    created_at          DATETIME2        NOT NULL DEFAULT GETDATE(),

    CONSTRAINT PK_alertas PRIMARY KEY (id)
);
GO

CREATE INDEX idx_alertas_aliado ON dbo.alertas (codigo_aliado, leida);
CREATE INDEX idx_alertas_tipo ON dbo.alertas (tipo, created_at);
GO

PRINT 'AKA 2.0 — 9 tablas creadas exitosamente en INTEGRACION.DBO';
GO
