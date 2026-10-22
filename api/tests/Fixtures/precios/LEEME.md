# Muestras reales de precios (octubre 2026)

Recortes de páginas y respuestas reales para los tests de precios de los
importadores. Se bajaron con el mismo User-Agent que usan los importadores.

## niceto/
La agenda de Niceto no publica precios. Cada evento linkea a su ficha de Venti,
que trae un JSON-LD `Event` con `offers.price` (string, pesos enteros).
- `agenda-tarjetas-sin-precio.html`: dos tarjetas de la agenda, sin precio.
- `venti-pago-15000.html`: `offers.price "15000"`.
- `venti-pago-65000-desde.html`: `offers.price "65000"`. La ficha muestra "Desde $ 65.000": es el mínimo.
- `venti-precio-0-reserva-pago-en-puerta.html`: `offers.price "0"`, que **no** es gratis. Es una reserva con pago en puerta y hay otra entrada de $15.000. Un `0` de Venti tiene que dar `precio_desde = null`.

## artmedia/
La API de C Art Media no trae precio. `tipoBoton` vale `comprar`, `Agotado` o `gratis`, y en el gratis `type` vale `"Gratis"`.
- `api-events-gratis-comprar-agotado.json`: respuesta real, con la envoltura `body` en string, recortada a 5 eventos:
  - 784: gratis;
  - 757: comprar, en Passline;
  - 751: comprar, en AllAccess;
  - 772: comprar, en Enigma;
  - 650: Agotado.
- `allaccess-la-joaqui-50000.html`: JSON-LD con `offers` como lista; `offers[0].price` = 50000, número.
- `enigmatickets-six-sex-55000-fragmento.json`: `schedules.<uuid>.enabledPrices.<nombre>` con `price` 55000 y `free: false`.

Passline (37 de 41 eventos de C Art Media) responde 403 con el desafío de Cloudflare, aun con un User-Agent de navegador: no se puede leer y no se intenta saltear.

El Teatro Colón no publica precios en ningún lugar legible sin JavaScript:
- el calendario y las fichas no traen precios;
- entradasba es Blazor por websocket;
- Tuentrada está detrás de Cloudflare.
