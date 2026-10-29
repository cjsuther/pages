import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/**
 * Safari anterior a 16.4 no entiende el lookbehind en una regex: el literal da
 * SyntaxError al cargar el módulo y, como va todo en el mismo bundle, no carga
 * el sitio entero. Node sí lo entiende, así que el resto de los tests no lo ve.
 */
const SRC = join(__dirname, '..', '..', 'src');

function archivos(dir) {
  return readdirSync(dir).flatMap((nombre) => {
    const ruta = join(dir, nombre);
    if (statSync(ruta).isDirectory()) return archivos(ruta);
    return /\.(js|jsx)$/.test(nombre) ? [ruta] : [];
  });
}

describe('compatibilidad con Safari viejo', () => {
  it('ninguna regex del frontend usa lookbehind', () => {
    const conLookbehind = archivos(SRC)
      .filter((ruta) => /\(\?<[!=]/.test(readFileSync(ruta, 'utf-8')))
      .map((ruta) => relative(SRC, ruta));

    expect(conLookbehind).toEqual([]);
  });
});
