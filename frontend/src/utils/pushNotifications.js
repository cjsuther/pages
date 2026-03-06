const apiUrl = import.meta.env.VITE_API_URL;

export async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    console.log('Service Worker no soportado');
    return null;
  }

  try {
    const registration = await navigator.serviceWorker.register('/sw.js');
    console.log('Service Worker registrado:', registration);
    return registration;
  } catch (error) {
    console.error('Error registrando Service Worker:', error);
    return null;
  }
}

export async function requestNotificationPermission() {
  if (!('Notification' in window)) {
    console.log('Notificaciones no soportadas');
    return false;
  }

  if (Notification.permission === 'granted') {
    return true;
  }

  if (Notification.permission === 'denied') {
    return false;
  }

  const permission = await Notification.requestPermission();
  return permission === 'granted';
}

export async function subscribeToPushNotifications(token) {
  try {
    const hasPermission = await requestNotificationPermission();
    if (!hasPermission) {
      console.log('Permiso de notificaciones denegado');
      return false;
    }

    const registration = await registerServiceWorker();
    if (!registration) {
      console.log('No se pudo registrar el Service Worker');
      return false;
    }

    // Obtener la clave pública VAPID del servidor
    const vapidResponse = await fetch(`${apiUrl}/notifications/subscribe.php`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const vapidData = await vapidResponse.json();
    const publicKey = vapidData.public_key;

    if (!publicKey) {
      console.error('No se pudo obtener la clave pública VAPID');
      return false;
    }

    // Suscribirse a push notifications
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey)
    });

    // Enviar la suscripción al servidor
    const response = await fetch(`${apiUrl}/notifications/subscribe.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(subscription)
    });

    if (response.ok) {
      console.log('Suscrito a push notifications');
      return true;
    } else {
      console.error('Error al guardar suscripción en el servidor');
      return false;
    }
  } catch (error) {
    console.error('Error en subscribeToPushNotifications:', error);
    return false;
  }
}

export async function unsubscribeFromPushNotifications(token) {
  try {
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration) {
      return true;
    }

    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      return true;
    }

    // Desuscribirse localmente
    await subscription.unsubscribe();

    // Eliminar del servidor
    await fetch(`${apiUrl}/notifications/subscribe.php`, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({ endpoint: subscription.endpoint })
    });

    console.log('Desuscrito de push notifications');
    return true;
  } catch (error) {
    console.error('Error al desuscribirse:', error);
    return false;
  }
}

export async function isPushNotificationSubscribed() {
  try {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      return false;
    }

    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration) {
      return false;
    }

    const subscription = await registration.pushManager.getSubscription();
    return subscription !== null;
  } catch (error) {
    console.error('Error verificando suscripción:', error);
    return false;
  }
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding)
    .replace(/\-/g, '+')
    .replace(/_/g, '/');

  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}
