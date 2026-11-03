import React, { useEffect, useMemo, useState } from 'react';
import { Minus, Plus } from 'lucide-react';
import PlanoDeLugares from './PlanoDeLugares';
import { resumirLugares } from '../utils/plano';
import { personasDe, unidades } from '../utils/tiposDeEntrada';

/**
 * Modificar una compra ya confirmada: la cantidad, cuántas de cada tipo o los
 * lugares, según cómo venda el evento.
 *
 * No se cobra ni se devuelve nada: el total queda como se pagó. Al guardar, el
 * servidor le manda un mail a quien compró con la entrada actualizada, con el
 * mismo código y el mismo QR.
 *
 * Lo que puede tener la compra lo dice el servidor: los lugares de las demás
 * compras, y cuántas quedan contando como libres las que ya son de ésta.
 */
function ModificarCompra({ codigo, apiUrl, token, onCerrar, onModificada }) {
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [guardando, setGuardando] = useState(false);
  const [cantidad, setCantidad] = useState(1);
  // Con tipos y sin plano: promos (o entradas) de cada tipo.
  const [porTipo, setPorTipo] = useState({});
  const [elegidos, setElegidos] = useState([]);
  const [ocupados, setOcupados] = useState([]);

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/entradas/modificar.php?codigo=${encodeURIComponent(codigo)}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos abrir la compra');
          return;
        }

        setDatos(cuerpo);
        setCantidad(cuerpo.orden.cantidad);
        setElegidos(cuerpo.orden.lugares || []);
        setOcupados(cuerpo.ocupados || []);

        const inicial = {};
        (cuerpo.tipos || []).forEach((t) => {
          const item = (cuerpo.orden.items || []).find((i) => i.tipo === t.id);
          inicial[t.id] = item ? unidades(item.cantidad, personasDe(t)) : 0;
        });
        setPorTipo(inicial);
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

  const conPlano = Boolean(datos && datos.plano);
  const tipos = datos && !conPlano && Array.isArray(datos.tipos) && datos.tipos.length > 0 ? datos.tipos : null;

  const personasPedidas = useMemo(() => {
    if (!datos) return 0;
    if (conPlano) return elegidos.length;
    if (tipos) return tipos.reduce((suma, t) => suma + (Number(porTipo[t.id]) || 0) * personasDe(t), 0);
    return Number(cantidad) || 0;
  }, [datos, conPlano, tipos, elegidos, porTipo, cantidad]);

  const guardar = async () => {
    setGuardando(true);
    setError(null);

    let cambio;
    if (conPlano) cambio = { lugares: elegidos };
    else if (tipos) {
      cambio = { tipos: Object.fromEntries(tipos.map((t) => [t.id, (Number(porTipo[t.id]) || 0) * personasDe(t)])) };
    } else cambio = { cantidad: Number(cantidad) };

    try {
      const r = await fetch(`${apiUrl}/entradas/modificar.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo, ...cambio }),
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No pudimos modificar la compra');

        // Alguien tomó un lugar mientras se editaba: se refresca el plano y
        // se sueltan los que ya no están libres.
        if (Array.isArray(cuerpo.ocupados)) {
          setOcupados(cuerpo.ocupados);
          setElegidos((previos) => previos.filter((id) => !cuerpo.ocupados.includes(id)));
        }
        return;
      }

      onModificada(cuerpo);
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  const orden = datos && datos.orden;
  const ingresadas = orden ? orden.ingresadas : 0;

  return (
    <div className="fixed inset-0 bg-white bg-opacity-80 z-50 flex items-center justify-center p-4">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="modificar-compra-titulo"
        className="bg-white border border-borde-fuerte rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto"
      >
        <h4 id="modificar-compra-titulo" className="text-tinta font-bold text-lg mb-1">
          Modificar compra
        </h4>

        {cargando && <p className="text-sm text-tinta-suave py-6">Cargando la compra...</p>}

        {orden && (
          <>
            <p className="text-sm text-tinta-media mb-4">
              {orden.nombre} · <span className="font-mono">{orden.codigo}</span>
            </p>

            {conPlano && (
              <div className="mb-4">
                <PlanoDeLugares
                  plano={datos.plano}
                  ocupados={ocupados}
                  elegidos={elegidos}
                  onCambiar={setElegidos}
                  maximo={datos.disponibles}
                />
                <p className="text-sm text-tinta mt-2">
                  {elegidos.length === 0
                    ? 'Elegí al menos un lugar.'
                    : `${elegidos.length} ${elegidos.length === 1 ? 'lugar' : 'lugares'}: ${resumirLugares(elegidos)}`}
                </p>
              </div>
            )}

            {tipos && (
              <ul className="space-y-3 mb-4">
                {tipos.map((t) => {
                  const personas = personasDe(t);
                  const valor = Number(porTipo[t.id]) || 0;
                  const maximo = Math.floor(t.disponibles / personas);

                  return (
                    <li key={t.id} className="flex items-center justify-between gap-3">
                      <span className="text-sm text-tinta">
                        {t.nombre}
                        {personas > 1 && <span className="text-tinta-suave"> ({personas} personas c/u)</span>}
                      </span>
                      <Contador
                        etiqueta={t.nombre}
                        valor={valor}
                        maximo={maximo}
                        onCambiar={(n) => setPorTipo((previo) => ({ ...previo, [t.id]: n }))}
                      />
                    </li>
                  );
                })}
              </ul>
            )}

            {!conPlano && !tipos && (
              <div className="flex items-center justify-between gap-3 mb-4">
                <span className="text-sm text-tinta">Entradas</span>
                <Contador
                  etiqueta="Entradas"
                  valor={Number(cantidad) || 0}
                  minimo={Math.max(1, ingresadas)}
                  maximo={datos.disponibles}
                  onCambiar={setCantidad}
                />
              </div>
            )}

            <p className="text-sm text-tinta-media mb-1">
              Antes: {orden.cantidad} {orden.cantidad === 1 ? 'entrada' : 'entradas'} · Ahora: {personasPedidas}{' '}
              {personasPedidas === 1 ? 'entrada' : 'entradas'}
            </p>
            {ingresadas > 0 && (
              <p className="text-sm text-tinta-media mb-1">
                Ya {ingresadas === 1 ? 'entró 1 persona' : `entraron ${ingresadas} personas`}: no puede quedar con menos.
              </p>
            )}
            <p className="text-sm text-tinta-suave mb-6">
              No se cobra ni se devuelve nada: el total queda como se pagó. Le mandamos un mail a {orden.email} con
              la entrada actualizada; el QR sigue siendo el mismo.
            </p>
          </>
        )}

        {error && (
          <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-3 py-2 mb-4">
            {error}
          </p>
        )}

        <div className="flex gap-3 justify-end">
          <button
            type="button"
            onClick={onCerrar}
            disabled={guardando}
            className="px-4 py-2 text-sm text-tinta-media hover:text-tinta transition"
          >
            Volver
          </button>
          {orden && (
            <button
              type="button"
              onClick={guardar}
              disabled={guardando || personasPedidas < 1}
              className="px-4 py-2 text-sm font-bold bg-tinta text-white rounded hover:opacity-90 transition disabled:opacity-50"
            >
              {guardando ? 'Guardando...' : 'Guardar y avisar'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

/** Menos, número, más. */
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

export default ModificarCompra;
