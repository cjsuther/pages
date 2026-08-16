/**
 * Suscripción a notificaciones push.
 *
 * Ver GUIA-PUSH-PWA.md §5. La regla que ordena este archivo: el permiso se
 * pide *después* de que el usuario entendió para qué sirve, y pedirlo sin
 * encadenar la suscripción no sirve de nada. Un "no" prematuro es permanente:
 * requestPermission() sólo abre el diálogo si el estado es `default`.
 */

import { estaInstalada, base64UrlABytes } from './pwa';

/** Registra el service worker en el scope raíz y espera a que esté activo. */
export async function registrarServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    return null;
  }

  try {
    const registro = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    await navigator.serviceWorker.ready;
    return registro;
  } catch (err) {
    console.error('No se pudo registrar el service worker:', err);
    return null;
  }
}

/**
 * Pide el permiso de notificaciones.
 *
 * @returns {'granted'|'denied'|'default'|'no-soportado'}
 */
export async function pedirPermiso() {
  if (!('Notification' in window)) {
    return 'no-soportado';
  }

  // Ya resuelto: el diálogo no se vuelve a abrir.
  if (Notification.permission !== 'default') {
    return Notification.permission;
  }

  return Notification.requestPermission();
}

/** Clave pública VAPID del servidor. */
export async function obtenerClaveVapid(apiUrl) {
  const respuesta = await fetch(`${apiUrl}/push/vapid.php`);
  const datos = await respuesta.json();

  if (!respuesta.ok || !datos.public_key) {
    throw new Error(datos.error || 'El servidor no tiene configuradas las notificaciones');
  }

  return datos;
}

/**
 * Pide permiso y, si lo concede, suscribe el dispositivo.
 *
 * Los dos pasos van juntos a propósito: pedir el permiso sin registrar la
 * suscripción deja al usuario creyendo que activó algo que no funciona.
 *
 * @returns {{ok: boolean, motivo?: string, permiso?: string}}
 */
export async function activarNotificaciones(apiUrl, token) {
  const permiso = await pedirPermiso();

  if (permiso !== 'granted') {
    return { ok: false, motivo: 'permiso', permiso };
  }

  const registro = await registrarServiceWorker();

  if (!registro) {
    return { ok: false, motivo: 'service-worker' };
  }

  let suscripcion;

  try {
    const { public_key: clavePublica } = await obtenerClaveVapid(apiUrl);

    suscripcion = await registro.pushManager.getSubscription();

    if (!suscripcion) {
      suscripcion = await registro.pushManager.subscribe({
        // Obligatorio: los navegadores no permiten push silencioso.
        userVisibleOnly: true,
        applicationServerKey: base64UrlABytes(clavePublica),
      });
    }
  } catch (err) {
    console.error('No se pudo suscribir al push:', err);
    return { ok: false, motivo: 'suscripcion' };
  }

  const respuesta = await fetch(`${apiUrl}/push/subscribe.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      suscripcion: suscripcion.toJSON(),
      standalone: estaInstalada(),
    }),
  });

  if (!respuesta.ok) {
    return { ok: false, motivo: 'servidor' };
  }

  return { ok: true, permiso };
}

/** Da de baja el dispositivo, local y en el servidor. */
export async function desactivarNotificaciones(apiUrl, token) {
  if (!('serviceWorker' in navigator)) {
    return true;
  }

  try {
    const registro = await navigator.serviceWorker.getRegistration();

    if (!registro) {
      return true;
    }

    const suscripcion = await registro.pushManager.getSubscription();

    if (!suscripcion) {
      return true;
    }

    const { endpoint } = suscripcion;
    await suscripcion.unsubscribe();

    await fetch(`${apiUrl}/push/subscribe.php`, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ endpoint }),
    });

    return true;
  } catch (err) {
    console.error('No se pudo desactivar el push:', err);
    return false;
  }
}

/** ¿Este dispositivo ya está suscrito? */
export async function estaSuscrito() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    return false;
  }

  try {
    const registro = await navigator.serviceWorker.getRegistration();

    if (!registro) {
      return false;
    }

    return (await registro.pushManager.getSubscription()) !== null;
  } catch (err) {
    console.error('No se pudo verificar la suscripción:', err);
    return false;
  }
}
