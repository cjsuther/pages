/**
 * El plano de un evento con lugares asignados: filas de butacas y mesas.
 *
 * El plano se mide en casilleros: una butaca ocupa uno. Las posiciones de los
 * elementos son enteras, así todo queda alineado sin que haya que acomodarlo
 * a ojo, y el servidor sólo tiene que validar números chicos.
 *
 * Cada lugar tiene un identificador que se entiende sin el plano —"f:A:7" es
 * fila A, butaca 7; "m:3:2" es mesa 3, lugar 2— y es el mismo que usa la API.
 */

export const ANCHO_MAXIMO = 80;
export const ALTO_MAXIMO = 80;
export const MAX_BUTACAS_POR_FILA = 60;
export const MAX_LUGARES_POR_MESA = 16;

/** Lo mismo que acepta el servidor: corto y sin separadores. */
export const PATRON_NOMBRE = /^[A-Za-z0-9]{1,6}$/;

/** Radio de una butaca, en casilleros. */
export const RADIO_BUTACA = 0.36;

/** Lado del cuadrado que ocupa una mesa: crece con los lugares para que no se pisen. */
export function ladoDeMesa(lugares) {
  if (lugares <= 6) return 3;
  if (lugares <= 10) return 4;
  return 5;
}

/** El rectángulo que ocupa un elemento en el plano. */
export function huella(elemento) {
  if (elemento.tipo === 'fila') {
    // Un casillero para el nombre de la fila y uno por butaca.
    return { x: elemento.x, y: elemento.y, ancho: elemento.butacas + 1, alto: 1 };
  }

  if (elemento.tipo === 'mesa') {
    const lado = ladoDeMesa(elemento.lugares);
    return { x: elemento.x, y: elemento.y, ancho: lado, alto: lado };
  }

  return { x: elemento.x, y: elemento.y, ancho: elemento.ancho, alto: elemento.alto };
}

/**
 * Los lugares de un elemento, con dónde dibujarlos.
 *
 * @returns {{id: string, numero: number, cx: number, cy: number}[]}
 */
export function lugaresDelElemento(elemento) {
  if (elemento.tipo === 'fila') {
    return Array.from({ length: elemento.butacas }, (_, i) => {
      const numero = elemento.desde + i;
      return {
        id: `f:${elemento.nombre}:${numero}`,
        numero,
        cx: elemento.x + 1 + i + 0.5,
        cy: elemento.y + 0.5,
      };
    });
  }

  if (elemento.tipo === 'mesa') {
    const lado = ladoDeMesa(elemento.lugares);
    const centro = { x: elemento.x + lado / 2, y: elemento.y + lado / 2 };
    const radio = lado / 2 - 0.45;

    // Arrancan arriba y siguen como las agujas del reloj: el lugar 1 de todas
    // las mesas queda en el mismo lugar, y se encuentra sin buscarlo.
    return Array.from({ length: elemento.lugares }, (_, i) => {
      const angulo = -Math.PI / 2 + (2 * Math.PI * i) / elemento.lugares;
      return {
        id: `m:${elemento.nombre}:${i + 1}`,
        numero: i + 1,
        cx: centro.x + radio * Math.cos(angulo),
        cy: centro.y + radio * Math.sin(angulo),
      };
    });
  }

  return [];
}

/** Todos los identificadores de lugar del plano, en orden. */
export function lugaresDelPlano(plano) {
  if (!plano || !Array.isArray(plano.elementos)) {
    return [];
  }

  return plano.elementos.flatMap((e) => lugaresDelElemento(e).map((l) => l.id));
}

/** "Fila A, butaca 7" o "Mesa 3, lugar 2". */
export function describirLugar(lugar) {
  const partes = String(lugar).split(':');

  if (partes.length !== 3) return String(lugar);

  const [tipo, nombre, numero] = partes;

  if (tipo === 'f') return `Fila ${nombre}, butaca ${numero}`;
  if (tipo === 'm') return `Mesa ${nombre}, lugar ${numero}`;

  return String(lugar);
}

/** Varios lugares en una línea, agrupados: "Fila A: 7, 8 · Mesa 3: 1". */
export function resumirLugares(lugares) {
  const grupos = new Map();

  (lugares || []).forEach((lugar) => {
    const partes = String(lugar).split(':');

    if (partes.length !== 3) {
      grupos.set(String(lugar), []);
      return;
    }

    const titulo = `${partes[0] === 'm' ? 'Mesa' : 'Fila'} ${partes[1]}`;
    if (!grupos.has(titulo)) grupos.set(titulo, []);
    grupos.get(titulo).push(Number(partes[2]));
  });

  return Array.from(grupos.entries())
    .map(([titulo, numeros]) => (
      numeros.length === 0
        ? titulo
        : `${titulo}: ${[...numeros].sort((a, b) => a - b).join(', ')}`
    ))
    .join(' · ');
}

/** Lugares que aparecen más de una vez en el plano. */
export function lugaresRepetidos(plano) {
  const vistos = new Set();
  const repetidos = new Set();

  lugaresDelPlano(plano).forEach((id) => {
    if (vistos.has(id)) repetidos.add(id);
    vistos.add(id);
  });

  return Array.from(repetidos);
}

/** La letra que sigue: A → B, Z → AA. */
export function siguienteLetra(letra) {
  if (!letra) return 'A';

  const chars = letra.toUpperCase().split('');
  let i = chars.length - 1;

  while (i >= 0) {
    if (chars[i] !== 'Z') {
      chars[i] = String.fromCharCode(chars[i].charCodeAt(0) + 1);
      return chars.join('');
    }
    chars[i] = 'A';
    i -= 1;
  }

  return `A${chars.join('')}`;
}

/** Un nombre que todavía no usa ningún elemento de ese tipo. */
function nombreLibre(plano, tipo) {
  const usados = new Set(plano.elementos.filter((e) => e.tipo === tipo).map((e) => e.nombre));

  if (tipo === 'mesa') {
    let n = 1;
    while (usados.has(String(n))) n += 1;
    return String(n);
  }

  let letra = 'A';
  while (usados.has(letra)) letra = siguienteLetra(letra);
  return letra;
}

/** Mueve un elemento lo justo para que entre entero en el plano. */
export function dentroDelPlano(elemento, plano) {
  const h = huella(elemento);

  return {
    ...elemento,
    x: Math.max(0, Math.min(Math.round(elemento.x), plano.ancho - h.ancho)),
    y: Math.max(0, Math.min(Math.round(elemento.y), plano.alto - h.alto)),
  };
}

/**
 * Un elemento nuevo, con nombre libre, abajo de todo lo que ya hay.
 *
 * Se agrega abajo y no en el medio para no taparle nada a quien lo está
 * armando: aparece donde se ve, y desde ahí se arrastra.
 */
export function nuevoElemento(tipo, plano) {
  const abajo = plano.elementos.reduce((max, e) => {
    const h = huella(e);
    return Math.max(max, h.y + h.alto);
  }, 0);

  const base = { x: 1, y: abajo + (plano.elementos.length ? 1 : 0) };
  let elemento;

  if (tipo === 'fila') {
    elemento = { tipo, nombre: nombreLibre(plano, 'fila'), desde: 1, butacas: 10, ...base };
  } else if (tipo === 'mesa') {
    elemento = { tipo, nombre: nombreLibre(plano, 'mesa'), lugares: 4, forma: 'redonda', ...base };
  } else {
    elemento = { tipo: 'escenario', texto: 'Escenario', ancho: Math.min(10, plano.ancho), alto: 2, ...base };
  }

  return dentroDelPlano(elemento, plano);
}

/**
 * Una platea armada de una: escenario arriba y filas centradas debajo.
 *
 * Es el caso más común —un teatro, una sala— y hacerlo fila por fila es
 * tedioso. Después se retoca a mano lo que haga falta.
 */
export function armarPlatea({ filas, butacas }) {
  const cantidadFilas = Math.max(1, Math.min(Number(filas) || 1, 26));
  const porFila = Math.max(1, Math.min(Number(butacas) || 1, MAX_BUTACAS_POR_FILA));

  const ancho = Math.min(ANCHO_MAXIMO, Math.max(12, porFila + 3));
  const alto = Math.min(ALTO_MAXIMO, cantidadFilas + 5);
  const xFila = Math.floor((ancho - (porFila + 1)) / 2);
  const anchoEscenario = Math.min(ancho - 2, Math.max(6, Math.round(porFila * 0.7)));

  const elementos = [{
    tipo: 'escenario',
    texto: 'Escenario',
    ancho: anchoEscenario,
    alto: 2,
    x: Math.floor((ancho - anchoEscenario) / 2),
    y: 0,
  }];

  let letra = 'A';

  for (let i = 0; i < cantidadFilas; i += 1) {
    elementos.push({ tipo: 'fila', nombre: letra, desde: 1, butacas: porFila, x: xFila, y: 3 + i });
    letra = siguienteLetra(letra);
  }

  return { ancho, alto, elementos };
}

/** El plano con el que arranca quien elige "con lugares asignados". */
export function planoInicial() {
  return armarPlatea({ filas: 5, butacas: 10 });
}

/** Si el plano cambia de tamaño, todo lo que tiene se acomoda para seguir adentro. */
export function redimensionar(plano, ancho, alto) {
  const nuevo = {
    ...plano,
    ancho: Math.max(4, Math.min(Number(ancho) || 4, ANCHO_MAXIMO)),
    alto: Math.max(4, Math.min(Number(alto) || 4, ALTO_MAXIMO)),
  };

  return { ...nuevo, elementos: plano.elementos.map((e) => dentroDelPlano(e, nuevo)) };
}

/**
 * Qué se elige al tocar una mesa: todos sus lugares libres, o ninguno si ya
 * estaban todos elegidos. Respeta el máximo por compra.
 */
export function alternarMesa(elemento, elegidos, ocupados, maximo) {
  const libres = lugaresDelElemento(elemento)
    .map((l) => l.id)
    .filter((id) => !ocupados.includes(id));

  if (libres.length > 0 && libres.every((id) => elegidos.includes(id))) {
    return elegidos.filter((id) => !libres.includes(id));
  }

  const resultado = [...elegidos];

  libres.forEach((id) => {
    if (!resultado.includes(id) && resultado.length < maximo) {
      resultado.push(id);
    }
  });

  return resultado;
}
