# Prueba de devoluciones en sandbox (antes de prender `DEVOLUCIONES_HABILITADAS`)

> **Para qué:** medir con usuarios de prueba de Mercado Pago qué le pasa a cada cuenta cuando Rezon.ar pide un reembolso **parcial por el neto**. Es la única forma de confirmar o descartar el riesgo abierto:
>
> - **Decisión de Cristian:** el organizador devuelve sólo su parte (el neto) y la comisión de Rezon.ar no se toca.
> - **Lo que dice la documentación de MP** ([Split 1:1, integrar marketplace](https://www.mercadopago.com.br/developers/en/docs/split-payments/split-1-1/integration-configuration/integrate-marketplace)): "the amount due to the final customer will be divided and subtracted from the seller's account and the Marketplace's account, in a proportional way". Si eso pasa también en un parcial, Rezon.ar pone una parte de cada devolución.
>
> **Nada de esto se hace contra producción.** Usuarios de prueba, una base local y un `config.php` local. Todo lo que figura como **[a confirmar]** depende de cómo esté hoy el panel de MP Developers.

## 0. Qué hay que tener

- El repo en la rama `claude/devoluciones-mp`, con `composer install` y `npm ci`.
- Una MariaDB local con el esquema armado desde `migraciones.txt`. Por ejemplo: `mysql -u root < database.sql` y después cada migración en orden. También sirve la base que levanta `api/tests/esquema.php`.
- Un `api/config.php` **local**:
  - `DB_*` apuntando a la base local.
  - `UPLOAD_URL` y `FRONTEND_URL` locales.
  - `MP_APP_ID`, `MP_APP_SECRET` y `MP_OAUTH_REDIRECT_URI` de la **aplicación de prueba** (paso 1).
  - `PLATFORM_FEE_PERCENT = 1`, `MP_FEE_PERCENT = 4.39` y `MP_FEE_IVA_PERCENT = 21`, como en producción.
  - `define('DEVOLUCIONES_HABILITADAS', true);` (sólo en este config local).
- El aviso de pago (`notification_url`) necesita una URL pública. No hace falta: el cron `api/entradas/conciliar.php` acredita las compras buscándolas por referencia. Se corre a mano con `php api/entradas/conciliar.php`.

## 1. Usuarios de prueba y aplicación de marketplace

Documentación: [Cuentas de prueba](https://www.mercadopago.com.ar/developers/es/docs/your-integrations/test/accounts) y [Split 1:1 (marketplace)](https://www.mercadopago.com.ar/developers/es/docs/split-payments/split-1-1/integration-configuration/integrate-marketplace) **[a confirmar: rutas y nombres del panel]**.

1. En *Tus integraciones → Cuentas de prueba*, crear tres usuarios de prueba (país Argentina):
   - **MARKETPLACE**: hace de Rezon.ar y cobra la comisión.
   - **VENDEDOR**: hace de organizador.
   - **COMPRADOR**: hace de quien compra.
2. Entrar con **MARKETPLACE** (en una ventana privada) y crear una aplicación de tipo Checkout Pro / marketplace, con la URL de redirect de OAuth local. Copiar Client ID y Client Secret al `config.php` local.
3. Anotar los saldos iniciales de VENDEDOR y de MARKETPLACE: dinero disponible y dinero a liberar.

## 2. Una venta con comisión

1. Levantar la API y el frontend locales. Con una cuenta de Rezon.ar local, crear una página y un evento con entradas: **1 entrada a $10.000**, `cargos_al_comprador` apagado.
2. En el editor, conectar Mercado Pago por OAuth **iniciando sesión como VENDEDOR**. Verificar en `page_payment_settings` que `conectado_por = 'oauth'`.
3. Comprar 1 entrada **iniciando sesión en el checkout como COMPRADOR**, con una [tarjeta de prueba](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/additional-content/your-integrations/test/cards). El titular `APRO` aprueba el pago.
4. Correr `php api/entradas/conciliar.php` hasta que la compra quede `pagada`.
5. Anotar de la base:
   ```sql
   SELECT codigo, total, comision, mp_neto, mp_comisiones, mp_comision_cobrada, mp_payment_id
   FROM ticket_orders ORDER BY id DESC LIMIT 1;
   ```
   Lo esperable: `total` 10000, `mp_comision_cobrada` 100 y `mp_neto` de alrededor de 9368,81. El neto depende del plazo de liberación de la cuenta VENDEDOR.
6. Anotar los saldos de VENDEDOR y MARKETPLACE después de la venta.

## 3. Devolución de la compra completa (el neto, parcial para MP)

1. En el panel de ventas: **Devolver → Toda la compra → Calcular devolución**. Tiene que proponer exactamente `mp_neto`. Después, **Devolver $…**.
2. Ver el intento y el reparto que guardó la plataforma:
   ```sql
   SELECT id, estado, monto, mp_refund_id, mp_status_http, mp_error_codigo, motivo_rechazo, reparto
   FROM ticket_order_devoluciones ORDER BY id DESC LIMIT 1;
   ```
   En `reparto` quedan:
   - `pago.fee_details` y `pago.application_fee` **después** de devolver.
   - `comision_plataforma_antes`.
   - `toco_comision_plataforma`: `true` si la comisión de plataforma que informa MP cambió.
   - `reembolso.partition_details`: la referencia de la API lo lista pero no lo describe.
3. Anotar los saldos de VENDEDOR, MARKETPLACE y COMPRADOR.
4. Opcional, para comparar con lo que guardó la plataforma: consultar el pago con el token del VENDEDOR. Es un GET, no mueve plata:
   ```bash
   curl -s -H "Authorization: Bearer <ACCESS_TOKEN_DEL_VENDEDOR_DE_PRUEBA>" \
        https://api.mercadopago.com/v1/payments/<mp_payment_id> | jq '{status, status_detail, transaction_amount, transaction_amount_refunded, fee_details, refunds}'
   ```

## 4. Devolución por entrada

1. Otra compra de **2 entradas** ($20.000). Devolver **1 entrada** (tiene que proponer la mitad del neto), y después la otra (tiene que proponer el resto y anular la compra).
2. Anotar el reparto y los saldos después de cada una, igual que en el paso 3.

## 5. Con cargos al comprador

1. Prender `CARGOS_AL_COMPRADOR` en el config local y la llave del evento.
2. Comprar 1 entrada. El total queda en ~$10.666,60 y el neto en ~$10.000.
3. Devolver la compra completa. Repetir las anotaciones.

## 6. Rechazos (la plataforma tiene que dejar el motivo en la compra)

| Caso | Cómo provocarlo | Qué se espera |
|---|---|---|
| Saldo insuficiente | Retirar o transferir el saldo del VENDEDOR antes de devolver **[a confirmar si el dinero a liberar cuenta]** | `rechazada`, motivo "No hay saldo suficiente…", y el código/mensaje de MP guardados crudos |
| Parcial no soportado | Pagar con dinero en cuenta del COMPRADOR o con débito, y devolver | Si MP lo rechaza: código 3024/4295 y motivo "no permite devoluciones parciales" |
| Entradas usadas | Marcar un ingreso desde la puerta y devolver | Bloqueo nuestro, sin llamar a MP |
| Corte de red | Cortar la red justo al confirmar (o simularlo) | `incierta`; el cron la confirma o la descarta sin volver a pedirla |

**Registrar cada código y mensaje real de MP:** el mapeo de `Devoluciones::motivoDeMercadoPago` sale de la referencia de la API y hay que ajustarlo con lo que devuelva MP de verdad (por ejemplo, el texto exacto de saldo insuficiente).

## 7. Tabla de resultados (completar)

| Caso | Devuelto al comprador | Bajó VENDEDOR | Bajó MARKETPLACE | `application_fee` antes / después | `toco_comision_plataforma` |
|---|---|---|---|---|---|
| Completa (neto) | | | | | |
| 1 de 2 entradas | | | | | |
| 2 de 2 entradas | | | | | |
| Con cargos | | | | | |

## 8. Cómo decidir con los resultados

- **Si MARKETPLACE no baja** (o `toco_comision_plataforma = false` y los saldos lo confirman): la decisión 1 se cumple tal cual. Se puede prender `DEVOLUCIONES_HABILITADAS` en producción.
- **Si MARKETPLACE baja proporcionalmente**: el reembolso parcial por el neto le saca a Rezon.ar más o menos `comisión × neto / total` (en el ejemplo, ~$93,69 de $100 si la proporción es sobre el total). El VENDEDOR pone el resto, que es menos que el neto. Eso contradice la decisión 1, y **no se arregla con transferencias** (decisión 2). Opciones para que decida Cristian:
  1. Aceptar el reparto de MP: Rezon.ar resigna esa parte de la comisión en las ventas devueltas.
  2. Pedir un monto mayor para que lo que baje del VENDEDOR sea exactamente su neto. Depende de la fórmula exacta que mida esta prueba, y le devuelve al comprador más que el neto.
  3. Consultar a Mercado Pago (soporte de integraciones) si hay una forma de reembolsar sin tocar el `marketplace_fee` en Checkout Pro. En la documentación pública no aparece (ver abajo).
- Sea cual sea el resultado: anotarlo en `docs/ANALISIS-DEVOLUCIONES.md` y ajustar los textos de la pantalla y del mail.

## ¿Hay una modalidad de reembolso que no toque el `marketplace_fee`?

En la documentación pública revisada (26/11/2026), para nuestra integración (Checkout Pro con `marketplace_fee` en la preferencia), **no hay ninguna**:

- [Create refund (Checkout Pro preferences)](https://www.mercadopago.com.ar/developers/en/reference/online-payments/checkout-pro-preferences/create-refund/post): `POST /v1/payments/{id}/refunds`. El cuerpo sólo acepta `amount` ("If this property (amount) is removed from body, it will create a full refund"). No hay ningún campo de split ni de comisión. La respuesta trae `partition_details`, sin describir. Por eso la plataforma lo guarda en `reparto`.
- [Refund order (Orders API, Brasil)](https://www.mercadopago.com.br/developers/en/reference/online-payments/checkout-pro/refund-order/post): `POST /v1/orders/{order_id}/refund` acepta un arreglo **`splits`** ("Split participants to refund"; el ejemplo trae `user_id` y `amount`). Eso permitiría elegir a qué participante se le descuenta. Pero:
  - es de la **Orders API**, otra integración. Habría que migrar el checkout entero a Orders, cosa que está fuera de alcance;
  - en la versión argentina de la misma referencia ([AR](https://www.mercadopago.com.ar/developers/en/reference/online-payments/checkout-api/refund-order/post)) el campo **no aparece**: sólo `transactions`;
  - no dice nada del `marketplace_fee`.

  **[SIN CONFIRMAR]** para Argentina y para Checkout Pro. Es la pregunta concreta para hacerle a MP en la opción 3.
