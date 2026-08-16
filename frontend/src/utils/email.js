/**
 * Validación de email para avisar antes de mandar el formulario.
 *
 * El `type="email"` del navegador es más flojo de lo que uno espera: acepta
 * `ana@localhost` o `asd@asd`, porque el estándar no exige punto ni extensión.
 * En una compra de entradas eso importa más que en otros lados: la
 * confirmación va por mail, así que un email que "pasa" pero no existe deja a
 * la persona sin su entrada y sin forma de reclamarla.
 *
 * La palabra final la tiene el servidor. Esto es para avisar a tiempo.
 */
export function esEmailValido(email) {
  const limpio = String(email == null ? '' : email).trim();

  if (limpio === '' || limpio.length > 254) {
    return false;
  }

  // Algo, una sola arroba, un dominio con al menos un punto y una extensión
  // de dos letras o más.
  return /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.[a-zA-Z]{2,}$/.test(limpio);
}

/** Dominios que la gente escribe mal seguido, con su corrección. */
const CONFUSIONES = {
  'gmail.co': 'gmail.com',
  'gmail.cm': 'gmail.com',
  'gmial.com': 'gmail.com',
  'gmai.com': 'gmail.com',
  'gmail.con': 'gmail.com',
  'hotmail.co': 'hotmail.com',
  'hotmial.com': 'hotmail.com',
  'hotmail.con': 'hotmail.com',
  'outlook.co': 'outlook.com',
  'yahoo.co': 'yahoo.com',
};

/**
 * Sugerencia para un email con forma válida pero dominio sospechoso.
 *
 * `ana@gmail.co` pasa cualquier validación y no llega nunca. No se corrige
 * solo: se pregunta, porque .co es un dominio real y puede ser lo que quiso.
 *
 * @returns {string|null} el email corregido, o null si no hay nada que sugerir
 */
export function sugerenciaDeEmail(email) {
  const limpio = String(email == null ? '' : email).trim().toLowerCase();
  const arroba = limpio.lastIndexOf('@');

  if (arroba < 1) {
    return null;
  }

  const dominio = limpio.slice(arroba + 1);
  const correcto = CONFUSIONES[dominio];

  return correcto ? `${limpio.slice(0, arroba)}@${correcto}` : null;
}
