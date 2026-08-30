import React, { useState, useEffect } from 'react';
import { Loader2, AlertTriangle, Check } from 'lucide-react';
import { formatearPrecio, esGratis } from '../utils/entradas';
import { MP_PORCENTAJE, MP_DIAS_ACREDITACION, formatearPorcentaje } from '../utils/comisiones';

/**
 * Configuración de venta de entradas de un evento, dentro del modal de edición.
 *
 * Un precio de 0 es una reserva sin cobro y no necesita Mercado Pago.
 */
function PanelEntradas({ linkId, apiUrl, token, onCambio }) {
  const [config, setConfig] = useState(null);
  const [cobros, setCobros] = useState(null);
  const [ocupadas, setOcupadas] = useState(0);
  const [comision, setComision] = useState(0);
  const [cargando, setCargando] = useState(true);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState(null);
  const [guardado, setGuardado] = useState(false);
  const [form, setForm] = useState({ activo: false, capacidad: 100, precio: 0, max_por_compra: 10 });

  const cabeceras = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  };

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/entradas/evento.php?link_id=${linkId}`, { headers: cabeceras });
        const cuerpo = await r.json();

        if (!vigente || !r.ok) return;

        setCobros(cuerpo.cobros);
        setOcupadas(cuerpo.ocupadas || 0);
        setComision(cuerpo.comision || 0);
        setConfig(cuerpo.entradas);

        if (cuerpo.entradas) {
          setForm({
            activo: !!Number(cuerpo.entradas.activo),
            capacidad: Number(cuerpo.entradas.capacidad),
            precio: Number(cuerpo.entradas.precio),
            max_por_compra: Number(cuerpo.entradas.max_por_compra),
          });
        }
      } catch (e) {
        if (vigente) setError('No pudimos cargar la configuración');
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [linkId]);

  const cambiar = (campo, valor) => {
    setForm((previo) => ({ ...previo, [campo]: valor }));
    setGuardado(false);
    setError(null);
  };

  const guardar = async () => {
    setGuardando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/evento.php?link_id=${linkId}`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify(form),
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No se pudo guardar');
        return;
      }

      setConfig(cuerpo.entradas);
      setGuardado(true);
      if (onCambio) onCambio(cuerpo.entradas);
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  if (cargando) {
    return <p className="text-gray-500 py-6">Cargando...</p>;
  }

  const sinMercadoPago = !cobros || !cobros.configurado;
  const quiereCobrar = !esGratis(form.precio);
  const disponibles = Math.max(0, Number(form.capacidad) - ocupadas);

  return (
    <div className="space-y-6">
      <label className="flex items-start gap-3 cursor-pointer">
        <input
          type="checkbox"
          checked={form.activo}
          onChange={(e) => cambiar('activo', e.target.checked)}
          className="mt-1"
        />
        <span>
          <span className="block text-white font-bold">Vender entradas para este evento</span>
          <span className="block text-sm text-gray-500">
            En el detalle del evento se muestra el botón de compra en lugar del link.
          </span>
        </span>
      </label>

      {form.activo && (
        <>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="entradas-capacidad" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
                CAPACIDAD MÁXIMA
              </label>
              <input
                id="entradas-capacidad"
                type="number"
                min="1"
                value={form.capacidad}
                onChange={(e) => cambiar('capacidad', Number(e.target.value))}
                className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
              />
            </div>

            <div>
              <label htmlFor="entradas-precio" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
                PRECIO POR ENTRADA
              </label>
              <input
                id="entradas-precio"
                type="number"
                min="0"
                step="0.01"
                value={form.precio}
                onChange={(e) => cambiar('precio', Number(e.target.value))}
                className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
              />
              <p className="text-xs text-gray-600 mt-1">
                {esGratis(form.precio)
                  ? 'En 0 es una reserva sin costo'
                  : formatearPrecio(form.precio)}
              </p>

              {/* Lo que importa a la hora de poner el precio no es lo que paga
                  el comprador sino lo que termina entrando a la cuenta. */}
              {!esGratis(form.precio) && comision > 0 && cobros && cobros.admite_split && (
                <>
                  <p className="text-xs text-emerald-400 mt-1">
                    Menos la comisión de Rezonar ({formatearPorcentaje(comision)}%):{' '}
                    {formatearPrecio(form.precio * (100 - comision) / 100)} por entrada
                  </p>
                  {/* El porcentaje y el plazo van juntos: en Mercado Pago uno
                      depende del otro, y a la hora de poner un precio lo que
                      importa es cuánto entra y cuándo. */}
                  <p className="text-xs text-gray-600 mt-1">
                    A eso Mercado Pago le descuenta aparte {formatearPorcentaje(MP_PORCENTAJE)}%
                    por procesar el pago, y libera la plata a los {MP_DIAS_ACREDITACION} días
                    de la compra.
                  </p>
                </>
              )}
            </div>
          </div>

          <div>
            <label htmlFor="entradas-max" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
              MÁXIMO POR COMPRA
            </label>
            <input
              id="entradas-max"
              type="number"
              min="1"
              max="50"
              value={form.max_por_compra}
              onChange={(e) => cambiar('max_por_compra', Number(e.target.value))}
              className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
            />
            <p className="text-xs text-gray-600 mt-1">
              Para que una sola persona no se lleve todo el cupo.
            </p>
          </div>

          {ocupadas > 0 && (
            <div className="border border-gray-800 bg-black p-4 text-sm">
              <p className="text-gray-400">
                Ya hay <strong className="text-white">{ocupadas}</strong> entradas tomadas
                (vendidas o reservándose ahora).
              </p>
              <p className="text-gray-600 mt-1">
                Quedan {disponibles} disponibles. No podés bajar la capacidad por debajo de {ocupadas}.
              </p>
            </div>
          )}

          {quiereCobrar && sinMercadoPago && (
            <p className="flex items-start gap-2 text-amber-400 text-sm bg-amber-950 border border-amber-900 px-4 py-3">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              Para cobrar tenés que conectar Mercado Pago en la sección Entradas de la
              página. Con precio en 0 podés ofrecer reservas sin costo igual.
            </p>
          )}

          {quiereCobrar && cobros && cobros.modo === 'prueba' && (
            <p className="flex items-start gap-2 text-amber-400 text-sm">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              Mercado Pago está con credenciales de prueba: los pagos no son reales.
            </p>
          )}
        </>
      )}

      {error && (
        <p className="text-sm text-red-400 bg-red-950 border border-red-900 px-4 py-3">{error}</p>
      )}

      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={guardar}
          disabled={guardando}
          className="bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition disabled:opacity-50 flex items-center gap-2"
        >
          {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
          {guardando ? 'GUARDANDO...' : 'GUARDAR ENTRADAS'}
        </button>

        {guardado && !guardando && (
          <span className="flex items-center gap-2 text-emerald-400 text-sm font-medium">
            <Check className="w-4 h-4" />
            Guardado
          </span>
        )}
      </div>
    </div>
  );
}

export default PanelEntradas;
