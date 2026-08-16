/**
 * Armado de links a WhatsApp desde un teléfono escrito a mano.
 *
 * El comprador escribe el teléfono como se le ocurre: "11 2233-4455",
 * "(011) 15 2233 4455", "+54 9 11 2233 4455". wa.me sólo acepta el número
 * internacional completo y en dígitos, así que hay que reconstruirlo.
 *
 * Las reglas argentinas que importan:
 *   - el 0 de larga distancia no va
 *   - el 15 de celular tampoco: se reemplaza por el 9 después del país
 *   - los móviles necesitan ese 9 entre el 54 y la característica
 */

/** Largo del número nacional argentino sin el 0 ni el 15: característica + abonado. */
const LARGO_NACIONAL = 10;

/** Largos posibles de característica en Argentina: 11, 351, 2262… */
const LARGOS_DE_CARACTERISTICA = [2, 3, 4];

/**
 * Saca el 15 que la gente escribe después de la característica.
 *
 * No se puede buscar "15" en cualquier lado porque puede ser parte del número;
 * sólo cuenta si está justo después de la característica y si al sacarlo el
 * número queda con el largo que corresponde.
 */
function sinElQuince(digitos) {
  if (digitos.length !== LARGO_NACIONAL + 2) {
    return digitos;
  }

  for (const largo of LARGOS_DE_CARACTERISTICA) {
    if (digitos.slice(largo, largo + 2) === '15') {
      return digitos.slice(0, largo) + digitos.slice(largo + 2);
    }
  }

  return digitos;
}

/**
 * Número listo para wa.me, o null si no se puede armar uno confiable.
 *
 * @param {string} telefono Tal como lo escribió la persona
 * @returns {string|null}
 */
export function aWhatsApp(telefono) {
  const texto = String(telefono == null ? '' : telefono).trim();
  let digitos = texto.replace(/\D/g, '');

  if (digitos.length < 6) {
    return null;
  }

  // El 00 es el prefijo de salida internacional; equivale a haber escrito +.
  if (digitos.startsWith('00')) {
    digitos = digitos.slice(2);
    return conCodigoDePais(digitos);
  }

  // Con + al principio la persona ya dijo el país: no se asume Argentina, que
  // convertiría un número de otro país en uno argentino inexistente.
  if (texto.startsWith('+')) {
    return conCodigoDePais(digitos);
  }

  if (digitos.startsWith('54')) {
    return conCodigoDePais(digitos);
  }

  // Sin país: se asume Argentina, que es de donde es el público.
  const nacional = sinElQuince(digitos.replace(/^0/, ''));

  return nacional.length === LARGO_NACIONAL ? `549${nacional}` : null;
}

/** Un número que ya trae país: sólo se le acomoda el 9 si es argentino. */
function conCodigoDePais(digitos) {
  if (!digitos.startsWith('54')) {
    return digitos.length >= 8 ? digitos : null;
  }

  let resto = digitos.slice(2).replace(/^9/, '');
  resto = sinElQuince(resto);

  return resto.length === LARGO_NACIONAL ? `549${resto}` : null;
}

/** URL de WhatsApp, o null si el teléfono no da para armar una. */
export function urlDeWhatsApp(telefono) {
  const numero = aWhatsApp(telefono);

  return numero ? `https://wa.me/${numero}` : null;
}
