import React, { useState, useEffect } from 'react';
import { Loader2, AlertTriangle, Check } from 'lucide-react';
import { formatearPrecio, esGratis } from '../utils/entradas';
import { formatearPorcentaje } from '../utils/comisiones';
import { lugaresDelPlano, lugaresRepetidos, planoInicial } from '../utils/plano';
import EditorDePlano from './EditorDePlano';
import CopiarPlano from './CopiarPlano';

/**
 * Cómo se consiguen las entradas de un evento.
 *
 * Es una sola decisión con dos respuestas posibles, y por eso se pregunta así
 * y no con una casilla: o las entradas se venden en otro lado y lo único que
 * hace falta es el link, o las vende Rezonar y hay que decir cuántas hay, a
 * cuánto y cuántas puede llevar cada uno. Antes el link vivía en DATOS y la
 * venta interna acá, así que la decisión no estaba en ninguna pantalla: se
 * deducía de qué campos habían quedado cargados.
 *
 * Un precio de 0 es una reserva sin cobro y no necesita Mercado Pago.
 */
function PanelEntradas({ linkId, apiUrl, token, onCambio, enlace = null, onGuardarEnlace = null }) {
  const [config, setConfig] = useState(null);
  const [cobros, setCobros] = useState(null);
  const [ocupadas, setOcupadas] = useState(0);
  const [comision, setComision] = useState(0);
  const [mercadoPago, setMercadoPago] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState(null);
  const [guardado, setGuardado] = useState(false);
  const [modo, setModo] = useState('externo');
  const [form, setForm] = useState({ capacidad: 100, precio: 0, max_por_compra: 10 });
  const [link, setLink] = useState({ url: '', url_text: '' });
  // null es sin lugares asignados: se vende por cupo, como siempre.
  const [plano, setPlano] = useState(null);
  const [lugaresOcupados, setLugaresOcupados] = useState([]);

  const cabeceras = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  };

  useEffect(() => {
    if (enlace) {
      setLink({ url: enlace.url || '', url_text: enlace.url_text || '' });
    }
    // Sólo para arrancar con lo que ya tenía el evento: mientras se edita, el
    // campo manda. Sin esto, guardar el link lo pisaría con el valor viejo.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [linkId]);

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
        setMercadoPago(cuerpo.mercadopago || null);
        setConfig(cuerpo.entradas);
        setLugaresOcupados(cuerpo.lugares_ocupados || []);

        if (cuerpo.entradas) {
          setPlano(cuerpo.entradas.plano || null);

          setForm({
            capacidad: Number(cuerpo.entradas.capacidad),
            precio: Number(cuerpo.entradas.precio),
            max_por_compra: Number(cuerpo.entradas.max_por_compra),
          });

          // La venta interna prendida es la que manda: es la que decide qué ve
          // el público, que con ella activa ni mira el link.
          if (Number(cuerpo.entradas.activo)) {
            setModo('interno');
          }
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

  const cambiarLink = (campo, valor) => {
    setLink((previo) => ({ ...previo, [campo]: valor }));
    setGuardado(false);
    setError(null);
  };

  const cambiarPlano = (nuevo) => {
    setPlano(nuevo);
    setGuardado(false);
    setError(null);
  };

  const elegirModo = (cual) => {
    setModo(cual);
    setGuardado(false);
    setError(null);
  };

  /** Guarda la configuración de venta interna. `activo` sale del modo elegido. */
  const guardarEntradas = async (activo) => {
    const r = await fetch(`${apiUrl}/entradas/evento.php?link_id=${linkId}`, {
      method: 'POST',
      headers: cabeceras,
      // El plano va siempre, también en null: es lo que le dice al servidor
      // que se saca. Con plano la capacidad la calcula él.
      body: JSON.stringify({ ...form, activo, plano }),
    });
    const cuerpo = await r.json();

    if (!r.ok) {
      throw new Error(cuerpo.error || 'No se pudo guardar');
    }

    setConfig(cuerpo.entradas);
    if (cuerpo.entradas && cuerpo.entradas.plano) {
      setForm((previo) => ({ ...previo, capacidad: Number(cuerpo.entradas.capacidad) }));
    }
    if (onCambio) onCambio(cuerpo.entradas);
  };

  const guardar = async () => {
    setGuardando(true);
    setError(null);

    try {
      if (modo === 'interno') {
        await guardarEntradas(true);
      } else {
        if (onGuardarEnlace) {
          await onGuardarEnlace(link);
        }

        // Apagar la venta interna es parte de elegir "en otro lado": si queda
        // prendida, el detalle del evento sigue ofreciendo el botón de compra
        // y el link no se ve nunca. Se apaga después de guardar el link, para
        // no dejar al evento sin ninguna de las dos cosas si eso falla.
        if (config && Number(config.activo)) {
          await guardarEntradas(false);
        }
      }

      setGuardado(true);
    } catch (e) {
      setError(e.message || 'No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  if (cargando) {
    return <p className="text-tinta-suave py-6">Cargando...</p>;
  }

  const sinMercadoPago = !cobros || !cobros.configurado;
  const quiereCobrar = !esGratis(form.precio);
  const capacidad = plano ? lugaresDelPlano(plano).length : Number(form.capacidad);
  const disponibles = Math.max(0, capacidad - ocupadas);
  // Hay entradas vendidas sin lugar: pasar a plano dejaría a esa gente sin
  // dónde sentarse, y el servidor no lo acepta.
  const vendidasSinLugar = ocupadas - lugaresOcupados.length;
  // Un plano copiado de otro evento puede no tener los lugares que este ya
  // vendió: el servidor lo rechazaría, así que no se deja guardar.
  const lugaresPerdidos = plano === null
    ? []
    : lugaresOcupados.filter((id) => !lugaresDelPlano(plano).includes(id));
  const planoConProblemas = plano !== null
    && (lugaresRepetidos(plano).length > 0 || lugaresPerdidos.length > 0);

  return (
    <div className="space-y-6">
      <fieldset>
        <legend className="text-sm font-bold text-tinta-media mb-3 tracking-wide">
          ¿DÓNDE SE CONSIGUEN LAS ENTRADAS?
        </legend>

        <div className="grid gap-3 sm:grid-cols-2">
          <OpcionDeModo
            valor="interno"
            elegido={modo}
            onElegir={elegirModo}
            titulo="Acá, con Rezonar"
            detalle="El evento muestra el botón de compra o reserva."
          />
          <OpcionDeModo
            valor="externo"
            elegido={modo}
            onElegir={elegirModo}
            titulo="En otro lado"
            detalle="El evento muestra un link a donde se venden."
          />
        </div>
      </fieldset>

      {modo === 'externo' && (
        <>
          <div>
            <label htmlFor="entradas-link" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
              Link (opcional)
            </label>
            <input
              id="entradas-link"
              type="url"
              value={link.url}
              onChange={(e) => cambiarLink('url', e.target.value)}
              placeholder="https://..."
              className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
            />
            <p className="text-xs text-tinta-suave mt-1">
              A dónde mandar a quien quiera la entrada. Sin link, el evento se
              muestra igual pero sin botón.
            </p>
          </div>

          <div>
            <label htmlFor="entradas-texto-boton" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
              Texto del botón (opcional)
            </label>
            <input
              id="entradas-texto-boton"
              type="text"
              value={link.url_text}
              onChange={(e) => cambiarLink('url_text', e.target.value)}
              placeholder="Más información"
              className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
            />
          </div>
        </>
      )}

      {modo === 'interno' && (
        <>
          <fieldset>
            <legend className="text-sm font-bold text-tinta-media mb-3 tracking-wide">
              ¿CÓMO SE UBICA LA GENTE?
            </legend>

            <div className="grid gap-3 sm:grid-cols-2">
              <OpcionDeModo
                nombre="ubicacion"
                valor="libre"
                elegido={plano ? 'plano' : 'libre'}
                onElegir={() => cambiarPlano(null)}
                titulo="Sin lugares asignados"
                detalle="Se vende por cupo: cada uno se ubica donde quiere."
                deshabilitado={lugaresOcupados.length > 0}
              />
              <OpcionDeModo
                nombre="ubicacion"
                valor="plano"
                elegido={plano ? 'plano' : 'libre'}
                onElegir={() => cambiarPlano(
                  config && config.plano ? config.plano : planoInicial()
                )}
                titulo="Con plano de butacas y mesas"
                detalle="Cada uno elige su butaca o su mesa al comprar."
                deshabilitado={vendidasSinLugar > 0}
              />
            </div>

            {vendidasSinLugar > 0 && !plano && (
              <p className="text-xs text-tinta-suave mt-2">
                Ya hay entradas vendidas sin lugar asignado: este evento no puede pasar a un plano.
              </p>
            )}
            {lugaresOcupados.length > 0 && plano && (
              <p className="text-xs text-tinta-suave mt-2">
                Ya hay lugares vendidos: el plano no se puede sacar.
              </p>
            )}

            <div className="mt-3">
              <CopiarPlano
                linkId={linkId}
                apiUrl={apiUrl}
                token={token}
                onCopiar={cambiarPlano}
                deshabilitado={vendidasSinLugar > 0}
              />
            </div>
          </fieldset>

          {plano && (
            <EditorDePlano plano={plano} onCambiar={cambiarPlano} ocupados={lugaresOcupados} />
          )}

          <div className="grid grid-cols-2 gap-4">
            {plano ? (
              <div>
                <p className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">Capacidad máxima</p>
                <p className="px-4 py-3 bg-papel-hueso border border-borde text-tinta">{capacidad}</p>
                <p className="text-xs text-tinta-suave mt-1">Sale de los lugares del plano</p>
              </div>
            ) : (
              <div>
                <label htmlFor="entradas-capacidad" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
                  Capacidad máxima
                </label>
                <input
                  id="entradas-capacidad"
                  type="number"
                  min="1"
                  value={form.capacidad}
                  onChange={(e) => cambiar('capacidad', Number(e.target.value))}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                />
              </div>
            )}

            <div>
              <label htmlFor="entradas-precio" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
                Precio por entrada
              </label>
              <input
                id="entradas-precio"
                type="number"
                min="0"
                step="0.01"
                value={form.precio}
                onChange={(e) => cambiar('precio', Number(e.target.value))}
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
              />
              <p className="text-xs text-tinta-suave mt-1">
                {esGratis(form.precio)
                  ? 'En 0 es una reserva sin costo'
                  : formatearPrecio(form.precio)}
              </p>

              {/* Lo que importa a la hora de poner el precio no es lo que paga
                  el comprador sino lo que termina entrando a la cuenta. */}
              {!esGratis(form.precio) && comision > 0 && cobros && cobros.admite_split && (
                <>
                  <p className="text-xs text-verde-oscuro mt-1">
                    Menos la comisión de Rezonar ({formatearPorcentaje(comision)}%):{' '}
                    {formatearPrecio(form.precio * (100 - comision) / 100)} por entrada
                  </p>
                  {/* El porcentaje y el plazo van juntos: en Mercado Pago uno
                      depende del otro, y a la hora de poner un precio lo que
                      importa es cuánto entra y cuándo. Si el servidor no los
                      tiene cargados no decimos nada: un número viejo acá es
                      peor que ninguno. */}
                  {mercadoPago && (
                    <p className="text-xs text-tinta-suave mt-1">
                      A eso Mercado Pago le descuenta aparte {formatearPorcentaje(mercadoPago.porcentaje)}%
                      por procesar el pago, y libera la plata a los {mercadoPago.dias} días
                      de la compra.
                    </p>
                  )}
                </>
              )}
            </div>
          </div>

          <div>
            <label htmlFor="entradas-max" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
              Máximo por compra
            </label>
            <input
              id="entradas-max"
              type="number"
              min="1"
              max="50"
              value={form.max_por_compra}
              onChange={(e) => cambiar('max_por_compra', Number(e.target.value))}
              className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
            />
            <p className="text-xs text-tinta-suave mt-1">
              Para que una sola persona no se lleve todo el cupo.
            </p>
          </div>

          {ocupadas > 0 && (
            <div className="border border-borde bg-white p-4 text-sm">
              <p className="text-tinta-media">
                Ya hay <strong className="text-tinta">{ocupadas}</strong> entradas tomadas
                (vendidas o reservándose ahora).
              </p>
              <p className="text-tinta-suave mt-1">
                Quedan {disponibles} disponibles.
                {plano
                  ? ' Los lugares vendidos no se pueden sacar del plano.'
                  : ` No podés bajar la capacidad por debajo de ${ocupadas}.`}
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

      {/* Cambiar de "acá" a "en otro lado" apaga la venta, no la borra: lo
          vendido sigue estando y se puede volver a prender sin recargar nada. */}
      {modo === 'externo' && config && Number(config.activo) > 0 && (
        <p className="flex items-start gap-2 text-amber-400 text-sm bg-amber-950 border border-amber-900 px-4 py-3">
          <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
          Al guardar se deja de vender por Rezonar. Lo ya vendido no se toca y
          lo seguís viendo en VENTAS.
        </p>
      )}

      {error && (
        <p className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>
      )}

      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={guardar}
          disabled={guardando || (modo === 'interno' && planoConProblemas)}
          className="inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-50 flex items-center gap-2"
        >
          {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
          {guardando ? 'Guardando...' : 'Guardar entradas'}
        </button>

        {guardado && !guardando && (
          <span className="flex items-center gap-2 text-verde-oscuro text-sm font-medium">
            <Check className="w-4 h-4" />
            Guardado
          </span>
        )}
      </div>
    </div>
  );
}

/**
 * Una de las dos respuestas posibles.
 *
 * Es un radio de verdad y no un botón: son opciones excluyentes de una misma
 * pregunta, y así se puede elegir con el teclado y un lector de pantalla lo
 * lee como lo que es.
 */
function OpcionDeModo({
  valor, elegido, onElegir, titulo, detalle, nombre = 'modo-de-entradas', deshabilitado = false,
}) {
  const activo = elegido === valor;

  return (
    <label
      className={`flex items-start gap-3 border p-4 transition ${
        activo ? 'border-verde bg-verde-claro' : 'border-borde hover:border-borde-fuerte'
      } ${deshabilitado && !activo ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
    >
      <input
        type="radio"
        name={nombre}
        value={valor}
        checked={activo}
        onChange={() => onElegir(valor)}
        disabled={deshabilitado && !activo}
        className="mt-1"
      />
      <span>
        <span className="block text-tinta font-bold">{titulo}</span>
        <span className="block text-sm text-tinta-suave">{detalle}</span>
      </span>
    </label>
  );
}

export default PanelEntradas;
