/**
 * Detección de entorno PWA y guía de instalación.
 *
 * Ver GUIA-PUSH-PWA.md §2 y §5. Dos reglas que gobiernan todo este archivo:
 *
 * 1. El orden de verificación es navegador → instalación → capacidad → permiso.
 *    Preguntar por la capacidad antes que por la instalación produce mensajes
 *    que mandan al usuario al lugar equivocado: en iOS `PushManager` no existe
 *    hasta que la app está agregada a la pantalla de inicio, así que un
 *    "tu navegador no soporta notificaciones" es literalmente falso y el
 *    usuario se va a Ajustes a buscar una actualización que no le falta.
 *
 * 2. Nunca se condiciona nada por la versión leída del User-Agent. Desde
 *    iOS 26 Apple la congela a propósito. Siempre detección de capacidades.
 */

export const PASOS = {
  NAVEGADOR: 'navegador',
  INSTALAR: 'instalar',
  SOLO_MOVIL: 'solo_movil',
  SOPORTE: 'soporte',
  PERMISO_DENEGADO: 'permiso_denegado',
  LISTO: 'listo',
};

/** Guías de ahorro de batería por marca (guía §8). */
const GUIAS_BATERIA = {
  Xiaomi:
    'Ajustes → Aplicaciones → Rezonar → Ahorro de batería → Sin restricciones. ' +
    'Y en Seguridad → Inicio automático, habilitala.',
  Samsung:
    'Ajustes → Batería → Límites de uso en segundo plano → sacar Rezonar de ' +
    '«Aplicaciones en suspensión».',
  Huawei: 'Ajustes → Batería → Inicio de aplicaciones → Rezonar → gestión manual.',
  Oppo: 'Ajustes → Batería → Optimización → Rezonar → No optimizar.',
  Realme: 'Ajustes → Batería → Optimización → Rezonar → No optimizar.',
  Motorola: 'Ajustes → Batería → Optimización de batería → Rezonar → No optimizar.',
};

const MARCAS = {
  Xiaomi: /XiaoMi|MI \d|Redmi|POCO/i,
  Samsung: /SM-[A-Z]|SAMSUNG|GT-/i,
  Motorola: /Moto |motorola|XT\d{4}/i,
  Huawei: /HUAWEI|Honor/i,
  Oppo: /OPPO|CPH\d/i,
  Realme: /RMX\d|realme/i,
};

/**
 * ¿La aplicación está agregada a la pantalla de inicio y abierta desde ahí?
 *
 * Android y escritorio usan display-mode; iOS expone navigator.standalone.
 * Hay que mirar las dos: en iPhone la primera siempre da false.
 */
export function estaInstalada() {
  const porDisplayMode =
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(display-mode: standalone)').matches;

  const porIOS = window.navigator.standalone === true;

  return porDisplayMode || porIOS;
}

/** Marca del dispositivo, para la guía de batería. */
export function detectarMarca(userAgent = navigator.userAgent) {
  const entrada = Object.entries(MARCAS).find(([, patron]) => patron.test(userAgent));
  return entrada ? entrada[0] : null;
}

export function guiaDeBateria(marca) {
  return GUIAS_BATERIA[marca] || null;
}

/** Fotografía del entorno. No decide nada: sólo describe. */
export function detectarEntorno() {
  const ua = navigator.userAgent || '';

  const esIOS = /iPhone|iPad|iPod/i.test(ua);
  const esAndroid = /Android/i.test(ua);

  // En iOS, Chrome/Firefox/Edge usan el motor de Safari pero no instalan la
  // PWA de forma confiable. Fuera de iOS la pregunta no aplica.
  const esSafariIOS =
    !esIOS || (!/CriOS|FxiOS|EdgiOS|OPiOS|Chrome/i.test(ua) && /Safari/i.test(ua));

  const soportaPush =
    'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  return {
    esIOS,
    esAndroid,
    // Ni teléfono ni tablet: una computadora. Los tablets caen en esIOS o
    // esAndroid según su sistema, así que no hacen falta más categorías.
    esEscritorio: !esIOS && !esAndroid,
    esSafariIOS,
    instalada: estaInstalada(),
    soportaPush,
    permiso: 'Notification' in window ? Notification.permission : 'no-soportado',
    marca: detectarMarca(ua),
  };
}

/**
 * Traduce el entorno en el siguiente paso concreto para el usuario.
 *
 * El orden de los `if` es el contrato de este módulo; cambiarlo rompe la guía.
 */
export function diagnosticar(entorno = detectarEntorno()) {
  // 1. Navegador. En iPhone, si no es Safari no se puede ni empezar.
  if (entorno.esIOS && !entorno.esSafariIOS) {
    return {
      paso: PASOS.NAVEGADOR,
      titulo: 'Abrilo en Safari',
      mensaje:
        'En iPhone sólo Safari puede agregar la aplicación a la pantalla de inicio. ' +
        'Copiá la dirección y abrila en Safari para continuar.',
      instrucciones: [
        'Tocá la barra de direcciones y copiá el enlace.',
        'Abrí Safari.',
        'Pegá el enlace y volvé a entrar.',
      ],
      puedeSuscribirse: false,
    };
  }

  // 2. Instalación. En iOS es obligatoria: sin esto la API de push no existe.
  if (entorno.esIOS && !entorno.instalada) {
    return {
      paso: PASOS.INSTALAR,
      titulo: 'Agregá Rezonar a tu pantalla de inicio',
      mensaje:
        'El iPhone sólo permite notificaciones si la aplicación está agregada a la ' +
        'pantalla de inicio. Es un paso y queda hecho para siempre.',
      instrucciones: [
        'Tocá el botón Compartir, abajo en el centro (un cuadrado con una flecha hacia arriba).',
        'Deslizá y elegí «Agregar a inicio».',
        'Tocá «Agregar», arriba a la derecha.',
        'Cerrá Safari y abrí Rezonar desde el ícono nuevo.',
      ],
      puedeSuscribirse: false,
    };
  }

  if (entorno.esAndroid && !entorno.instalada) {
    // En Android push funciona sin instalar, pero instalada llega mucho mejor:
    // el sistema descarta antes el service worker de una pestaña suelta.
    return {
      paso: PASOS.INSTALAR,
      titulo: 'Instalá Rezonar para no perderte nada',
      mensaje:
        'Podés activar las notificaciones igual, pero instalada llegan más rápido y ' +
        'con la aplicación cerrada.',
      instrucciones: [
        'Tocá «Instalar aplicación» acá abajo.',
        'Si no aparece, abrí el menú del navegador (⋮) y elegí «Instalar aplicación» o «Agregar a la pantalla principal».',
      ],
      // A diferencia de iOS, acá sí puede continuar sin instalar.
      puedeSuscribirse: entorno.soportaPush && entorno.permiso !== 'denied',
      opcional: true,
    };
  }

  // 3. Escritorio. Chrome y Firefox de computadora declaran soporte de push,
  // así que sin esto el diagnóstico llegaba hasta el final y ofrecía activarlas.
  // Acá las notificaciones no llegan a funcionar, y ofrecer un botón que no
  // hace nada es peor que no ofrecer ninguno.
  if (entorno.esEscritorio) {
    return {
      paso: PASOS.SOLO_MOVIL,
      titulo: 'Las notificaciones son del teléfono',
      mensaje:
        'Se activan desde el teléfono, con la aplicación agregada a la pantalla ' +
        'de inicio. Entrá a Rezonar desde el celular para configurarlas.',
      instrucciones: [],
      puedeSuscribirse: false,
    };
  }

  // 4. Capacidad. Recién ahora hablar de versión tiene sentido.
  if (!entorno.soportaPush) {
    return {
      paso: PASOS.SOPORTE,
      titulo: 'Este dispositivo no admite notificaciones',
      mensaje: entorno.esIOS
        ? 'Se necesita iOS 16.4 o superior.'
        : 'Probá con Chrome actualizado.',
      instrucciones: [],
      puedeSuscribirse: false,
    };
  }

  // 5. Permiso. Un "no" es definitivo: no se puede volver a preguntar.
  if (entorno.permiso === 'denied') {
    return {
      paso: PASOS.PERMISO_DENEGADO,
      titulo: 'Las notificaciones están bloqueadas',
      mensaje:
        'El navegador ya no deja volver a preguntar desde la aplicación. Hay que ' +
        'habilitarlas a mano.',
      instrucciones: entorno.esIOS
        ? ['Ajustes → Notificaciones → Rezonar → Permitir notificaciones.']
        : ['Ajustes del sistema → Aplicaciones → Rezonar → Notificaciones → Permitir.'],
      puedeSuscribirse: false,
    };
  }

  return {
    paso: PASOS.LISTO,
    titulo: 'Todo listo',
    mensaje: 'Podés activar las notificaciones de los eventos que te interesan.',
    instrucciones: [],
    puedeSuscribirse: true,
  };
}

/** Convierte la clave VAPID en el formato que espera pushManager.subscribe. */
export function base64UrlABytes(base64Url) {
  const relleno = '='.repeat((4 - (base64Url.length % 4)) % 4);
  const base64 = (base64Url + relleno).replace(/-/g, '+').replace(/_/g, '/');
  const crudo = window.atob(base64);
  const salida = new Uint8Array(crudo.length);

  for (let i = 0; i < crudo.length; i++) {
    salida[i] = crudo.charCodeAt(i);
  }

  return salida;
}
