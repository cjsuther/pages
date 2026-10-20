import React from 'react';
import { FileText, Loader2 } from 'lucide-react';
import { describirPeso } from '../utils/imagenes';
import { AYUDA_ARCHIVO } from '../utils/productos';

/**
 * El archivo de un producto digital: el que está cargado y el campo para
 * elegir otro.
 *
 * Con 50 MB la subida puede tardar minutos, así que mientras sube se dice, y
 * el campo se apaga: elegir otro a mitad de camino no cancela el primero.
 *
 * @param archivo   { nombre, bytes } del archivo que ya tiene, o null
 * @param subiendo  si hay una subida en curso
 * @param onElegir  recibe el File elegido
 */
function ArchivoDeProducto({ archivo, subiendo = false, onElegir, error = '' }) {
  const elegir = (e) => {
    const file = e.target.files && e.target.files[0];
    // Se vacía para que volver a elegir el mismo archivo también avise.
    e.target.value = '';
    if (file) onElegir(file);
  };

  return (
    <div>
      <label htmlFor="archivo-producto" className="block text-sm font-semibold text-tinta mb-1.5">
        {archivo ? 'Reemplazar archivo' : 'Archivo'}
      </label>

      {archivo && !subiendo && (
        <div className="flex items-center gap-3 mb-3 p-3 rounded-xl border border-borde bg-papel-hueso">
          <FileText className="w-5 h-5 shrink-0 text-tinta-media" />
          <div className="min-w-0">
            <p className="text-sm font-medium text-tinta truncate">{archivo.nombre}</p>
            {archivo.bytes > 0 && (
              <p className="text-xs text-tinta-suave">{describirPeso(Number(archivo.bytes))}</p>
            )}
          </div>
        </div>
      )}

      {subiendo && (
        <p role="status" className="flex items-center gap-2 mb-3 text-sm text-tinta-media">
          <Loader2 className="w-4 h-4 animate-spin" />
          Subiendo el archivo… Con archivos grandes puede tardar unos minutos.
        </p>
      )}

      <input
        id="archivo-producto"
        type="file"
        onChange={elegir}
        disabled={subiendo}
        className="text-sm text-tinta-media max-w-full min-w-0"
      />
      <p className="text-xs text-tinta-suave mt-1">{AYUDA_ARCHIVO}</p>
      {error && <p className="text-sm text-red-700 mt-2">{error}</p>}
    </div>
  );
}

export default ArchivoDeProducto;
