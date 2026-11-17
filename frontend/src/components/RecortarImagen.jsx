import React, { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Cropper from 'react-easy-crop';
import { Loader2, ZoomIn, ZoomOut } from 'lucide-react';
import { Boton } from './ui';
import { useBloqueoDeScroll } from '../hooks/useBloqueoDeScroll';
import {
  ORIGINAL,
  areaCompleta,
  cargarImagen,
  exportarRecorte,
  sirveTalCual,
} from '../utils/recorte';

const ZOOM_MIN = 1;
const ZOOM_MAX = 4;
const PASO_ZOOM = 0.2;

const ENFOCABLES = 'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Diálogo para recortar una imagen antes de subirla.
 *
 * Mover con el mouse, el dedo o las flechas; zoom con la rueda, pellizcando,
 * con el control deslizante o con + y −. Lo táctil y la rueda los resuelve
 * react-easy-crop; acá se decide la proporción, el tamaño de salida y el
 * formato (src/utils/recorte.js).
 *
 * @param archivo     el File elegido
 * @param destino     una entrada de DESTINOS
 * @param onListo     recibe el File a subir
 * @param onCancelar  se cierra sin subir nada
 */
function RecortarImagen({ archivo, destino, onListo, onCancelar }) {
  const idTitulo = useId();
  const idAyuda = useId();
  const caja = useRef(null);

  const [url, setUrl] = useState(null);
  const [imagen, setImagen] = useState(null);
  const [proporcion, setProporcion] = useState(destino.proporciones[0].valor);
  const [crop, setCrop] = useState({ x: 0, y: 0 });
  const [zoom, setZoom] = useState(1);
  const [area, setArea] = useState(null);
  const [procesando, setProcesando] = useState(false);
  const [error, setError] = useState(null);

  useBloqueoDeScroll(true);

  // Una URL local para mostrar el archivo sin subirlo; se libera al cerrar.
  useEffect(() => {
    const local = URL.createObjectURL(archivo);
    setUrl(local);
    let vigente = true;

    cargarImagen(local)
      .then((cargada) => vigente && setImagen(cargada))
      .catch(() => vigente && setError('No pudimos abrir esta imagen. Probá con un JPG o un PNG.'));

    return () => {
      vigente = false;
      URL.revokeObjectURL(local);
    };
  }, [archivo]);

  // El foco entra al diálogo y vuelve a donde estaba al cerrarlo.
  useEffect(() => {
    const anterior = document.activeElement;
    caja.current?.focus();
    return () => {
      if (anterior && typeof anterior.focus === 'function') anterior.focus();
    };
  }, []);

  const aspecto = proporcion === ORIGINAL
    ? (imagen ? imagen.naturalWidth / imagen.naturalHeight : 1)
    : proporcion;

  const alCompletar = useCallback((_porcentajes, pixeles) => setArea(pixeles), []);

  const cambiarZoom = (valor) => setZoom(Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, valor)));

  const exportar = async (zona) => {
    setProcesando(true);
    setError(null);
    try {
      const resultado = await exportarRecorte(imagen, zona, {
        maxLado: destino.maxLado,
        maxBytes: destino.maxBytes,
        tipo: archivo.type,
        nombre: archivo.name,
      });
      onListo(resultado);
    } catch (e) {
      setError(e.message || 'No pudimos procesar la imagen');
      setProcesando(false);
    }
  };

  const usarRecorte = () => {
    if (imagen && area) exportar(area);
  };

  const usarSinRecortar = () => {
    // El original, si ya entra: así un GIF animado sigue animado.
    if (sirveTalCual(archivo, destino)) {
      onListo(archivo);
      return;
    }
    if (imagen) exportar(areaCompleta(imagen));
  };

  const alTeclear = (e) => {
    if (e.key === 'Escape') {
      e.stopPropagation();
      if (!procesando) onCancelar();
      return;
    }

    // Zoom con el teclado desde cualquier parte del diálogo menos el
    // deslizante, que ya usa sus propias teclas.
    if ((e.key === '+' || e.key === '=') && e.target.type !== 'range') {
      e.preventDefault();
      cambiarZoom(zoom + PASO_ZOOM);
      return;
    }
    if ((e.key === '-' || e.key === '_') && e.target.type !== 'range') {
      e.preventDefault();
      cambiarZoom(zoom - PASO_ZOOM);
      return;
    }

    // El Tab no se escapa del diálogo.
    if (e.key === 'Tab' && caja.current) {
      const enfocables = [...caja.current.querySelectorAll(ENFOCABLES)];
      if (enfocables.length === 0) return;
      const primero = enfocables[0];
      const ultimo = enfocables[enfocables.length - 1];
      if (e.shiftKey && (document.activeElement === primero || document.activeElement === caja.current)) {
        e.preventDefault();
        ultimo.focus();
      } else if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault();
        primero.focus();
      }
    }
  };

  const listo = !!imagen && !procesando;

  return createPortal(
    <div className="fixed inset-0 z-[60] bg-tinta/60 backdrop-blur-sm flex items-center justify-center p-0 sm:p-4">
      <div
        ref={caja}
        role="dialog"
        aria-modal="true"
        aria-labelledby={idTitulo}
        aria-describedby={idAyuda}
        tabIndex={-1}
        onKeyDown={alTeclear}
        className="bg-white w-full h-full sm:h-auto sm:max-w-xl sm:rounded-2xl sm:border sm:border-borde flex flex-col outline-none"
      >
        <div className="px-5 pt-5 pb-3">
          <h2 id={idTitulo} className="text-lg font-bold text-tinta">{destino.titulo}</h2>
          <p id={idAyuda} className="text-xs text-tinta-suave mt-1">
            Arrastrá para mover y usá la rueda o pellizcá para el zoom. Con el teclado: flechas para mover, + y − para acercar o alejar.
          </p>
        </div>

        <div className="relative flex-1 min-h-[300px] sm:h-[360px] sm:flex-none bg-tinta">
          {url && (
            <Cropper
              image={url}
              crop={crop}
              zoom={zoom}
              minZoom={ZOOM_MIN}
              maxZoom={ZOOM_MAX}
              aspect={aspecto}
              cropShape={destino.forma === 'round' ? 'round' : 'rect'}
              showGrid={destino.forma !== 'round'}
              onCropChange={setCrop}
              onZoomChange={cambiarZoom}
              onCropComplete={alCompletar}
              cropperProps={{
                role: 'group',
                'aria-label': 'Área de recorte. Usá las flechas para mover la imagen.',
              }}
              mediaProps={{ alt: '' }}
            />
          )}
          {!imagen && !error && (
            <div className="absolute inset-0 flex items-center justify-center text-white" role="status">
              <Loader2 className="w-6 h-6 animate-spin" aria-hidden="true" />
              <span className="sr-only">Abriendo la imagen…</span>
            </div>
          )}
        </div>

        <div className="px-5 py-4 space-y-4">
          {destino.proporciones.length > 1 && (
            <div role="group" aria-label="Proporción" className="flex flex-wrap gap-2">
              {destino.proporciones.map((p) => (
                <button
                  key={p.etiqueta}
                  type="button"
                  aria-pressed={proporcion === p.valor}
                  onClick={() => setProporcion(p.valor)}
                  className={`px-3 py-1 rounded-full text-sm border transition-colors ${
                    proporcion === p.valor
                      ? 'bg-tinta text-white border-tinta'
                      : 'bg-white text-tinta border-borde-fuerte hover:border-tinta'
                  }`}
                >
                  {p.etiqueta}
                </button>
              ))}
            </div>
          )}

          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => cambiarZoom(zoom - PASO_ZOOM)}
              disabled={zoom <= ZOOM_MIN}
              aria-label="Alejar"
              className="p-1.5 rounded-full text-tinta-media hover:text-tinta disabled:opacity-40"
            >
              <ZoomOut className="w-5 h-5" aria-hidden="true" />
            </button>
            <input
              type="range"
              min={ZOOM_MIN}
              max={ZOOM_MAX}
              step={0.01}
              value={zoom}
              onChange={(e) => cambiarZoom(Number(e.target.value))}
              aria-label="Zoom"
              aria-valuetext={`${Math.round(zoom * 100)}%`}
              className="flex-1 accent-verde-oscuro"
            />
            <button
              type="button"
              onClick={() => cambiarZoom(zoom + PASO_ZOOM)}
              disabled={zoom >= ZOOM_MAX}
              aria-label="Acercar"
              className="p-1.5 rounded-full text-tinta-media hover:text-tinta disabled:opacity-40"
            >
              <ZoomIn className="w-5 h-5" aria-hidden="true" />
            </button>
          </div>

          {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
            <Boton type="button" variante="fantasma" onClick={onCancelar} disabled={procesando}>
              Cancelar
            </Boton>
            {destino.sinRecortar !== false && (
              <Boton
                type="button"
                variante="secundario"
                onClick={usarSinRecortar}
                disabled={procesando || (!imagen && !sirveTalCual(archivo, destino))}
              >
                Usar sin recortar
              </Boton>
            )}
            <Boton type="button" onClick={usarRecorte} disabled={!listo || !area}>
              {procesando && <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />}
              Usar recorte
            </Boton>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}

export default RecortarImagen;
