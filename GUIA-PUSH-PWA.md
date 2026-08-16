# Notificaciones push en PWA — guía de implementación para iOS y Android

Guía prescriptiva basada en una implementación **probada en dispositivos reales**
(Android Chrome e iPhone 11 Pro Max con iOS 26, Safari). Incluye los errores que
efectivamente ocurrieron durante esa implementación y cómo evitarlos.

**Resultado verificado:** 100% de entrega en ambas plataformas, latencia de 1 a
2,6 segundos, incluyendo notificaciones recibidas con la aplicación cerrada y el
teléfono bloqueado.

> **Cómo usar este documento.** Está escrito para que otro desarrollador o
> sistema lo implemente sin repetir los errores. Las secciones 2 y 5 son las
> críticas: contienen los dos fallos que no se detectan probando en una sola
> plataforma. Si se va a leer una sola sección, que sea la 2.

---

## 1. Alcance

### Qué cubre

- Web Push estándar (VAPID + Web Push Protocol) sin servicios de terceros
- Servidor propio en PHP (la lógica es idéntica en Node, Python o Go)
- iOS 16.4+ y Android con Chrome
- Confirmación de entrega real y métricas segmentadas por plataforma

### Qué NO cubre

- Notificaciones locales sin servidor
- Apps nativas o híbridas
- Servicios gestionados como OneSignal o Firebase Cloud Messaging directo

### Requisitos no negociables

| Requisito | Motivo |
|---|---|
| **HTTPS con certificado válido** | Sin esto no hay service worker ni push. `localhost` sirve para desarrollo en Android; **iOS necesita HTTPS real** |
| **Service worker en el scope correcto** | Debe poder controlar la ruta desde la que se llama |
| **Manifiesto con `display: standalone`** | Obligatorio en iOS; sin esto no se puede instalar |
| **Iconos PNG de 192 y 512 px** | Requeridos por el manifiesto |

---

## 2. Los cinco errores que rompen esto

Ordenados por cuánto cuesta descubrirlos tarde. Los dos primeros **no se
detectan probando en una sola plataforma**.

### 2.1 El `sub` de VAPID: Apple lo valida, Google no

**El error más caro de todos.** El JWT de VAPID lleva un campo `sub` (subject).
Debe ser una **URL `https://` real** o un **`mailto:` con dominio existente**.

```php
// ✗ MAL — Android funciona perfecto, iOS devuelve 403 Forbidden
'subject' => 'mailto:push@miapp.local'
'subject' => 'mailto:admin@localhost'
'subject' => 'miapp'

// ✓ BIEN
'subject' => 'https://miapp.com'
'subject' => 'mailto:soporte@miapp.com'   // dominio que existe de verdad
```

**Por qué es peligroso:** FCM (Android) acepta cualquier cosa. APNs (Apple)
valida y responde `403 Forbidden` **sin explicar el motivo**. El síntoma es que
todo funciona en Android, se sale a producción, y ningún usuario de iPhone
recibe nada — sin ningún error visible del lado del cliente.

**Regla:** el `sub` sale de una variable de entorno con el dominio de
producción. Nunca un valor hardcodeado de desarrollo.

### 2.2 En iOS `PushManager` no existe hasta instalar la app

En iOS, `window.PushManager` **no está definido** mientras la PWA no esté
agregada a la pantalla de inicio y abierta desde ahí.

```js
// ✗ MAL — en todo iPhone sin instalar da false, y se culpa a la versión de iOS
if (!('PushManager' in window)) {
  mostrar('Tu navegador no soporta push. Actualizá iOS.');   // mensaje falso
}
```

El usuario va a Ajustes, ve que tiene la última versión, y concluye que la
aplicación está rota. Se pierde ahí.

**El orden correcto de verificación es:**

```
1. ¿Es el navegador correcto?      (iOS: sólo Safari puede instalar)
2. ¿Está instalada?                (iOS: obligatorio para que exista la API)
3. ¿Existe la API?                 (recién acá tiene sentido hablar de versión)
4. ¿Hay permiso?
5. ¿Hay suscripción?
```

### 2.3 En iOS sólo Safari puede instalar

Chrome, Firefox y Edge en iOS usan el motor de Safari por obligación de Apple,
pero **no pueden instalar la PWA en la pantalla de inicio** de forma confiable.

Esto importa porque el enlace suele llegar por WhatsApp o correo, y iOS lo abre
en el navegador por defecto o en el visor interno de la aplicación. Si el
usuario tiene Chrome por defecto, el flujo se rompe sin ningún aviso.

**Hay que detectarlo y decirlo explícitamente.**

```js
const ua = navigator.userAgent;
const esIOS = /iPhone|iPad|iPod/i.test(ua);
const esSafariIOS = !esIOS ||
  (!/CriOS|FxiOS|EdgiOS|OPiOS|Chrome/i.test(ua) && /Safari/i.test(ua));
```

### 2.4 Si el usuario deniega, no se puede volver a pedir

`Notification.requestPermission()` sólo abre el diálogo si el estado es
`default`. Una vez en `denied`, la única salida es que el usuario lo habilite a
mano en Ajustes.

**Consecuencia de diseño:** no pedir el permiso al abrir la aplicación. Pedirlo
**después de que el usuario haya visto para qué sirve** — por ejemplo, tras
crear su primera alerta. Un "no" prematuro es permanente.

### 2.5 La versión de iOS en el User-Agent es falsa

Desde iOS 26, Apple **congela deliberadamente la versión del sistema** en el
User-Agent de Safari como medida antifingerprinting. Un iPhone con iOS 26.6
reporta una versión anterior.

**Nunca condicionar funcionalidad por versión leída del User-Agent.** Siempre
detección de capacidades:

```js
// ✗ MAL
const soporta = parseFloat(versionIOSDelUA()) >= 16.4;

// ✓ BIEN
const soporta = 'serviceWorker' in navigator
             && 'PushManager' in window
             && 'Notification' in window;
```

---

## 3. Dependencias del servidor

### PHP

```bash
composer require minishlink/web-push
composer require php-http/guzzle7-adapter guzzlehttp/guzzle
```

> **`minishlink/web-push` no trae cliente HTTP.** Sin un paquete que implemente
> PSR-18 falla en tiempo de ejecución con
> `No PSR-18 clients found. Make sure to install a package providing
> "psr/http-client-implementation"`. El error aparece **sólo al intentar
> enviar**, no al instalar ni al suscribir.

### Extensiones de PHP requeridas

| Extensión | Para qué | Riesgo |
|---|---|---|
| `gmp` | Firma VAPID (curva elíptica) | **La que más falta en hosting compartido.** Verificar antes de comprometerse |
| `openssl` | Cifrado del payload | Casi siempre presente |
| `curl` | Cliente HTTP | Casi siempre presente |
| `mbstring` | Manejo de cadenas | Casi siempre presente |

```bash
php -m | grep -E 'gmp|openssl|curl|mbstring'
```

**Sin `gmp` no se pueden firmar las claves VAPID y no hay push propio posible.**
Verificar esto antes de escribir código.

### Generar las claves VAPID

Una sola vez. Se guardan como secreto, nunca en el repositorio.

```php
$claves = Minishlink\WebPush\VAPID::createVapidKeys();
// => ['publicKey' => 'B...', 'privateKey' => '...']
```

La pública se entrega al cliente; la privada nunca sale del servidor.

---

## 4. Archivos requeridos

### 4.1 `manifest.json`

```json
{
  "name": "Nombre completo de la aplicación",
  "short_name": "Nombre corto",
  "start_url": "./?fuente=pwa",
  "scope": "./",
  "display": "standalone",
  "background_color": "#131C26",
  "theme_color": "#131C26",
  "icons": [
    { "src": "icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any" },
    { "src": "icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any" },
    { "src": "icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ]
}
```

`display: "standalone"` es obligatorio: iOS no habilita push en modo navegador.

### 4.2 `<head>` del HTML

```html
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Nombre corto">
<meta name="theme-color" content="#131C26">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
```

### 4.3 Service worker (`sw.js`)

Lo importante no es mostrar la notificación — eso son diez líneas. Es
**confirmar la entrega**, porque sin eso sólo se sabe cuántas se enviaron, no
cuántas llegaron.

```js
self.addEventListener('install',  () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let datos = {};
  try { datos = event.data ? event.data.json() : {}; }
  catch { datos = { titulo: 'Novedad', cuerpo: event.data?.text() || '' }; }

  const opciones = {
    body:  datos.cuerpo || '',
    icon:  'icon-192.png',
    badge: 'icon-192.png',
    tag:   datos.tag || 'general',
    renotify: true,
    data: { url: datos.url || './', id: datos.id, enviadoEn: datos.enviadoEn },
    // Los botones sólo se ven en Android; iOS los ignora en silencio.
    actions: datos.acciones || [
      { action: 'ver',      title: 'Ver' },
      { action: 'posponer', title: 'Posponer' },
    ],
  };

  event.waitUntil((async () => {
    await self.registration.showNotification(datos.titulo || 'Novedad', opciones);
    await confirmarEntrega(datos);
  })());
});

// Confirmación de entrega: la métrica que importa de verdad.
async function confirmarEntrega(datos) {
  if (!datos.id) return;
  const recibidoEn = Date.now();
  try {
    await fetch('api/ack', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: datos.id,
        recibidoEn,
        latenciaMs: datos.enviadoEn ? recibidoEn - datos.enviadoEn : null,
      }),
      keepalive: true,   // el worker puede dormirse enseguida
    });
  } catch { /* si falla el ack, la notificación ya se mostró igual */ }
}

self.addEventListener('notificationclick', (event) => {
  const url = event.notification.data?.url || './';
  event.notification.close();
  if (event.action === 'posponer') return;

  event.waitUntil((async () => {
    const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const v of ventanas) if ('focus' in v) return v.focus();
    return self.clients.openWindow(url);
  })());
});

// Si el navegador rota la suscripción hay que volver a registrarla,
// o el usuario deja de recibir sin que nadie se entere.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const { publicKey } = await (await fetch('api/vapid')).json();
    const nueva = await self.registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: base64UrlABytes(publicKey),
    });
    await fetch('api/subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ suscripcion: nueva, renovacion: true }),
    });
  })());
});

function base64UrlABytes(b64url) {
  const relleno = '='.repeat((4 - (b64url.length % 4)) % 4);
  const b64 = (b64url + relleno).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from([...self.atob(b64)].map(c => c.charCodeAt(0)));
}
```

---

## 5. Lógica del cliente — el orden importa

Esta es la parte donde se cometen los errores 2.2 y 2.3.

```js
const estado = {};

function detectar() {
  const ua = navigator.userAgent;

  estado.standalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;          // ← iOS usa esta segunda forma

  estado.esIOS     = /iPhone|iPad|iPod/i.test(ua);
  estado.esAndroid = /Android/i.test(ua);

  estado.esSafariIOS = !estado.esIOS ||
    (!/CriOS|FxiOS|EdgiOS|OPiOS|Chrome/i.test(ua) && /Safari/i.test(ua));

  estado.soportaPush =
    'serviceWorker' in navigator &&
    'PushManager'  in window &&
    'Notification' in window;

  estado.permiso = 'Notification' in window ? Notification.permission : 'no-soportado';
}

// ORDEN OBLIGATORIO: navegador → instalación → capacidad → permiso
function guiarUsuario() {
  if (estado.esIOS && !estado.esSafariIOS) {
    return mostrar('Abrilo en Safari',
      'En iPhone sólo Safari puede instalar la aplicación. Copiá la dirección y abrila en Safari.');
  }

  if (estado.esIOS && !estado.standalone) {
    return mostrar('Agregala a la pantalla de inicio',
      'Compartir → Agregar a inicio → Agregar. Después abrila desde el ícono, no desde Safari. ' +
      'Sin este paso el iPhone no permite notificaciones.');
  }

  // Recién acá tiene sentido hablar de versión: ya está instalada.
  if (!estado.soportaPush) {
    return mostrar('Este dispositivo no soporta push',
      estado.esIOS ? 'Requiere iOS 16.4 o superior.' : 'Probá con Chrome actualizado.');
  }

  if (estado.permiso === 'denied') {
    return mostrar('Permiso denegado',
      'No se puede volver a pedir por código. Habilitalo en Ajustes → Notificaciones.');
  }

  return null;   // todo en orden
}
```

### Suscripción

```js
async function suscribir() {
  const { publicKey } = await (await fetch('api/vapid')).json();

  const reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
  await navigator.serviceWorker.ready;

  let sus = await reg.pushManager.getSubscription();
  if (!sus) {
    sus = await reg.pushManager.subscribe({
      userVisibleOnly: true,          // obligatorio: no se permite push silencioso
      applicationServerKey: base64UrlABytes(publicKey),
    });
  }

  await fetch('api/subscribe', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ suscripcion: sus, standalone: estado.standalone }),
  });
}
```

### Instalación en Android

Android permite instalación con un toque; iOS no dispara nunca este evento.

```js
let promptInstalar = null;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  promptInstalar = e;
  document.getElementById('btnInstalar').hidden = false;
});

async function instalar() {
  if (!promptInstalar) return;
  promptInstalar.prompt();
  await promptInstalar.userChoice;
  promptInstalar = null;
}
```

### Momento de pedir el permiso

```js
// ✗ MAL — un "no" acá es permanente
window.addEventListener('load', () => Notification.requestPermission());

// ✓ BIEN — después de que el usuario entienda para qué sirve
async function trasCrearPrimeraAlerta() {
  const r = await Notification.requestPermission();
  if (r === 'granted') await suscribir();   // encadenar: pedir sin suscribir no sirve
}
```

---

## 6. Backend

### Guardar la suscripción

Campos mínimos por dispositivo:

| Campo | Uso |
|---|---|
| `endpoint` | Identifica el dispositivo. **Clave única** — reemplazar si ya existe, para no duplicar al recargar |
| `p256dh`, `auth` | Claves de cifrado del payload |
| `plataforma` | `iOS` / `Android`. **Imprescindible para segmentar métricas** |
| `marca` | Samsung, Xiaomi, etc. Para las guías de batería |
| `standalone` | Si estaba instalada al suscribirse |
| `user_agent` | Diagnóstico |

### Enviar

```php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$webPush = new WebPush([
    'VAPID' => [
        // Ver sección 2.1. URL https real o mailto con dominio existente.
        'subject'    => getenv('VAPID_SUBJECT'),      // p.ej. https://miapp.com
        'publicKey'  => getenv('VAPID_PUBLIC_KEY'),
        'privateKey' => getenv('VAPID_PRIVATE_KEY'),
    ],
]);
$webPush->setReuseVAPIDHeaders(true);   // menos firmas si se envía a muchos

$enviadoEn = (int) round(microtime(true) * 1000);

foreach ($suscripciones as $s) {
    $idEnvio = bin2hex(random_bytes(5));

    $webPush->queueNotification(
        Subscription::create([
            'endpoint'  => $s['endpoint'],
            'publicKey' => $s['p256dh'],
            'authToken' => $s['auth'],
        ]),
        json_encode([
            'titulo'    => $titulo,
            'cuerpo'    => $cuerpo,
            'id'        => $idEnvio,      // para correlacionar el ack
            'enviadoEn' => $enviadoEn,    // para calcular latencia real
            'url'       => './',
        ], JSON_UNESCAPED_UNICODE)
    );

    registrarEnvio($idEnvio, $s['id'], $s['plataforma'], $enviadoEn);
}

foreach ($webPush->flush() as $reporte) {
    if (!$reporte->isSuccess()) {
        registrarFallo($reporte->getRequest()->getUri(), $reporte->getReason());
    }
    // 404 o 410 => la suscripción ya no existe: borrarla de la base.
    if ($reporte->isSubscriptionExpired()) {
        borrarSuscripcion((string) $reporte->getRequest()->getUri());
    }
}
```

> **`isSuccess()` significa «el servicio de push lo aceptó», no «el teléfono lo
> recibió».** Esa diferencia es el motivo de toda la sección 7.

### Encolar, no enviar en línea

Enviar a N dispositivos puede tardar más que el tiempo máximo de un request.
El envío va a una cola. En hosting compartido sin procesos permanentes, la cola
se dispara con un cron de un minuto:

```
* * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1
```

```php
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
```

`--max-time=50` mata al worker antes del minuto siguiente para que nunca haya
dos corriendo a la vez.

---

## 7. Medición

**Medir envíos no sirve.** Hay que medir entregas confirmadas, y segmentar por
plataforma.

| Métrica | Qué es | Por qué |
|---|---|---|
| **Enviadas** | El servicio de push aceptó el mensaje | Sólo dice que el servidor hizo su parte |
| **Confirmadas** | El service worker reportó haberlo recibido | Lo único que prueba que el usuario pudo verlo |
| **Latencia** | `recibidoEn − enviadoEn` | Donde aparece el retraso por ahorro de batería |
| **Tasa por plataforma** | Confirmadas / enviadas, separado iOS y Android | **Un 100% global puede esconder un 0% en iOS** |

Valores de referencia medidos en dispositivos reales:

| Plataforma | Entrega | Latencia | Con la app cerrada |
|---|---|---|---|
| Android Chrome | 100% | ~2,3 s | 2,6 s |
| iOS 26 Safari | 100% | 1,0–2,4 s | correcto |

**Estas son fallas silenciosas: nadie reclama por una notificación que nunca
vio.** Si no se instrumenta, no se detecta.

---

## 8. Diferencias entre plataformas

| Aspecto | Android | iOS |
|---|---|---|
| Versión mínima | Chrome moderno | **iOS 16.4+** |
| Instalación | Un toque con `beforeinstallprompt` | **Manual**: Compartir → Agregar a inicio |
| ¿Se puede instalar desde otro navegador? | Sí | **No, sólo Safari** |
| ¿Push sin instalar? | Sí | **No** |
| Qué queda instalado | WebAPK: app real con ícono y ajustes propios | Acceso directo |
| Botones de acción | Sí | Se ignoran |
| Imagen en la notificación | Sí | No |
| Permisos | **Dos** en Android 13+: sistema (`POST_NOTIFICATIONS`, apagado por defecto) + navegador | Uno |
| Validación del `sub` de VAPID | Laxa | **Estricta: 403 si es inválido** |
| Riesgo principal | Fabricante mata procesos en segundo plano | Que nunca se instale |

### Android: el ahorro de batería del fabricante

Los fabricantes matan procesos en segundo plano y eso **demora o bloquea** las
notificaciones. Por agresividad: Huawei y Xiaomi son los peores, Samsung también
está alto, Motorola es moderado, y el Android puro no tiene el problema.

En Latinoamérica, Samsung y Xiaomi son cerca de la mitad del parque, y los
equipos de gama baja lo sufren más porque el sistema descarta antes el service
worker.

**Una app nativa tiene exactamente el mismo problema** — no es una limitación de
las PWA. La única ventaja de la nativa es que puede pedir la exención de
optimización de batería con un diálogo del sistema.

**Mitigación:** detectar la marca y mostrar la instrucción concreta.

```js
const guias = {
  Xiaomi:  'Ajustes → Aplicaciones → [App] → Ahorro de batería → Sin restricciones. ' +
           'Y en Seguridad → Inicio automático, habilitala.',
  Samsung: 'Ajustes → Batería → Límites de uso en segundo plano → ' +
           'sacar [App] de «Aplicaciones en suspensión».',
  Huawei:  'Ajustes → Batería → Inicio de aplicaciones → [App] → gestión manual.',
  Oppo:    'Ajustes → Batería → Optimización → [App] → No optimizar.',
  Realme:  'Ajustes → Batería → Optimización → [App] → No optimizar.',
};

const marcas = {
  Xiaomi:   /XiaoMi|MI \d|Redmi|POCO/i,
  Samsung:  /SM-[A-Z]|SAMSUNG|GT-/i,
  Motorola: /Moto |motorola|XT\d{4}/i,
  Huawei:   /HUAWEI|Honor/i,
  Oppo:     /OPPO|CPH\d/i,
  Realme:   /RMX\d|realme/i,
};
```

### Android 13+: dos permisos

Desde Android 13, el WebAPK necesita el permiso de sistema
`POST_NOTIFICATIONS`, que **viene desactivado por defecto**, además del permiso
web del navegador. Un usuario puede conceder uno y no recibir nada porque el
otro quedó apagado. Hay que verificar los dos y guiar al que falte.

---

## 9. Diagnóstico de fallas

| Síntoma | Causa | Solución |
|---|---|---|
| `403 Forbidden` sólo en iOS | `sub` de VAPID inválido | URL `https://` real o mailto con dominio existente (2.1) |
| `No PSR-18 clients found` | Falta cliente HTTP | `composer require php-http/guzzle7-adapter` |
| `PushManager` no existe en iPhone | No está instalada en pantalla de inicio | Guiar la instalación; no culpar a la versión (2.2) |
| No aparece el botón de instalar en iOS | Apple no implementa `beforeinstallprompt` | Instrucciones manuales con capturas |
| `requestPermission()` no abre nada | El estado ya es `denied` | Sólo se resuelve desde Ajustes (2.4) |
| Sin push en iPhone abierto en Chrome | Sólo Safari instala en iOS | Detectar y redirigir (2.3) |
| Enviadas ≫ confirmadas en Android | Ahorro de batería del fabricante | Guía por marca (sección 8) |
| Service worker no registra | Se sirve con MIME incorrecto | Debe ser `application/javascript` |
| Funciona en desarrollo, no en producción | HTTPS ausente o certificado inválido | Certificado válido; iOS no acepta autofirmado |
| `410 Gone` al enviar | Suscripción caducada | Borrarla de la base al detectarla |

### Túneles para desarrollo

Para probar en dispositivos reales hace falta HTTPS público.

> **ngrok en su plan gratuito no sirve.** Intercala una página de advertencia
> para navegadores; el efecto es que `sw.js` se entrega como `text/html` y **el
> service worker no registra**. Verificado durante esta implementación.
>
> **Usar Cloudflare Tunnel**, que no intercala nada:
> ```bash
> cloudflared tunnel --url http://127.0.0.1:8000
> ```

Comprobación rápida de que el túnel sirve los tipos correctos:

```bash
UA="Mozilla/5.0 (Linux; Android 14; SM-A546E) Chrome/126.0 Mobile Safari/537.36"
curl -s -A "$UA" -o /dev/null -w "%{http_code} %{content_type}\n" "$URL/sw.js"
# esperado: 200 application/javascript
```

---

## 10. Lista de verificación

### Antes de escribir código

- [ ] `php -m | grep gmp` devuelve resultado — **si no, no hay push propio posible**
- [ ] Hay HTTPS con certificado válido en el entorno de pruebas
- [ ] Está definido el dominio real para el `sub` de VAPID

### Implementación

- [ ] Claves VAPID generadas y guardadas como secreto, fuera del repositorio
- [ ] `sub` de VAPID es URL `https://` o mailto con dominio real
- [ ] `manifest.json` con `display: standalone` e iconos de 192 y 512
- [ ] Service worker registrado con el scope correcto
- [ ] Service worker **confirma la entrega** al servidor
- [ ] Manejo de `pushsubscriptionchange`
- [ ] Orden de verificación: navegador → instalación → capacidad → permiso
- [ ] Detección de navegador no-Safari en iOS con mensaje propio
- [ ] Permiso pedido **después** de mostrar el valor, nunca al cargar
- [ ] `beforeinstallprompt` capturado para instalación en un toque en Android
- [ ] Guía de instalación manual para iOS con capturas
- [ ] Detección de marca con guía de batería para Android
- [ ] Suscripciones caducadas (404/410) borradas de la base
- [ ] Envío encolado, no en línea
- [ ] Métricas segmentadas por plataforma

### Pruebas de aceptación

Con dispositivos reales, no con emuladores:

- [ ] Android: instalar, permiso, suscripción, recepción con la app abierta
- [ ] **Android: recepción con la app cerrada y el teléfono bloqueado**
- [ ] **Android: probar en un Samsung o Xiaomi con el teléfono quieto 30 minutos**
- [ ] iOS: instalación desde Safari con las instrucciones
- [ ] iOS: permiso, suscripción, recepción con la app abierta
- [ ] **iOS: recepción con la app cerrada y el teléfono bloqueado**
- [ ] iOS: verificar que abrir en Chrome muestra el mensaje correcto
- [ ] Denegar el permiso a propósito y comprobar que el mensaje es útil
- [ ] Tasa de entrega **por plataforma** por encima del 95%

---

## 11. Resumen de lo aprendido

1. **Probar en las dos plataformas desde el principio.** El error del `sub` de
   VAPID sólo aparece en iOS y no da ninguna pista.
2. **Verificar en el orden correcto.** Preguntar por la capacidad antes que por
   la instalación produce mensajes de error que mandan al usuario al lugar
   equivocado.
3. **La instalación en iOS es una pantalla de producto, no un detalle técnico.**
   Es donde se pierde la mayoría de los usuarios.
4. **Medir entregas confirmadas, segmentadas por plataforma.** Las fallas de
   push son silenciosas.
5. **El ahorro de batería de Android no se resuelve con una app nativa.** Es del
   sistema operativo; se mitiga guiando al usuario.
6. **Nunca condicionar por versión leída del User-Agent.** Apple la falsea a
   propósito. Detección de capacidades, siempre.
