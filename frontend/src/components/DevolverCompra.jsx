import React, { useEffect, useState } from 'react';
import { Minus, Plus } from 'lucide-react';
import { formatearPrecio } from '../utils/entradas';
import { personasDe } from '../utils/tiposDeEntrada';
import { describirLugar } from '../utils/plano';

/**
 * Devolverle plata a quien compró, desde la cuenta de Mercado Pago del
 * organizador.
 *
 * Se devuelve sólo lo que le llegó al organizador por esa compra (el neto,
 * sin las comisiones de Mercado Pago ni de Rezonar): la compra completa o la
 * parte de algunas entradas, que se sacan de la compra.
 *
 * Dos pasos, a propósito: primero se calcula (el servidor dice cuánto, sin
 * mover nada) y después se confirma ese monto. Si en el medio cambió, el
 * servidor no devuelve y muestra el nuevo. Mueve plata real y no se deshace.
 */
function DevolverCompra({ codigo, apiUrl, token, onCerrar, onDevuelta }) {
  const [datos, setDatos] = useState(null);
  const [intentos, setIntentos] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [modo, setModo] = useState('completa');
  const [cantidad, setCantidad] = useState(1);
  const [porTipo, setPorTipo] = useState({});
  const [lugares, setLugares] = useState([]);
  const [calculo, setCalculo] = useState(null);
  const [trabajando, setTrabajando] = useState(false);

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/entradas/devolucion.php?codigo=${encodeURIComponent(codigo)}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos cargar la compra');
          return;
        }

        setDatos(cuerpo);
        setIntentos(cuerpo.intentos || []);
      } catch (e) {
        if (vigente) setError('No pudimos conectarnos al servidor');
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => {
      vigente = false;
    };
  }, [apiUrl, token, codigo]);

  /** Cualquier cambio en lo elegido invalida el cálculo anterior. */
  const cambiar = (fn) => (...args) => {
    setCalculo(null);
    setError(null);
    fn(...args);
  };

  const pedido = () => {
    if (modo === 'completa') return { modo };

    const forma = datos.por_entrada.forma;

    if (forma === 'lugares') return { modo, lugares };
    if (forma === 'tipos') {
      return {
        modo,
        tipos: Object.fromEntries(
          datos.por_entrada.items.map((item) => [item.tipo, (Number(porTipo[item.tipo]) || 0) * personasDe(item)])
        ),
      };
    }

    return { modo, cantidad: Number(cantidad) };
  };

  const enviar = async (extra) => {
    setTrabajando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/devolucion.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo, ...pedido(), ...extra }),
      });
      const cuerpo = await r.json();

      if (Array.isArray(cuerpo.intentos)) setIntentos(cuerpo.intentos);

      return { r, cuerpo };
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
      return null;
    } finally {
      setTrabajando(false);
    }
  };

  const calcular = async () => {
    const respuesta = await enviar({ simular: true });
    if (!respuesta) return;

    const { r, cuerpo } = respuesta;

    if (!r.ok) {
      setError(cuerpo.error || 'No se puede devolver');
      return;
    }

    setCalculo(cuerpo);
  };

  const devolver = async () => {
    const respuesta = await enviar({ confirmacion: calculo.monto });
    if (!respuesta) return;

    const { r, cuerpo } = respuesta;

    if (!r.ok) {
      setError(cuerpo.error || 'No pudimos hacer la devolución');

      // El monto cambió en el medio: se muestra el nuevo para confirmarlo.
      if (typeof cuerpo.monto === 'number') {
        setCalculo((previo) => ({ ...previo, monto: cuerpo.monto }));
      } else {
        setCalculo(null);
      }
      return;
    }

    onDevuelta({ ...cuerpo, nombre: datos.orden.nombre });
  };

  const orden = datos && datos.orden;
  const desglose = datos && datos.desglose;
  const moneda = orden ? orden.moneda : 'ARS';
  const nadaElegido = modo === 'entradas' && datos && cantidadElegida(datos.por_entrada, cantidad, porTipo, lugares) === 0;

  return (
    <div className="fixed inset-0 bg-white bg-opacity-80 z-50 flex items-center justify-center p-4">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="devolver-compra-titulo"
        className="bg-white border border-borde-fuerte rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto"
      >
        <h4 id="devolver-compra-titulo" className="text-tinta font-bold text-lg mb-1">
          Devolver dinero
        </h4>

        {cargando && <p className="text-sm text-tinta-suave py-6">Cargando la compra...</p>}

        {orden && (
          <>
            <p className="text-sm text-tinta-media mb-4">
              {orden.nombre} · <span className="font-mono">{orden.codigo}</span> · {orden.cantidad}{' '}
              {orden.cantidad === 1 ? 'entrada' : 'entradas'}
            </p>

            <dl className="text-sm border border-borde divide-y divide-borde mb-4">
              <Renglon etiqueta="Pagó" valor={formatearPrecio(desglose.cobrado, moneda)} />
              {desglose.comision_mercadopago !== null && (
                <Renglon etiqueta="Comisión de Mercado Pago" valor={`− ${formatearPrecio(desglose.comision_mercadopago, moneda)}`} />
              )}
              <Renglon etiqueta="Comisión de Rezonar" valor={`− ${formatearPrecio(desglose.comision_plataforma, moneda)}`} />
              <Renglon
                etiqueta="Te llegó a Mercado Pago"
                valor={desglose.neto === null ? 'sin dato' : formatearPrecio(desglose.neto, moneda)}
              />
              {desglose.devuelto > 0 && (
                <Renglon etiqueta="Ya devuelto" valor={`− ${formatearPrecio(desglose.devuelto, moneda)}`} />
              )}
              <Renglon etiqueta="Se puede devolver" valor={formatearPrecio(desglose.devolvible, moneda)} fuerte />
            </dl>

            <p className="text-xs text-tinta-suave mb-4">
              Se devuelve sólo lo que te llegó por esta compra. Las comisiones de Mercado Pago y de Rezonar
              no se devuelven. Sale de tu cuenta de Mercado Pago como una devolución del pago.
            </p>

            {datos.aviso && (
              <p role="status" className="text-sm text-amber-800 bg-amber-50 border border-amber-200 px-3 py-2 mb-4">
                {datos.aviso}
              </p>
            )}

            <fieldset className="mb-4 space-y-2">
              <legend className="sr-only">Qué devolver</legend>
              <label className="flex items-center gap-2 text-sm text-tinta">
                <input
                  type="radio"
                  name="modo"
                  checked={modo === 'completa'}
                  onChange={cambiar(() => setModo('completa'))}
                />
                Toda la compra (se anula y el QR deja de servir)
              </label>
              {orden.cantidad > 1 && (
                <label className="flex items-center gap-2 text-sm text-tinta">
                  <input
                    type="radio"
                    name="modo"
                    checked={modo === 'entradas'}
                    onChange={cambiar(() => setModo('entradas'))}
                  />
                  Algunas entradas (la parte de lo que valen)
                </label>
              )}
            </fieldset>

            {modo === 'entradas' && (
              <EleccionDeEntradas
                porEntrada={datos.por_entrada}
                cantidad={cantidad}
                porTipo={porTipo}
                lugares={lugares}
                onCantidad={cambiar(setCantidad)}
                onPorTipo={cambiar(setPorTipo)}
                onLugares={cambiar(setLugares)}
              />
            )}

            {calculo && (
              <div className="border border-borde-fuerte bg-papel-hueso px-4 py-3 mb-4 text-sm text-tinta">
                <p className="font-bold">
                  Se le devuelven {formatearPrecio(calculo.monto, moneda)} a {orden.nombre}.
                </p>
                <p className="text-tinta-media mt-1">
                  {calculo.modo === 'completa'
                    ? 'La compra queda anulada y los lugares vuelven a estar disponibles.'
                    : 'Esas entradas se sacan de la compra; el resto sigue con el mismo QR.'}{' '}
                  No se puede deshacer.
                </p>
              </div>
            )}
          </>
        )}

        {error && (
          <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-3 py-2 mb-4">
            {error}
          </p>
        )}

        {intentos.length > 0 && (
          <div className="mb-4">
            <p className="text-xs font-bold text-tinta-suave tracking-wide mb-1">INTENTOS ANTERIORES</p>
            <ul className="text-xs space-y-1">
              {intentos.map((intento) => (
                <li key={intento.id} className="text-tinta-media">
                  {fechaYHora(intento.created_at)} · {ESTADOS[intento.estado] || intento.estado}
                  {intento.monto > 0 && ` · ${formatearPrecio(intento.monto, moneda)}`}
                  {intento.motivo_rechazo && ` · ${intento.motivo_rechazo}`}
                  {intento.entradas_error && ` · ${intento.entradas_error}`}
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="flex gap-3 justify-end">
          <button
            type="button"
            onClick={onCerrar}
            disabled={trabajando}
            className="px-4 py-2 text-sm text-tinta-media hover:text-tinta transition"
          >
            Volver
          </button>
          {orden && !calculo && (
            <button
              type="button"
              onClick={calcular}
              disabled={trabajando || nadaElegido}
              className="px-4 py-2 text-sm font-bold bg-black text-white rounded hover:bg-gray-800 transition disabled:opacity-50"
            >
              {trabajando ? 'Calculando...' : 'Calcular devolución'}
            </button>
          )}
          {orden && calculo && (
            <button
              type="button"
              onClick={devolver}
              disabled={trabajando}
              className="px-4 py-2 text-sm font-bold bg-red-600 text-white rounded hover:bg-red-500 transition disabled:opacity-50"
            >
              {trabajando ? 'Devolviendo...' : `Devolver ${formatearPrecio(calculo.monto, moneda)}`}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

const ESTADOS = {
  pendiente: 'En curso',
  incierta: 'Verificando con Mercado Pago',
  aprobada: 'Devuelta',
  rechazada: 'No realizada',
};

/** "26/11 14:05" desde "2026-11-26 14:05:00". */
export function fechaYHora(momento) {
  if (!momento) return '';

  const [fecha, hora = ''] = String(momento).replace('T', ' ').split(' ');
  const [, mes, dia] = fecha.split('-');

  return `${dia}/${mes} ${hora.slice(0, 5)}`.trim();
}

function cantidadElegida(porEntrada, cantidad, porTipo, lugares) {
  if (porEntrada.forma === 'lugares') return lugares.length;
  if (porEntrada.forma === 'tipos') return Object.values(porTipo).reduce((suma, n) => suma + (Number(n) || 0), 0);

  return Number(cantidad) || 0;
}

function EleccionDeEntradas({ porEntrada, cantidad, porTipo, lugares, onCantidad, onPorTipo, onLugares }) {
  if (porEntrada.forma === 'lugares') {
    return (
      <fieldset className="mb-4">
        <legend className="text-sm text-tinta mb-2">Lugares que se devuelven</legend>
        <div className="flex flex-wrap gap-3">
          {porEntrada.lugares.map((lugar) => (
            <label key={lugar} className="flex items-center gap-1 text-sm text-tinta">
              <input
                type="checkbox"
                checked={lugares.includes(lugar)}
                onChange={(e) =>
                  onLugares(e.target.checked ? [...lugares, lugar] : lugares.filter((l) => l !== lugar))
                }
              />
              {describirLugar(lugar)}
            </label>
          ))}
        </div>
      </fieldset>
    );
  }

  if (porEntrada.forma === 'tipos') {
    return (
      <ul className="space-y-3 mb-4">
        {porEntrada.items.map((item) => {
          const personas = personasDe(item);
          const maximo = Math.floor(item.cantidad / personas);

          return (
            <li key={item.tipo} className="flex items-center justify-between gap-3">
              <span className="text-sm text-tinta">
                {item.nombre}
                {personas > 1 && <span className="text-tinta-suave"> ({personas} personas c/u)</span>}
              </span>
              <Contador
                etiqueta={item.nombre}
                valor={Number(porTipo[item.tipo]) || 0}
                maximo={maximo}
                onCambiar={(n) => onPorTipo({ ...porTipo, [item.tipo]: n })}
              />
            </li>
          );
        })}
      </ul>
    );
  }

  return (
    <div className="flex items-center justify-between gap-3 mb-4">
      <span className="text-sm text-tinta">Entradas que se devuelven</span>
      <Contador etiqueta="Entradas" valor={Number(cantidad) || 0} minimo={1} maximo={porEntrada.cantidad} onCambiar={onCantidad} />
    </div>
  );
}

function Contador({ etiqueta, valor, minimo = 0, maximo, onCambiar }) {
  return (
    <div className="flex items-center gap-2">
      <button
        type="button"
        aria-label={`Menos ${etiqueta}`}
        onClick={() => onCambiar(Math.max(minimo, valor - 1))}
        disabled={valor <= minimo}
        className="w-8 h-8 flex items-center justify-center border border-borde-fuerte rounded disabled:opacity-40"
      >
        <Minus className="w-3.5 h-3.5" />
      </button>
      <span className="w-8 text-center tabular-nums text-tinta" aria-label={`Cantidad de ${etiqueta}`}>
        {valor}
      </span>
      <button
        type="button"
        aria-label={`Más ${etiqueta}`}
        onClick={() => onCambiar(Math.min(maximo, valor + 1))}
        disabled={valor >= maximo}
        className="w-8 h-8 flex items-center justify-center border border-borde-fuerte rounded disabled:opacity-40"
      >
        <Plus className="w-3.5 h-3.5" />
      </button>
    </div>
  );
}

function Renglon({ etiqueta, valor, fuerte = false }) {
  return (
    <div className={`flex justify-between gap-4 px-3 py-1.5 ${fuerte ? 'font-bold text-tinta' : 'text-tinta-media'}`}>
      <dt>{etiqueta}</dt>
      <dd>{valor}</dd>
    </div>
  );
}

export default DevolverCompra;
