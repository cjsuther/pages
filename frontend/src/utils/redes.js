/**
 * Catálogo de redes sociales.
 *
 * Es la fuente única: el editor arma el formulario a partir de esta lista y
 * las plantillas dibujan los iconos con ella. Agregar una red es agregar una
 * entrada acá (y el icono, si es de marca).
 *
 * `base` sirve para que el usuario pueda escribir sólo su usuario: si lo que
 * carga no parece una URL, se le antepone.
 */
export const REDES = [
  {
    clave: 'instagram',
    nombre: 'Instagram',
    base: 'https://instagram.com/',
    ejemplo: 'tu-usuario',
  },
  {
    clave: 'tiktok',
    nombre: 'TikTok',
    base: 'https://tiktok.com/@',
    ejemplo: 'tu-usuario',
  },
  {
    clave: 'youtube',
    nombre: 'YouTube',
    base: 'https://youtube.com/@',
    ejemplo: 'tu-canal',
  },
  {
    clave: 'facebook',
    nombre: 'Facebook',
    base: 'https://facebook.com/',
    ejemplo: 'tu-pagina',
  },
  {
    clave: 'whatsapp',
    nombre: 'WhatsApp',
    base: 'https://wa.me/',
    ejemplo: '5491122334455',
    ayuda: 'Con código de país y sin espacios ni signos.',
  },
  {
    clave: 'x',
    nombre: 'X',
    base: 'https://x.com/',
    ejemplo: 'tu-usuario',
    icono: 'lucide',
  },
  {
    clave: 'spotify',
    nombre: 'Spotify',
    base: '',
    ejemplo: 'https://open.spotify.com/artist/...',
    icono: 'lucide',
  },
  {
    clave: 'linkedin',
    nombre: 'LinkedIn',
    base: 'https://linkedin.com/in/',
    ejemplo: 'tu-perfil',
    icono: 'lucide',
  },
  {
    clave: 'telegram',
    nombre: 'Telegram',
    base: 'https://t.me/',
    ejemplo: 'tu-usuario',
    icono: 'lucide',
  },
  {
    clave: 'cafecito',
    nombre: 'Cafecito',
    base: 'https://cafecito.app/',
    ejemplo: 'tu-usuario',
  },
  {
    clave: 'email',
    nombre: 'Email',
    base: 'mailto:',
    ejemplo: 'hola@tu-dominio.com',
    icono: 'lucide',
  },
  {
    clave: 'web',
    nombre: 'Sitio web',
    base: 'https://',
    ejemplo: 'tu-dominio.com',
    icono: 'lucide',
  },
];

const PORCLAVE = REDES.reduce((acc, red) => ({ ...acc, [red.clave]: red }), {});

export function buscarRed(clave) {
  return PORCLAVE[clave] || null;
}

/**
 * Convierte lo que escribió el usuario en una URL utilizable.
 *
 * Se acepta tanto el usuario suelto ("mi-banda") como la URL completa, porque
 * la mitad de la gente copia y pega y la otra mitad escribe el usuario.
 */
export function normalizarUrl(clave, valor) {
  const limpio = (valor || '').trim();

  if (limpio === '') {
    return '';
  }

  // Ya es una URL o un mailto: se respeta tal cual.
  if (/^(https?:\/\/|mailto:)/i.test(limpio)) {
    return limpio;
  }

  const red = buscarRed(clave);

  if (!red) {
    return limpio;
  }

  if (clave === 'email') {
    return `mailto:${limpio}`;
  }

  if (clave === 'whatsapp') {
    // Se dejan sólo los dígitos: la gente escribe +54 9 11 2233-4455.
    return `${red.base}${limpio.replace(/\D/g, '')}`;
  }

  // El usuario puede venir con @ adelante y la base ya lo incluye.
  const sinArroba = red.base.endsWith('@') ? limpio.replace(/^@/, '') : limpio;

  return `${red.base}${sinArroba}`;
}

/** Texto que se muestra en el editor: lo que el usuario escribió, sin la base. */
export function valorVisible(clave, url) {
  if (!url) {
    return '';
  }

  const red = buscarRed(clave);

  if (!red || !red.base) {
    return url;
  }

  return url.startsWith(red.base) ? url.slice(red.base.length) : url;
}
