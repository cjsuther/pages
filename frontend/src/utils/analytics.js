/**
 * Medición con Google Analytics.
 *
 * La propiedad se declara en un solo lugar: VITE_GA_MEASUREMENT_ID. Estaba en
 * dos —en duro en el index.html y por variable acá—, y la variable no estaba
 * definida en producción, así que todo lo que mandaba la aplicación iba a
 * G-XXXXXXXXXX, un valor de relleno que no existe. Se veía sólo la primera
 * carga de cada visita; toda la navegación interna se perdía.
 *
 * El tag lo carga esta misma función y no el index.html. Es lo que permite que
 * la propiedad esté en un solo lado, y que sin propiedad configurada no se
 * cargue nada en vez de pedirle a Google un identificador inventado.
 */
export const GA_MEASUREMENT_ID = import.meta.env.VITE_GA_MEASUREMENT_ID || '';

const URL_TAG = 'https://www.googletagmanager.com/gtag/js?id=';

/**
 * La URL que se le informa a Google: sin lo que va después del #.
 *
 * Detrás del # viajan credenciales: el token de /descarga#<token> y las
 * claves de /puerta# y /venta#. Están ahí justamente para que no lleguen a
 * ningún servidor, y gtag, si no se le dice otra cosa, manda
 * document.location entero con cada vista y cada evento. Por eso se fija esta
 * en la configuración y se vuelve a fijar (con `set`) en cada vista: los
 * eventos que salen después la toman de ahí.
 */
export function urlSinHash() {
  if (typeof window === 'undefined' || !window.location) return '';

  const { origin, pathname, search } = window.location;

  return `${origin}${pathname}${search}`;
}

/** Sin propiedad no se mide: en desarrollo y en los tests no hay ninguna. */
function seMide() {
  return GA_MEASUREMENT_ID !== '' && typeof window.gtag === 'function';
}

/**
 * Carga el tag y configura la propiedad. Se llama una vez, al arrancar.
 *
 * `send_page_view: false` es lo que hace que las vistas las mande la
 * aplicación. Esto es una SPA: Google sólo vería la primera pantalla de cada
 * visita, porque después no hay más cargas de documento. Y si las mandaran los
 * dos, esa primera pantalla contaría doble.
 */
export function iniciarAnalytics() {
  if (GA_MEASUREMENT_ID === '' || typeof document === 'undefined') {
    return false;
  }

  if (typeof window.gtag === 'function') {
    return false;
  }

  const tag = document.createElement('script');
  tag.async = true;
  tag.src = URL_TAG + encodeURIComponent(GA_MEASUREMENT_ID);
  document.head.appendChild(tag);

  window.dataLayer = window.dataLayer || [];
  // Tiene que ser `arguments` y no un rest: gtag guarda el objeto crudo y
  // Google lo lee así.
  window.gtag = function gtag() { window.dataLayer.push(arguments); };

  window.gtag('js', new Date());
  window.gtag('config', GA_MEASUREMENT_ID, { send_page_view: false, page_location: urlSinHash() });

  return true;
}

/**
 * Una vista de pantalla.
 *
 * Va como evento y no como `config`. Un `config` por cada cambio de ruta
 * reconfigura la propiedad entera, que es lo que se hacía antes y es la razón
 * por la que había que nombrarla acá: nombrarla mal mandaba la vista a otro
 * lado. Un evento va a la propiedad que ya está configurada.
 */
export const pageview = (url, title) => {
  if (!seMide()) return;

  const ubicacion = urlSinHash();

  // Para los eventos que salgan desde esta pantalla (trackEvent).
  window.gtag('set', { page_location: ubicacion });
  window.gtag('event', 'page_view', {
    page_path: url,
    page_title: title,
    page_location: ubicacion,
  });
};

const sendEvent = (action, params = {}) => {
  if (!seMide()) return;

  window.gtag('event', action, params);
};

export const trackEvent = {
  event: (action, params = {}) => {
    sendEvent(action, params);
  },
  userLogin: (method = 'email') => {
    sendEvent('login', {
      method: method
    });
  },

  userRegister: (method = 'email') => {
    sendEvent('sign_up', {
      method: method
    });
  },

  createPage: (pageId) => {
    sendEvent('create_page', {
      page_id: pageId
    });
  },

  editPage: (pageId) => {
    sendEvent('edit_page', {
      page_id: pageId
    });
  },

  addLink: (linkType) => {
    sendEvent('add_link', {
      link_type: linkType
    });
  },

  addEvent: (eventId) => {
    sendEvent('add_event', {
      event_id: eventId
    });
  },

  viewPublicPage: (slug) => {
    sendEvent('view_public_page', {
      page_slug: slug
    });
  },

  clickLink: (linkUrl, linkTitle) => {
    sendEvent('click_link', {
      link_url: linkUrl,
      link_title: linkTitle
    });
  },

  shareEvent: (eventId, shareMethod) => {
    sendEvent('share_event', {
      event_id: eventId,
      method: shareMethod
    });
  },

  searchPages: (query) => {
    sendEvent('search', {
      search_term: query
    });
  },

  viewMap: () => {
    sendEvent('view_map');
  },

  interactMap: (action) => {
    sendEvent('map_interaction', {
      interaction_type: action
    });
  },

  changeTemplate: (templateName) => {
    sendEvent('change_template', {
      template_name: templateName
    });
  },

  uploadImage: (imageType) => {
    sendEvent('upload_image', {
      image_type: imageType
    });
  }
};
