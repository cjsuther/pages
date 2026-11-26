# Análisis: devoluciones a compradores a través de Rezon.ar

> Estado: **implementado en la rama `claude/devoluciones-mp`, detrás de `DEVOLUCIONES_HABILITADAS` (apagado)**. Base: `origin/main` @ `ac74b64` (26/11/2026).
>
> **Decisiones de Cristian (26/11/2026):**
> 1. El organizador devuelve sólo su parte: el neto que le llegó (`mp_neto`/`net_received_amount`) menos lo ya devuelto. La parte de Rezon.ar no se toca.
> 2. Siempre como reembolso de Mercado Pago (`POST /v1/payments/{id}/refunds`, parcial con `amount` = neto devolvible, con el token OAuth del vendedor). Nada de transferencias por fuera.
> 3. No se detectan las devoluciones ni los contracargos hechos por fuera de la plataforma: la Fase 1 queda fuera de alcance.
> 4. Si una devolución no se puede hacer, el motivo queda registrado en la compra y en el historial de intentos (pedido posterior).
>
> **El riesgo sigue abierto**: si MP reparte el reembolso en forma proporcional, el parcial le saca una parte a Rezon.ar (§2). Después de cada reembolso se guarda el reparto real que informa MP (`ticket_order_devoluciones.reparto`). La prueba para medirlo está en [`PRUEBA-DEVOLUCIONES-SANDBOX.md`](PRUEBA-DEVOLUCIONES-SANDBOX.md). Decisiones de diseño que tomé en la implementación (para revisar):
> - Por entrada, el neto que queda se reparte según lo que valen las entradas que tiene **hoy** la compra (también si fue modificada). Va hacia abajo al centavo y la última entrada se lleva el resto.
> - Tope: lo ya devuelto es el mayor entre nuestro registro y lo que informa MP (`transaction_amount_refunded`).
> - Una compra devuelta entera queda `cancelada` y sigue sumando en "recaudado". Lo devuelto se resta de "te queda", de lo acreditado y de lo que falta acreditar.
> - Los productos (`product_orders`) quedan fuera de alcance.
>
> El texto de abajo es el análisis original.
> Nada de esto se probó contra Mercado Pago: no se hicieron pagos ni reembolsos, ni de prueba ni reales.
> Lo que no está confirmado por la documentación oficial está marcado con **[SIN CONFIRMAR]**.

## Resumen

- **Se puede**, pero con una condición importante. Mercado Pago tiene una API de reembolsos, total y parcial (`POST /v1/payments/{id}/refunds`), y nosotros ya guardamos todo lo necesario para llamarla: el `mp_payment_id` y el token OAuth del vendedor.
- **Lo que quiere Cristian** es que el comprador reciba sólo el neto, que MP y Rezon.ar se queden con lo suyo y que al organizador le salga exactamente lo que cobró. **No lo controlamos nosotros, lo decide Mercado Pago.** En un marketplace, MP descuenta el reembolso "de la cuenta del vendedor y de la del marketplace, en forma **proporcional**". Por eso un reembolso parcial por el neto probablemente **también le saca a Rezon.ar casi toda su comisión**, y el organizador termina devolviendo menos que el neto. Hay que medirlo en sandbox antes de decidir (Fase 0).
- Hoy **ya se guarda el neto real** (`ticket_orders.mp_neto`, según MP) y la comisión que se cobró de verdad (`mp_comision_cobrada`). Esa es la base del cálculo.
- **Hueco que ya existe:** si un pago se devuelve desde el panel de MP o tiene un contracargo, Rezon.ar no se entera. La orden sigue `pagada` y el QR sigue sirviendo en la puerta.

---

## 1. Cómo fluye hoy el dinero en una compra

### Vinculación con Mercado Pago
- El organizador conecta su cuenta por **OAuth** con la aplicación de marketplace de Rezon.ar (`api/lib/MercadoPagoOAuth.php`, `api/entradas/conectar.php`, `oauth-callback.php`). El alta manual de tokens quedó deshabilitada (`config.example.php`).
- Las credenciales están en `page_payment_settings` (una fila por página): `access_token_cifrado`, `refresh_token_cifrado`, `mp_user_id`, `conectado_por` ('oauth'|'manual'), `token_expira_en` y `modo` ('prueba'|'produccion'). `Cobros::tokenDe()` descifra el token y lo renueva si está por vencer.
- El cobro es **Checkout Pro** con el **access token del vendedor**: el pago se hace en la cuenta del organizador. La comisión de Rezon.ar viaja como **`marketplace_fee`** en la preferencia (`MercadoPago::crearPreferencia`), y MP la deposita en la cuenta de la aplicación. En el pago aparece como la línea `application_fee` de `fee_details`.

### Quién se queda con qué
| Concepto | Quién lo calcula | Dónde queda |
|---|---|---|
| Lo que paga el comprador (`transaction_amount`) | Rezon.ar (`Entradas::crearOrden`), validado contra MP en el aviso | `ticket_orders.total` |
| Comisión pedida para Rezon.ar (`marketplace_fee`) | `Comision::sobre(total)` (floor, % de `PLATFORM_FEE_PERCENT`), o `cargos.plataforma` si la paga el comprador | `ticket_orders.comision`, `comision_porcentaje` |
| Comisión que cobró Rezon.ar de verdad (`application_fee`) | Mercado Pago | `ticket_orders.mp_comision_cobrada` (NULL = no se sabe; 0 = MP no la cobró) |
| Comisión de MP + la de Rezon.ar (suma de `fee_details`) | Mercado Pago | `ticket_orders.mp_comisiones` |
| **Neto acreditado al organizador** (`transaction_details.net_received_amount`) | Mercado Pago | **`ticket_orders.mp_neto`** |
| Fecha de liberación (`money_release_date`) | Mercado Pago | `ticket_orders.acreditacion_en` |
| Cargos sumados al comprador (gross-up) | `CargosAlComprador::calcular` | `ticket_orders.cargo_mercadopago`, `cargo_plataforma` |
| Precio de las entradas | — | `total - cargo_mercadopago - cargo_plataforma` (`CargosAlComprador::precioDeLaOrden`) |
| Desglose por tipo de entrada | copia al comprar | `ticket_order_items` (precio y cantidad por tipo) |

La comisión de MP sola no se guarda aparte, pero se deduce: `mp_comisiones - mp_comision_cobrada`.

**¿Se guarda el neto?** Sí, desde `migration_acreditacion.sql`. Lo escriben `Entradas::acreditarPago` (en el aviso) y `completarDetalleDePago` (al conciliar). Puede ser **NULL** en ventas viejas o si MP no mandó el desglose. Por eso, antes de devolver, el diseño vuelve a consultar el pago en MP (ver §3).

**Matiz:** la tasa de MP de `config.php` (4,39 % + IVA) es sólo informativa. La tasa real depende del plazo de liberación que eligió **cada cuenta** (de ~1,49 % a ~6,29 % + IVA). Por eso el cálculo de la devolución tiene que usar `mp_neto`/`fee_details`, nunca las tasas de config.

---

## 2. Qué permite la API de Mercado Pago

Fuentes oficiales consultadas:
- [R1] Checkout Pro → Reembolsos y cancelaciones: https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/additional-settings/refunds-and-cancellations
- [R2] Referencia de la API "Create refund": https://www.mercadopago.com.ar/developers/en/reference/online-payments/checkout-api-payments/create-refund/post
- [R3] Split de pagos 1:1, integrar marketplace: https://www.mercadopago.com.ar/developers/pt/docs/split-payments/split-1-1/integration-configuration/integrate-marketplace
- [R4] Marketplace en Checkout Pro: https://www.mercadopago.com.ar/developers/en/docs/checkout-pro/how-tos/integrate-marketplace (no habla de reembolsos)
- [R5] Ayuda, "Cómo devuelvo un pago": https://www.mercadopago.com.ar/ayuda/devolver-un-pago_4247 (devolvió 403 al intentar leerla; sólo se vio el extracto del buscador)
- [R6] Aviso de que la idempotencia pasa a ser obligatoria: https://www.mercadopago.com.ar/developers/en/news/2023/01/04/Idempotency-key-usage-will-be-mandatory

| Tema | Qué dice la documentación | Confirmado |
|---|---|---|
| Endpoint | `POST https://api.mercadopago.com/v1/payments/{id}/refunds`. **Total:** cuerpo vacío. **Parcial:** `{"amount": X}` [R1][R2]. Para listar: `GET /v1/payments/{id}/refunds` | Sí |
| Idempotencia | Header `X-Idempotency-Key` **obligatorio** (error 4292 si falta) [R2][R6] | Sí |
| Plazo | "dentro de los **180 días** posteriores a la aprobación del pago" [R1]. El extracto de la ayuda [R5] dice **90 días**. Error `Payment-too-old-to-be-refunded` (2024/15016) [R2] | 180 en la doc para desarrolladores; **la diferencia con 90 queda [SIN CONFIRMAR]** |
| Reembolso parcial | Se puede. Hay transacciones que no lo admiten: error 3024 "Partial refund unsupported for this transaction" [R2]. No se publica qué medios lo admiten ni cuántos parciales se pueden hacer | **Medios y límite: [SIN CONFIRMAR]** |
| Saldo | Si la cuenta no tiene saldo suficiente, el reembolso "será rechazado" [R1]. Hay que ver si cuenta el dinero no liberado (a liberar) | **Dinero no liberado: [SIN CONFIRMAR]** |
| Token | La referencia sólo pide "Access Token" [R2]. El pago pertenece al vendedor (es el collector, y lo creamos con su token), así que lo esperable es usar **el token OAuth del vendedor**, el mismo que ya usamos en `consultarPago` | **[SIN CONFIRMAR]**: probar en sandbox |
| Reparto en un marketplace | "o valor devido ao cliente final será dividido e subtraído da conta do vendedor e da conta do Marketplace, sendo **proporcional** para as partes envolvidas" [R3]. Si el vendedor no tiene saldo, "o Marketplace não poderá realizar o reembolso total"; el marketplace devuelve su parte y decide qué hace con el resto [R3] | Proporcional: sí. **La base de la proporción: [SIN CONFIRMAR]** |
| ¿MP devuelve su comisión? | La ayuda [R5], según el extracto del buscador, dice que en una devolución no te cobran el costo del pago (es decir, MP devolvería su comisión). No está en la doc para desarrolladores, ni para reembolsos por API, ni para reembolsos parciales | **[SIN CONFIRMAR]** |
| Estado del pago después | No figura en las páginas consultadas. Lo conocido de la API: `status = refunded` (total), `status = approved` + `status_detail = partially_refunded` (parcial), `charged_back` (contracargo), y el campo `transaction_amount_refunded` | **[SIN CONFIRMAR]**: verificar en sandbox |
| Webhook | No figura. Lo esperable es que MP mande un aviso `payment` (id del pago) cuando el pago cambia, también al devolverse | **[SIN CONFIRMAR]** |
| Respuesta | `id` (del refund), `payment_id`, `amount`, `status`, `date_created`, `source`, `refund_mode`… [R2] | Sí |
| Otros errores | 2063 estado inválido para la acción, 4040/4041 monto inválido, 2000 pago inexistente, 401 token inválido [R2] | Sí |

### ¿Se puede devolver "sólo el neto"?
Técnicamente sí: es un reembolso **parcial** con `amount = neto`. Contablemente, **no queda como quiere Cristian** si el reparto es proporcional [R3]. Ejemplo con una venta de $10.000 sin cargos: neto $9.368,81, Rezon.ar $100, MP $531,19.

| Hipótesis (sin confirmar) | Le sale al organizador | Le sale a Rezon.ar | El comprador recibe |
|---|---|---|---|
| A. Proporción sobre lo que recibió cada uno (neto : fee) | ~$9.269,87 | **~$98,94** | $9.368,81 |
| B. Proporción sobre el total, y MP aporta su parte | ~$8.777,46 | ~$93,69 | $9.368,81 |
| **Lo que quiere Cristian** | $9.368,81 | $0 | $9.368,81 |

Con cualquiera de las dos hipótesis, **Rezon.ar devuelve casi toda su comisión** y el organizador conserva una parte. En cambio, con un **reembolso total** el organizador devuelve exactamente lo que recibió (su neto) y la diferencia la ponen MP (si devuelve su comisión) y Rezon.ar (`application_fee`). Es decir:

- **Total por MP:** comprador $10.000, organizador −$9.368,81, Rezon.ar −$100 y MP −$531,19 (si la devuelve).
- **Parcial por el neto:** comprador $9.368,81, organizador −(menos que el neto) y Rezon.ar pierde casi toda la comisión igual.

**Conclusión del punto:** la única forma de que Rezon.ar conserve el 100 % de su fee sería que MP **no** debitara al marketplace en los reembolsos parciales, y la documentación dice lo contrario. Hay que medirlo en sandbox (Fase 0) antes de elegir. Si se confirma lo proporcional, las alternativas están en §5.

---

## 3. Cómo calcular el monto a devolver

Definición: **lo que le quedó al organizador.**

```
neto_orden = net_received_amount del pago (MP)       ← fuente de verdad
           = transaction_amount − Σ fee_details      (MP + application_fee + financiación)
devolvible = neto_orden − devoluciones ya hechas (aprobadas o en curso)
```

- `net_received_amount` **ya descuenta** el `application_fee`, así que no hay que restarlo otra vez (`MercadoPago::leerPago`, comentario "ya descontadas su comisión y la nuestra").
- Antes de devolver, se **vuelve a consultar el pago** (`consultarPago`). Si `mp_neto` es NULL o no coincide con lo que dice MP, gana MP y se guarda. Se exige `status = approved` y `transaction_amount_refunded` menor que el total.
- Si `fee_details` trae financiación (cuotas con interés a cargo del vendedor), ese costo también queda afuera del neto. Es coherente con "lo que llegó".
- Redondeo: siempre en centavos enteros y hacia abajo, para no superar nunca el neto.

### Ejemplos con las tasas de producción (MP 4,39 % + 21 % IVA = 5,3119 %; Rezon.ar 1 % sin IVA; fijo 0)

**Sin cargos al comprador (el organizador absorbe):**

| Entradas | Total cobrado | MP (5,3119 %) | Rezon.ar (1 %) | **Neto = devolvible** |
|---|---|---|---|---|
| 1 × $10.000 | $10.000,00 | $531,19 | $100,00 | **$9.368,81** |
| 2 × $10.000 | $20.000,00 | $1.062,38 | $200,00 | **$18.737,62** |

**Con cargos al comprador (gross-up, `CargosAlComprador::calcular`):**

| Entradas | Precio | Cargo Rezon.ar | Cargo MP | Total cobrado | **Neto = devolvible** |
|---|---|---|---|---|---|
| 1 × $10.000 | $10.000 | $100,00 | $566,60 | $10.666,60 | **$10.000,00** |
| 2 × $10.000 | $20.000 | $200,00 | $1.133,20 | $21.333,20 | **$20.000,00** |

Con cargos, "devolver el neto" equivale a **devolver el precio de la entrada sin los cargos**. Siempre que la cuenta del organizador tenga la misma tasa que la de config: si eligió otro plazo de liberación, el neto real difiere unos pesos. Por eso se usa el `net_received_amount` real, nunca el `precio`.

**Devolución por entrada (compra de varias):** el neto se reparte en proporción a lo que se cobró por cada renglón (ya existe `Entradas::cobradoPorItem`), con el resto del redondeo en la última entrada que se devuelva. En el ejemplo de 2 × $10.000 sin cargos, devolver 1 entrada son $9.368,81, y la segunda, lo que quede ($9.368,81). Con tipos de precio distinto, cada tipo se lleva su proporción.

---

## 4. Entradas, QR y casos borde

| Caso | Qué pasa hoy | Propuesta |
|---|---|---|
| **QR / puerta** | `Puerta::mirar`/`ingresar` sólo aceptan `estado = 'pagada'`. `Entradas::cancelar` pasa a `cancelada` y libera el cupo solo, porque `ocupadas()` sólo cuenta pagadas y reservas vigentes | Devolución total ⇒ `cancelada` (el QR deja de servir y se libera el cupo). Es el mismo mecanismo que ya existe |
| **Devolución parcial (por entrada)** | `ModificarEntradas` ya baja cantidad, tipos y lugares sin tocar la plata | Reusar ese camino para sacar entradas y registrar el monto aparte. El QR sigue siendo el mismo y en la puerta se ve la cantidad nueva |
| **Entradas ya usadas** (`ingresadas > 0`) | — | No devolver las que ya ingresaron (o exigir confirmación explícita del superadmin) |
| **Compras modificadas** (`modificada_en`) | Total, comisión y neto quedan como se pagaron; las entradas sumadas son gratis | El devolvible siempre es `neto − ya devuelto`, nunca más, por más entradas que tenga la orden. La regla para repartirlo por entrada en una compra modificada es una **decisión** (ver §6) |
| **Invitados** (`origen = 'invitacion'`, total 0) y **reservas gratis** | Sin MP | No se devuelven: sólo se cancelan, como hoy. El endpoint los rechaza |
| **Saldo insuficiente** | — | MP rechaza el reembolso [R1]. Se muestra el error, la devolución queda `rechazada` y se puede reintentar con **otra** clave de idempotencia cuando haya saldo. No se cancela la orden hasta que MP apruebe |
| **Pago > 180 días** | — | Error 15016: se bloquea en la UI antes de llamar a MP |
| **Respuesta incierta** (timeout; `HttpClient` hoy no tiene timeout y devuelve `status 0`) | — | La devolución queda `incierta`. Se concilia con `GET /v1/payments/{id}/refunds` y **jamás** se reintenta con una clave nueva sin conciliar antes |
| **Doble clic / dos admins a la vez** | — | `SELECT … FOR UPDATE` sobre la orden, fila `pendiente` con clave de idempotencia única por intento, y `UNIQUE (mp_refund_id)` |
| **Devoluciones hechas desde el panel de MP, y contracargos** | **No se detectan.** `acreditarPago` corta con "ya estaba pagada" y el aviso se ignora. La orden sigue pagada y el QR sigue sirviendo | Que el aviso de pago (y la conciliación) mire `status`/`transaction_amount_refunded` en órdenes ya pagadas. Con `refunded` o `charged_back`: registrar la devolución "externa", cancelar o marcar la orden y avisar al organizador. Esto vale aunque nunca se haga la función completa |
| **Pago con varios medios** | Checkout Pro genera un pago por medio [R1] | Hoy guardamos un solo `mp_payment_id`. Bloquear si la búsqueda por referencia muestra más de un pago aprobado |
| **Productos digitales/físicos** (`product_orders`) | Mismo MP | Fuera de alcance. Mismo diseño, en una fase posterior |

---

## 5. Propuesta de diseño

### Opción recomendada (si la Fase 0 confirma que el reparto es proporcional)
1. **Devolución total por MP** (`refund` sin monto) como opción principal: cuando el organizador cancela el evento o acepta devolver todo. El organizador devuelve exactamente su neto, Rezon.ar pierde su fee de esa venta y el comprador recupera todo. Es lo más simple, lo menos discutible con el comprador y lo más defendible ante Defensa del Consumidor.
2. **Devolución parcial por el neto** como segunda opción, explicando con números en la pantalla cuánto pone cada parte, con lo que muestre la Fase 0.
3. Si Cristian necesita sí o sí que Rezon.ar conserve su fee: no se resuelve con la API. Habría que cobrarle aparte al organizador lo que MP le debitó a Rezon.ar (facturarlo o descontarlo), cosa que hoy no existe. **No lo recomiendo.**

### Permisos
- **Pide/ejecuta:** quien administra la página (`PageAccess::canManageLink`, que ya incluye a los superadmins). Es quien pone la plata.
- **Opcional:** que sea una función que habilita el superadmin por página (`page_payment_settings.devoluciones_habilitadas`). También se puede exigir aprobación del superadmin para montos altos. Es una decisión (§6).
- El comprador **no** puede devolverse solo: con el código de la orden podría hacerlo cualquiera. Como mucho, un "pedir devolución" que le avise al organizador.

### Endpoints (patrón existente: archivo `.php` → handler)
- `api/entradas/devolucion.php` → `DevolucionesHandler::preparar` (GET `?codigo=`): consulta el pago en MP y devuelve neto, ya devuelto, devolvible total y por entrada, plazo restante, entradas usadas y si admite parcial.
- `api/entradas/devolucion.php` → `DevolucionesHandler::devolver` (POST `codigo`, `modo: total|neto|entradas`, `entradas: {tipo: n}`, `motivo`, `confirmacion` = monto que vio la persona). Se rechaza si el monto recalculado no coincide con el confirmado.
- `api/entradas/conciliar.php` (cron existente): suma un paso para devoluciones `incierta`/`pendiente` y para pagos devueltos o contracargados afuera.
- `MercadoPago::devolver($pagoId, $monto|null, $claveIdempotencia)` y `MercadoPago::devoluciones($pagoId)`. Detalle: el reembolso total tiene que mandar `{}` y no `[]` (hoy `json_encode([])` da `[]`). Y agregar un timeout a `HttpClient`.

### Base (migración aditiva e idempotente: `migration_devoluciones.sql`)
```sql
CREATE TABLE IF NOT EXISTS ticket_order_devoluciones (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    order_id          INT NOT NULL,
    mp_payment_id     VARCHAR(100) NOT NULL,
    mp_refund_id      VARCHAR(100) NULL DEFAULT NULL,
    idempotency_key   VARCHAR(64)  NOT NULL,
    modo              ENUM('total','neto','entradas','externa') NOT NULL,
    monto             DECIMAL(10,2) NOT NULL,
    entradas          TEXT NULL,               -- JSON {tipo: cantidad}
    neto_al_pedir     DECIMAL(10,2) NULL,       -- foto de lo que dijo MP
    estado            ENUM('pendiente','aprobada','rechazada','incierta') NOT NULL DEFAULT 'pendiente',
    error             VARCHAR(255) NULL,
    respuesta_mp      TEXT NULL,               -- sin datos personales ni tokens
    motivo            VARCHAR(255) NULL,
    pedida_por        INT NULL,
    mail_enviado_en   TIMESTAMP NULL DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resuelta_en       TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_refund_mp (mp_refund_id),
    UNIQUE KEY uniq_idempotencia (idempotency_key),
    KEY idx_devolucion_orden (order_id),
    CONSTRAINT fk_devolucion_orden FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT
);
ALTER TABLE ticket_orders ADD COLUMN IF NOT EXISTS devuelto DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER mp_comision_cobrada;
ALTER TABLE ticket_orders ADD COLUMN IF NOT EXISTS mp_estado VARCHAR(30) NULL DEFAULT NULL AFTER devuelto; -- refunded / charged_back…
```
- No se toca el ENUM `estado`: total ⇒ `cancelada` (ya existe). `ON DELETE RESTRICT` para que no se borre la auditoría de plata.
- Reportes: `ventasDelEvento`, el panel (`PanelVentas.jsx`) y el Excel tienen que restar `devuelto` de lo recaudado y del neto. Si no, el organizador ve plata que ya no tiene.
- Pasos de siempre: agregar la migración al final de `migraciones.txt`, `php tests/esquema.php generar`, y **aplicarla en producción antes de mergear**.

### UI
- En `PanelVentas`, por compra pagada: botón "Devolver dinero" → modal con el desglose (cobrado, comisión MP, comisión Rezon.ar, ya devuelto, **a devolver**), la elección total/neto/por entrada, y la advertencia de que es irreversible y sale de la cuenta de MP del organizador. Se confirma escribiendo el monto o el código.
- Estado visible en la compra: "Devuelta $X el dd/mm", o "Devolución rechazada: saldo insuficiente".
- Política de devoluciones del evento visible **antes de comprar** (texto en el checkout y en el mail). Es necesario si se devuelve menos de lo pagado.

### Notificación al comprador
- Mail con PHPMailer (`CorreoEntradas::enviarDevolucion`), después del commit, como en `enviarModificacion`: monto, medio (a la tarjeta o a la cuenta de MP), plazo estimado según el medio, y si las entradas quedan anuladas. El resultado se guarda en `mail_enviado_en`. Que falle el mail no revierte la devolución.

### Auditoría
- Cada intento queda en `ticket_order_devoluciones`, con quién, cuándo, cuánto, la foto del neto, la respuesta de MP y el resultado. Nunca se actualiza `monto` ni se borran filas. Log de errores sin tokens.

### Riesgos (ALTO RIESGO: mueve plata real)
1. **Devolver dos veces** (reintentos, doble clic, timeout): se mitiga con idempotencia de MP, bloqueo de fila, conciliación antes de reintentar y tope `devuelto ≤ neto`.
2. **Devolver de más:** el monto se calcula en el servidor contra el neto que dice MP en ese momento, y nunca se confía en el navegador.
3. **Mala interpretación contable:** que Rezon.ar pierda su fee sin saberlo (§2). Mitigación: Fase 0 y mostrar el reparto real (`GET` del pago después del refund).
4. **Consumidor:** devolver menos de lo pagado puede chocar con la Ley 24.240 y el botón de arrepentimiento (Res. 424/2020), sobre todo **si el evento se cancela**. Es un **tema legal [SIN CONFIRMAR]** para consultar con un abogado antes de lanzar "parcial por el neto".
5. **Token vencido o revocado** del organizador: la devolución falla. Ya hay renovación en `Cobros::tokenDe`.
6. **Facturación:** si Rezon.ar devuelve parte de su fee, puede hacer falta una nota de crédito. **[SIN CONFIRMAR]**, para el contador.
7. **Error humano del organizador:** confirmación fuerte y, si se decide, aprobación del superadmin.
8. **Pruebas:** nunca contra producción. Tests unitarios con un `HttpClient` doble, integración con MariaDB y Fase 0 con usuarios de prueba de MP (`TEST-`).

### Alternativas
| Alternativa | Pros | Contras |
|---|---|---|
| Sólo total por MP | Simple, el comprador queda conforme, el organizador devuelve exactamente su neto | Rezon.ar pierde su fee (y MP la suya, si la devuelve) |
| Parcial por el neto por MP | Lo que pidió Cristian, del lado del comprador | Por el reparto proporcional, Rezon.ar igual pierde casi todo su fee y el organizador devuelve menos que el neto. Posible conflicto legal |
| Parcial calculado para que el organizador ponga el neto exacto | Al organizador le sale lo que cobró | El comprador recibe más que el neto, Rezon.ar igual pierde. La fórmula depende de la Fase 0 |
| Sin API (el organizador transfiere por fuera) y Rezon.ar sólo marca | Rezon.ar conserva el fee y no hay riesgo técnico | Sin trazabilidad ni garantía, el pago sigue "aprobado" en MP y queda expuesto a contracargo |
| Sólo detectar devoluciones y contracargos externos (mínimo) | Arregla el hueco del QR válido, riesgo bajo | No ofrece devoluciones desde Rezon.ar |

---

## 6. Esfuerzo estimado y decisiones

| Fase | Contenido | Estimación |
|---|---|---|
| **0. Sandbox** | Con usuarios de prueba de MP (vendedor conectado por OAuth a una app de prueba, más comprador): un pago con `marketplace_fee`, un reembolso total y uno parcial. Anotar los saldos de vendedor y marketplace, `fee_details`, `status`/`status_detail`, si llega el webhook, qué token sirve, y si se puede devolver dinero no liberado | 0,5–1 día (requiere que Cristian cree las cuentas de prueba) |
| **1. Detectar devoluciones y contracargos externos** | Aviso + conciliación miran pagos ya acreditados, registran `mp_estado`, cancelan/marcan la orden y avisan. Tests | 1,5–2 días |
| **2. Backend: devolución total** | Migración, `MercadoPago::devolver`, `DevolucionesHandler`, idempotencia, conciliación de `incierta`, tests unitarios (FakePdo + doble HTTP) e integración | 3–4 días |
| **3. UI + mail + reportes** | Modal en `PanelVentas`, restar `devuelto` en panel y Excel, mail al comprador, tests Vitest | 2–3 días |
| **4. Parcial (neto y por entrada)** | Reparto por renglón, integración con `ModificarEntradas`, compras modificadas, entradas usadas | 2–3 días |
| 5. Productos (`product_orders`) | Mismo diseño | 2 días |

Total aproximado: **9–13 días** para las fases 0–4.

### Decisiones que tiene que tomar Cristian
1. **Después de la Fase 0:** ¿se acepta que en un reembolso parcial Rezon.ar devuelva su parte proporcional (casi todo el fee)? Si no se acepta, el "neto" no se puede hacer por la API.
2. **¿Total, parcial por el neto, o las dos?** ¿Y cuál por defecto cuando el evento se cancela? Esto incluye una consulta legal sobre devolver menos de lo pagado.
3. **¿Quién puede devolver?** Cualquier admin de la página, sólo el dueño, o con aprobación del superadmin (¿desde qué monto?). ¿Función habilitada por página?
4. **Compras modificadas:** ¿cómo se reparte el neto por entrada cuando la compra tiene más entradas que las pagadas?
5. **Entradas ya usadas en la puerta:** ¿se bloquean o se permite con confirmación?
6. **Contracargos:** ¿se anula el QR automáticamente o sólo se avisa al organizador?
7. **¿Se incluyen los productos** en el alcance o sólo las entradas?
8. **Política visible para el comprador** (texto) y si se pide una cuenta de prueba de MP para la Fase 0.
