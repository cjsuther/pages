/**
 * Service worker de Rezonar.
 *
 * Ver GUIA-PUSH-PWA.md §4.3. Lo importante no es mostrar la notificación
 * —eso son diez líneas— sino confirmar la entrega: sin el ack sólo sabemos
 * cuántas se enviaron, no cuántas llegaron.
 */

const API = '/api';
const ICONO = '/icon-192.png';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// --------------------------------------------------------------------- push

self.addEventListener('push', (event) => {
  let datos = {};

  try {
    datos = event.data ? event.data.json() : {};
  } catch {
    datos = { titulo: 'Novedad en Rezonar', cuerpo: event.data ? event.data.text() : '' };
  }

  const opciones = {
    body: datos.cuerpo || '',
    icon: ICONO,
    badge: ICONO,
    tag: datos.tag || 'rezonar',
    renotify: true,
    data: {
      url: datos.url || '/',
      id: datos.id,
      enviadoEn: datos.enviadoEn,
    },
    // Los botones sólo se ven en Android; iOS los ignora en silencio.
    actions: [
      { action: 'ver', title: 'Ver' },
      { action: 'descartar', title: 'Descartar' },
    ],
  };

  event.waitUntil(
    (async () => {
      await self.registration.showNotification(datos.titulo || 'Novedad en Rezonar', opciones);
      await confirmarEntrega(datos);
    })()
  );
});

/**
 * Confirmación de entrega: la métrica que importa de verdad.
 *
 * `isSuccess()` en el servidor sólo dice que el servicio de push aceptó el
 * mensaje, no que el teléfono lo recibió.
 */
async function confirmarEntrega(datos) {
  if (!datos.id) return;

  const recibidoEn = Date.now();

  try {
    await fetch(`${API}/push/ack.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: datos.id,
        recibidoEn,
        latenciaMs: datos.enviadoEn ? recibidoEn - datos.enviadoEn : null,
      }),
      keepalive: true, // el worker puede dormirse enseguida
    });
  } catch {
    // Si falla el ack la notificación ya se mostró igual: no se reintenta.
  }
}

// ------------------------------------------------------------------- click

self.addEventListener('notificationclick', (event) => {
  const url = (event.notification.data && event.notification.data.url) || '/';

  event.notification.close();

  if (event.action === 'descartar') return;

  event.waitUntil(
    (async () => {
      const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      // Si la app ya está abierta se reutiliza esa ventana y se navega.
      for (const ventana of ventanas) {
        if ('focus' in ventana) {
          if ('navigate' in ventana) {
            try {
              await ventana.navigate(url);
            } catch {
              // Algunos navegadores no permiten navigate(): al menos enfoca.
            }
          }
          return ventana.focus();
        }
      }

      return self.clients.openWindow(url);
    })()
  );
});

// ------------------------------------------------- renovación de suscripción

/**
 * Si el navegador rota la suscripción hay que volver a registrarla, o el
 * usuario deja de recibir sin que nadie se entere.
 *
 * Acá no hay sesión disponible, así que el endpoint de la clave VAPID es
 * público y el registro se hace con la suscripción vieja como credencial.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    (async () => {
      try {
        const respuesta = await fetch(`${API}/push/vapid.php`);
        const { public_key: clavePublica } = await respuesta.json();

        const nueva = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: base64UrlABytes(clavePublica),
        });

        await fetch(`${API}/push/subscribe.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            suscripcion: nueva.toJSON(),
            renovacion: true,
            endpointAnterior: event.oldSubscription ? event.oldSubscription.endpoint : null,
          }),
        });
      } catch {
        // Sin sesión no se puede completar; el cliente lo rehará al abrir la app.
      }
    })()
  );
});

function base64UrlABytes(base64Url) {
  const relleno = '='.repeat((4 - (base64Url.length % 4)) % 4);
  const base64 = (base64Url + relleno).replace(/-/g, '+').replace(/_/g, '/');
  const crudo = self.atob(base64);
  const salida = new Uint8Array(crudo.length);

  for (let i = 0; i < crudo.length; i++) {
    salida[i] = crudo.charCodeAt(i);
  }

  return salida;
}
