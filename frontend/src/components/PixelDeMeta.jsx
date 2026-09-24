import React, { useState } from 'react';
import { Check, Loader2 } from 'lucide-react';
import { esPixelValido } from '../utils/metaPixel';

/**
 * El pixel de Meta de una página, en su administración.
 *
 * Va acá, con las métricas, y no en Configuración: es medición. La diferencia
 * con los números de arriba es de quién son. Los de arriba los mide Rezonar y
 * sólo se ven acá; el pixel es la cuenta de Meta de quien tiene la página, y
 * es lo que le permite anunciar sus shows y saber qué aviso vendió entradas.
 */
function PixelDeMeta({ pixelInicial, onGuardar }) {
  const [pixel, setPixel] = useState(pixelInicial || '');
  const [guardando, setGuardando] = useState(false);
  const [guardado, setGuardado] = useState(false);
  const [error, setError] = useState(null);

  const cambiar = (valor) => {
    // Se pega el fragmento de código entero más veces de las que uno
    // esperaría: si adentro hay un número de pixel, se toma ése.
    const numeros = valor.match(/[0-9]{10,20}/);
    setPixel(valor.trim().length > 20 && numeros ? numeros[0] : valor);
    setGuardado(false);
    setError(null);
  };

  const guardar = async () => {
    const valor = pixel.trim();

    if (valor !== '' && !esPixelValido(valor)) {
      setError('El pixel es un número de 15 o 16 dígitos. Copialo del administrador de eventos de Meta.');
      return;
    }

    setGuardando(true);
    setError(null);

    try {
      await onGuardar(valor);
      setGuardado(true);
    } catch (e) {
      setError(e.message || 'No pudimos guardar el pixel');
    } finally {
      setGuardando(false);
    }
  };

  return (
    <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
      <h2 className="text-lg font-bold text-tinta mb-1">Pixel de Meta</h2>
      <p className="text-sm text-tinta-media mb-5">
        Si anunciás en Instagram o Facebook, tu pixel mide las visitas a esta página, a
        cada uno de sus eventos y las entradas que se venden acá. Los datos van a tu
        cuenta de Meta, no a la nuestra.
      </p>

      <label htmlFor="meta-pixel" className="block text-sm font-semibold text-tinta mb-1.5">
        ID del pixel
      </label>
      <div className="flex flex-wrap gap-3">
        <input
          id="meta-pixel"
          type="text"
          inputMode="numeric"
          value={pixel}
          onChange={(e) => cambiar(e.target.value)}
          placeholder="1234567890123456"
          className="flex-1 min-w-[16rem] px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
        />
        <button
          type="button"
          onClick={guardar}
          disabled={guardando}
          className="inline-flex items-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-50"
        >
          {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
          {guardando ? 'Guardando...' : 'Guardar'}
        </button>
      </div>

      <p className="text-xs text-tinta-suave mt-2">
        Está en el administrador de eventos de Meta, arriba del todo. Son sólo números.
        Vaciá el campo para dejar de medir.
      </p>

      {error && (
        <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3 mt-4">
          {error}
        </p>
      )}

      {guardado && !guardando && !error && (
        <p className="flex items-center gap-2 text-verde-oscuro text-sm font-medium mt-4">
          <Check className="w-4 h-4" />
          {pixel.trim() === '' ? 'Se dejó de medir con Meta' : 'Guardado'}
        </p>
      )}
    </div>
  );
}

export default PixelDeMeta;
