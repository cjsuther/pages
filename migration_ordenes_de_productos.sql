-- ---------------------------------------------------------------------------
-- Órdenes de productos digitales (#18).
--
-- Aparte de ticket_orders a propósito: una entrada tiene cupo, lugares, tipos
-- y puerta; un producto no tiene nada de eso, y mezclarlos obligaría a cada
-- consulta de entradas a filtrar lo que no es suyo. Una orden es una unidad.
--
-- El link de descarga es la credencial. Se guarda sólo su hash —se busca por
-- token_hash, que es único—, y además una copia cifrada con Cripto para poder
-- reenviar el mail sin generar otro link. El código de la orden NO es una
-- credencial: son 12 hex, se ve en la URL de vuelta de Mercado Pago.
--
-- Borrar el producto se lleva sus órdenes (ON DELETE CASCADE), pero la API no
-- deja borrar un producto con órdenes pagadas: quien lo compró tiene que poder
-- seguir descargándolo.
--
-- Se puede correr más de una vez: la tabla se crea sólo si no existe.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS product_orders (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    codigo               CHAR(12) NOT NULL,
    link_id              INT NOT NULL,

    nombre               VARCHAR(150) NOT NULL,
    email                VARCHAR(255) NOT NULL,
    telefono             VARCHAR(50)  NULL DEFAULT NULL,

    -- En pesos, como el precio del producto. 0 es un producto gratis.
    total                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- La comisión que se pidió al crear el cobro, congelada: si mañana cambia
    -- el porcentaje, esta venta sigue mostrando lo que se descontó.
    comision             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    comision_porcentaje  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    -- Lo que Mercado Pago dice que cobró como comisión de plataforma.
    mp_comision_cobrada  DECIMAL(12,2) NULL DEFAULT NULL,

    estado               ENUM('pendiente', 'pagada', 'rechazada', 'cancelada', 'vencida') NOT NULL DEFAULT 'pendiente',
    mp_preference_id     VARCHAR(100) NULL DEFAULT NULL,
    mp_payment_id        VARCHAR(100) NULL DEFAULT NULL,
    pagada_en            TIMESTAMP NULL DEFAULT NULL,

    token_hash           CHAR(64) NOT NULL,
    token_cifrado        VARCHAR(255) NOT NULL,

    descargas            INT NOT NULL DEFAULT 0,
    primera_descarga_en  TIMESTAMP NULL DEFAULT NULL,
    ultima_descarga_en   TIMESTAMP NULL DEFAULT NULL,

    mail_enviado_en      TIMESTAMP NULL DEFAULT NULL,
    mail_intentos        INT NOT NULL DEFAULT 0,
    mail_error           VARCHAR(255) NULL DEFAULT NULL,

    creado_en            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_producto_orden_codigo (codigo),
    -- Idempotencia del aviso: el mismo pago no puede acreditar dos órdenes.
    UNIQUE KEY uniq_producto_orden_pago (mp_payment_id),
    UNIQUE KEY uniq_producto_orden_token (token_hash),
    -- El pedido repetido de un producto gratis se busca por link y email.
    KEY idx_producto_orden_email (link_id, email),
    -- La conciliación y los reintentos de mail recorren por estado.
    KEY idx_producto_orden_estado (estado, creado_en),
    CONSTRAINT fk_producto_orden_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
