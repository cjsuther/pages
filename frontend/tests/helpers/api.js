import { vi } from 'vitest';

/**
 * Declara las respuestas que devolverá fetch, asociadas a un fragmento de URL.
 *
 *   mockFetch({
 *     'public/recent-pages.php': { pages: [] },
 *     'auth/login.php': { status: 401, body: { error: 'Invalid credentials' } },
 *   });
 *
 * Cualquier petición a una URL no declarada falla con un mensaje explícito, de
 * modo que un test nunca pase por accidente contra una respuesta vacía.
 */
export function mockFetch(rutas = {}) {
  const llamadas = [];

  global.fetch = vi.fn((url, opciones = {}) => {
    const urlTexto = typeof url === 'string' ? url : url.toString();
    llamadas.push({ url: urlTexto, options: opciones });

    const clave = Object.keys(rutas).find((fragmento) => urlTexto.includes(fragmento));

    if (!clave) {
      return Promise.reject(
        new Error(`fetch no declarado para ${urlTexto}. Agregalo a mockFetch().`)
      );
    }

    const definicion = rutas[clave];
    const esRespuestaDetallada =
      definicion && typeof definicion === 'object' && ('status' in definicion || 'body' in definicion);

    const status = esRespuestaDetallada && definicion.status ? definicion.status : 200;
    const body = esRespuestaDetallada ? definicion.body : definicion;

    return Promise.resolve(respuesta(status, body));
  });

  return { llamadas, fetch: global.fetch };
}

/** Construye un objeto Response mínimo con lo que usa la app. */
export function respuesta(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
    headers: new Map(),
  };
}

/** Devuelve el cuerpo JSON enviado en una llamada registrada. */
export function cuerpoDe(llamada) {
  if (!llamada || !llamada.options || !llamada.options.body) {
    return null;
  }
  return JSON.parse(llamada.options.body);
}

/** Busca la primera llamada cuya URL contenga el fragmento. */
export function llamadaA(llamadas, fragmento) {
  return llamadas.find((l) => l.url.includes(fragmento)) || null;
}

/** Cabecera Authorization de una llamada. */
export function tokenDe(llamada) {
  if (!llamada || !llamada.options || !llamada.options.headers) {
    return null;
  }
  return llamada.options.headers.Authorization || llamada.options.headers.authorization || null;
}
