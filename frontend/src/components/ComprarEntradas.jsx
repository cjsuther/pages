import React, { useMemo, useState } from 'react';
import { X, Loader2, Check } from 'lucide-react';
import { formatearPrecio, opcionesDeCantidad } from '../utils/entradas';
import { esEmailValido, sugerenciaDeEmail } from '../utils/email';
import { resumirLugares } from '../utils/plano';
import {
  tiposPorLugar, pedidoDeLosLugares, resumenDelPedido, resumirTipos, precioDelTipo, lugaresSinCargo,
} from '../utils/tiposDeEntrada';
import { evento as eventoDePixel } from '../utils/metaPixel';
import PlanoDeLugares from './PlanoDeLugares';

/**
 * Formulario de compra o reserva de entradas de un evento.
 *
 * Con precio manda a Mercado Pago; sin precio la reserva queda confirmada en el
 * acto y no hay checkout de por medio.
 *
 * Si el evento tiene plano, en lugar de la cantidad se eligen los lugares:
 * la cantidad es cuántos se tocaron.
 *
 * Con tipos de entrada, sin plano se elige cuántas de cada tipo; con plano el
 * tipo lo pone la zona de cada lugar, y el total sale de lo elegido.
 */
function ComprarEntradas({ evento, entradas, apiUrl, color = '#3B82F6', onCerrar, pixelId = null }) {
  // El pixel donde se mide esta venta: el de la página que vende.
  //
  // En un evento colaborado no es la página que se está mirando sino la dueña
  // del evento, que es la que cobra. Si fueran distintos, el arranque del
  // checkout quedaría en una cuenta y la compra —que se registra al volver de
  // Mercado Pago, con los datos de la orden— en la otra, y ninguna de las dos
  // podría comparar cuántos de los que empezaron terminaron comprando.
  const pixel = evento.meta_pixel_id || evento.source_page_pixel || pixelId;
  const [datos, setDatos] = useState({ nombre: '', email: '', telefono: '', cantidad: 1 });
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState(null);
  const [reservado, setReservado] = useState(null);
  // Se avisa recién al salir del campo: marcar en rojo mientras se escribe
  // es molesto, porque todo email está incompleto hasta que se termina.
  const [avisoEmail, setAvisoEmail] = useState(null);
  const [sugerencia, setSugerencia] = useState(null);
  const [elegidos, setElegidos] = useState([]);
  // Empieza con lo que dijo la página, y se actualiza si al confirmar alguien
  // se adelantó con un lugar: elegir de nuevo sobre la foto vieja no sirve.
  const [ocupados, setOcupados] = useState(entradas.ocupados || []);
  // Sin plano y con tipos: cuántas de cada uno.
  const [pedido, setPedido] = useState({});

  const conPlano = Boolean(entradas.plano);
  const tipos = Array.isArray(entradas.tipos) && entradas.tipos.length > 0 ? entradas.tipos : null;
  const cantidades = opcionesDeCantidad(entradas);
  const maximo = Number(entradas.max_por_compra) || 1;

  const tipoDeLugar = useMemo(
    () => (conPlano && tipos ? tiposPorLugar(entradas.plano, tipos) : {}),
    [conPlano, tipos, entradas.plano]
  );

  // Las butacas de un tipo agotado se ven ocupadas: el plano tiene lugar,
  // pero ese tipo ya no se puede vender.
  const noElegibles = useMemo(() => {
    const agotados = new Set((tipos || []).filter((t) => t.agotado).map((t) => t.id));
    if (agotados.size === 0) return ocupados;

    const extra = Object.keys(tipoDeLugar).filter((lugar) => agotados.has(tipoDeLugar[lugar]));
    return Array.from(new Set([...ocupados, ...extra]));
  }, [tipos, tipoDeLugar, ocupados]);

  const resumen = tipos
    ? resumenDelPedido(tipos, conPlano ? pedidoDeLosLugares(elegidos, tipoDeLugar) : pedido)
    : null;
  let cantidad = Number(datos.cantidad);
  if (conPlano) cantidad = elegidos.length;
  else if (resumen) cantidad = resumen.cantidad;
  const total = resumen ? resumen.total : (Number(entradas.precio) || 0) * cantidad;
  // Una compra que no suma nada —sólo entradas sin costo— no pasa por el pago.
  const sinCobro = entradas.es_gratis || (resumen !== null && cantidad > 0 && total <= 0);

  const cambiarPedido = (id, valor) => {
    setPedido((previo) => ({ ...previo, [id]: valor }));
    setError(null);
  };

  const elegir = (nuevos) => {
    setElegidos(nuevos);
    setError(null);
  };

  const cambiar = (campo, valor) => {
    setDatos((previos) => ({ ...previos, [campo]: valor }));
    setError(null);

    if (campo === 'email') {
      setAvisoEmail(null);
      setSugerencia(null);
    }
  };

  const revisarEmail = () => {
    const email = datos.email.trim();

    if (email === '') {
      return;
    }

    if (!esEmailValido(email)) {
      setAvisoEmail('Revisá el email: parece que le falta algo.');
      return;
    }

    setAvisoEmail(null);
    setSugerencia(sugerenciaDeEmail(email));
  };

  const enviar = async (e) => {
    e.preventDefault();

    // La confirmación va por mail: mandar una compra con el email mal escrito
    // deja a la persona sin entrada y sin forma de reclamarla.
    if (!esEmailValido(datos.email)) {
      setAvisoEmail('Revisá el email: parece que le falta algo.');
      return;
    }

    if (conPlano && elegidos.length === 0) {
      setError('Elegí tus lugares en el plano.');
      return;
    }

    if (!conPlano && resumen && resumen.cantidad === 0) {
      setError('Elegí cuántas entradas querés.');
      return;
    }

    setEnviando(true);
    setError(null);

    let cuerpoDelPedido = datos;
    if (conPlano) cuerpoDelPedido = { ...datos, cantidad: elegidos.length, lugares: elegidos };
    else if (resumen) cuerpoDelPedido = { ...datos, cantidad, tipos: pedido };

    try {
      const respuesta = await fetch(`${apiUrl}/public/comprar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ link_id: evento.id, ...cuerpoDelPedido }),
      });

      const cuerpo = await respuesta.json();

      if (!respuesta.ok) {
        // Alguien tomó un lugar mientras tanto: se marca ocupado y se saca de
        // lo elegido, así lo que queda elegido sigue siendo comprable.
        if (Array.isArray(cuerpo.ocupados)) {
          setOcupados(cuerpo.ocupados);
          setElegidos((previos) => previos.filter((id) => !cuerpo.ocupados.includes(id)));
        }

        setError(cuerpo.error || 'No se pudo completar la operación');
        setEnviando(false);
        return;
      }

      // Arrancó el checkout. Se manda antes de irse a Mercado Pago, que es
      // la última pantalla nuestra que ve: después ya no podríamos.
      eventoDePixel(pixel, 'InitiateCheckout', {
        content_type: 'product',
        content_ids: [String(evento.id)],
        content_name: evento.text,
        num_items: cantidad,
        value: total,
        currency: entradas.moneda,
      });

      // Con cobro se sale a Mercado Pago; sin cobro ya está confirmada.
      if (cuerpo.url) {
        window.location.href = cuerpo.url;
        return;
      }

      setReservado(cuerpo.codigo);
      setEnviando(false);
    } catch (err) {
      setError('No pudimos conectarnos. Revisá tu conexión e intentá de nuevo.');
      setEnviando(false);
    }
  };

  if (reservado) {
    return (
      <Marco onCerrar={onCerrar}>
        <div className="text-center py-4">
          <div
            className="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-5"
            style={{ backgroundColor: color }}
          >
            <Check className="w-7 h-7 text-tinta" />
          </div>

          <h3 className="text-2xl font-bold text-tinta mb-2">¡Lugar reservado!</h3>
          <p className="text-tinta-media mb-6">
            Te esperamos en {evento.text}.
          </p>

          {resumen && resumen.items.length > 0 && (
            <p className="text-tinta font-bold mb-2">{resumirTipos(resumen.items)}</p>
          )}

          {conPlano && elegidos.length > 0 && (
            <p className="text-tinta font-bold mb-6">{resumirLugares(elegidos)}</p>
          )}

          <p className="text-sm text-tinta-suave mb-1">Tu código de reserva</p>
          <p className="text-xl font-mono font-bold text-tinta tracking-wider mb-6">{reservado}</p>

          <a
            href={`/entrada/${reservado}`}
            className="inline-block px-6 py-3 font-bold text-tinta"
            style={{ backgroundColor: color }}
          >
            Ver mi reserva
          </a>
        </div>
      </Marco>
    );
  }

  return (
    <Marco onCerrar={onCerrar} ancho={conPlano ? 'max-w-3xl' : 'max-w-md'}>
      <h3 className="text-2xl font-bold text-tinta mb-1">
        {entradas.es_gratis ? 'Reservar lugar' : 'Comprar entradas'}
      </h3>
      <p className="text-tinta-suave text-sm mb-6">{evento.text}</p>

      <form onSubmit={enviar} className="space-y-4">
        {conPlano && (
          <div>
            <p className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
              ELEGÍ TUS LUGARES
            </p>
            {tipos && tipos.length > 1 && (
              <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-tinta-media mb-2">
                {tipos.map((t) => (
                  <li key={t.id}>
                    <strong className="text-tinta">{t.nombre}</strong>{' '}
                    {precioDelTipo(t, (p) => formatearPrecio(p, entradas.moneda)) || 'sin costo'}
                    {t.agotado && ' · agotado'}
                  </li>
                ))}
              </ul>
            )}
            <PlanoDeLugares
              plano={entradas.plano}
              ocupados={noElegibles}
              elegidos={elegidos}
              onCambiar={elegir}
              maximo={maximo}
              color={color}
            />
            <p className="text-sm text-tinta mt-2" aria-live="polite">
              {elegidos.length === 0
                ? `Tocá las butacas o una mesa. Hasta ${maximo} por compra.`
                : resumirLugares(elegidos)}
            </p>
            {elegidos.length >= maximo && (
              <p className="text-xs text-amber-400 mt-1">Llegaste al máximo de {maximo} por compra.</p>
            )}
          </div>
        )}

        <Campo
          id="entrada-nombre"
          etiqueta="NOMBRE Y APELLIDO"
          value={datos.nombre}
          onChange={(v) => cambiar('nombre', v)}
          autoComplete="name"
          required
        />

        <div>
          <Campo
            id="entrada-email"
            etiqueta="EMAIL"
            type="email"
            value={datos.email}
            onChange={(v) => cambiar('email', v)}
            onBlur={revisarEmail}
            autoComplete="email"
            ayuda={avisoEmail ? null : 'Te mandamos ahí la confirmación'}
            aria-invalid={avisoEmail ? 'true' : undefined}
            aria-describedby={avisoEmail ? 'entrada-email-aviso' : undefined}
            required
          />

          {avisoEmail && (
            <p id="entrada-email-aviso" role="alert" className="text-xs text-red-700 mt-1">
              {avisoEmail}
            </p>
          )}

          {/* Un dominio mal tipeado pasa cualquier validación y no llega nunca.
              Se pregunta en vez de corregir solo: .co es un dominio real. */}
          {sugerencia && !avisoEmail && (
            <p className="text-xs text-amber-400 mt-1">
              ¿Quisiste decir{' '}
              <button
                type="button"
                onClick={() => {
                  cambiar('email', sugerencia);
                  setSugerencia(null);
                }}
                className="underline font-bold"
              >
                {sugerencia}
              </button>
              ?
            </p>
          )}
        </div>

        <Campo
          id="entrada-telefono"
          etiqueta="TELÉFONO (OPCIONAL)"
          type="tel"
          value={datos.telefono}
          onChange={(v) => cambiar('telefono', v)}
          autoComplete="tel"
          ayuda="Por si hay que avisarte de un cambio"
        />

        {!conPlano && tipos && (
          <SelectorDeTipos
            tipos={tipos}
            pedido={pedido}
            maximo={maximo}
            moneda={entradas.moneda}
            onCambiar={cambiarPedido}
          />
        )}

        {!conPlano && !tipos && (
          <div>
            <label htmlFor="entrada-cantidad" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
              Cantidad
            </label>
            <select
              id="entrada-cantidad"
              value={datos.cantidad}
              onChange={(e) => cambiar('cantidad', Number(e.target.value))}
              className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
            >
              {cantidades.map((n) => (
                <option key={n} value={n}>{n}</option>
              ))}
            </select>

            {entradas.disponibles <= 10 && (
              <p className="text-xs text-amber-400 mt-1">
                Quedan {entradas.disponibles} {entradas.disponibles === 1 ? 'entrada' : 'entradas'}
              </p>
            )}
          </div>
        )}

        {resumen && resumen.items.map((i) => {
          const sobran = lugaresSinCargo(i.cantidad, i.personas);
          if (sobran === 0 || !(i.precio > 0)) return null;
          return (
            <p key={`sobran-${i.id}`} className="text-sm text-tinta bg-amber-50 border border-amber-200 px-4 py-3">
              Por el mismo precio {sobran === 1 ? 'entra 1 persona más' : `entran ${sobran} personas más`} en {i.nombre}
              {conPlano ? ': elegí otro lugar de esa zona.' : '.'}
            </p>
          );
        })}

        {resumen && (resumen.items.length > 1 || resumen.items.some((i) => i.personas > 1)) && (
          <ul className="text-sm text-tinta-media space-y-1 border-t border-borde pt-4">
            {resumen.items.map((i) => (
              <li key={i.id} className="flex justify-between">
                <span>{i.cantidad} × {i.nombre}</span>
                <span>{formatearPrecio(i.subtotal, entradas.moneda)}</span>
              </li>
            ))}
          </ul>
        )}

        {!entradas.es_gratis && (
          <div className="flex items-baseline justify-between border-t border-borde pt-4">
            <span className="text-tinta-media">Total</span>
            <span className="text-2xl font-bold text-tinta">
              {formatearPrecio(total, entradas.moneda)}
            </span>
          </div>
        )}

        {error && (
          <p className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>
        )}

        <button
          type="submit"
          disabled={enviando || (conPlano && elegidos.length === 0) || (resumen !== null && cantidad === 0)}
          className="w-full py-4 font-bold text-tinta flex items-center justify-center gap-2 disabled:opacity-60"
          style={{ backgroundColor: color }}
        >
          {enviando && <Loader2 className="w-4 h-4 animate-spin" />}
          {enviando
            ? 'PROCESANDO...'
            : sinCobro
              ? 'Confirmar reserva'
              : 'IR A PAGAR'}
        </button>

        {!sinCobro && (
          <p className="text-xs text-tinta-suave text-center">
            Te vamos a llevar a Mercado Pago para completar el pago.
            Tu lugar queda reservado 15 minutos.
          </p>
        )}
      </form>
    </Marco>
  );
}

/**
 * Cuántas de cada tipo, sin plano. Cada uno ofrece hasta lo que le queda y
 * hasta lo que deja el máximo por compra con lo ya elegido de los otros.
 */
function SelectorDeTipos({ tipos, pedido, maximo, moneda, onCambiar }) {
  const elegidas = tipos.reduce((suma, t) => suma + (Number(pedido[t.id]) || 0), 0);

  return (
    <fieldset className="space-y-3">
      <legend className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">ENTRADAS</legend>

      {tipos.map((t) => {
        const propias = Number(pedido[t.id]) || 0;
        const tope = Math.max(0, Math.min(Number(t.disponibles) || 0, maximo - (elegidas - propias)));
        const id = `entrada-tipo-${t.id}`;

        return (
          <div key={t.id} className="flex items-center justify-between gap-4 border border-borde px-4 py-3">
            <label htmlFor={id} className="min-w-0">
              <span className="block font-bold text-tinta">{t.nombre}</span>
              <span className="block text-sm text-tinta-suave">
                {precioDelTipo(t, (p) => formatearPrecio(p, moneda)) || 'Sin costo'}
                {!t.agotado && t.disponibles <= 10 && ` · quedan ${t.disponibles}`}
              </span>
            </label>

            {t.agotado ? (
              <span className="text-sm font-bold text-tinta-suave">AGOTADO</span>
            ) : (
              <select
                id={id}
                value={propias}
                onChange={(e) => onCambiar(t.id, Number(e.target.value))}
                className="px-3 py-2 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none"
              >
                {Array.from({ length: Math.max(tope, propias) + 1 }, (_, n) => (
                  <option key={n} value={n}>{n}</option>
                ))}
              </select>
            )}
          </div>
        );
      })}

      {elegidas >= maximo && (
        <p className="text-xs text-amber-400">Llegaste al máximo de {maximo} por compra.</p>
      )}
    </fieldset>
  );
}

function Marco({ children, onCerrar, ancho = 'max-w-md' }) {
  return (
    <div
      className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-start justify-center p-4 z-[60] overflow-y-auto"
      onClick={onCerrar}
    >
      <div
        className={`bg-white border border-borde ${ancho} w-full p-5 sm:p-8 my-8 relative`}
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          onClick={onCerrar}
          aria-label="Cerrar"
          className="absolute top-4 right-4 text-tinta-suave hover:text-tinta transition"
        >
          <X className="w-5 h-5" />
        </button>

        {children}
      </div>
    </div>
  );
}

function Campo({ id, etiqueta, ayuda, value, onChange, type = 'text', ...resto }) {
  const conProblema = resto['aria-invalid'] === 'true';
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
        {etiqueta}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={`w-full px-4 py-3 bg-white border text-tinta focus:border-verde-oscuro transition ${
          conProblema ? 'border-red-200' : 'border-borde-fuerte'
        }`}
        {...resto}
      />
      {ayuda && <p className="text-xs text-tinta-suave mt-1">{ayuda}</p>}
    </div>
  );
}

export default ComprarEntradas;
