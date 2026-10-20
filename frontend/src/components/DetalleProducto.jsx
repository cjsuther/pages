import React, { useContext, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Download, FileText, Share2, ShoppingBag } from 'lucide-react';
import { AuthContext } from '../App';
import ComprarProducto from './ComprarProducto';
import useBloqueoDeScroll from '../hooks/useBloqueoDeScroll';
import { compartirLink, describirPrecio, esPago, linkDeProducto } from '../utils/productos';
import { describirPeso } from '../utils/imagenes';
import { textoSobre } from '../utils/colores';

/** Si no llega scrollend, cuánto se espera para volver a seguir el scroll. */
const MS_DE_RESPALDO = 1000;

/** Si la persona pidió menos movimiento en su sistema. */
export function prefiereMenosMovimiento() {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Lo que hace que una tarjeta de producto de la grilla se abra como un botón:
 * con el click y, para el teclado, con Enter o Espacio. Las teclas que vienen
 * del botón de comprar de adentro no cuentan: ese botón hace lo suyo.
 */
export function tarjetaQueAbre(abrir) {
  return {
    role: 'button',
    tabIndex: 0,
    onClick: abrir,
    onKeyDown: (e) => {
      if (e.target !== e.currentTarget) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        abrir();
      }
    },
  };
}

/**
 * "Comprar" o "Descargar gratis", según el precio. En la grilla va chico y no
 * abre la tarjeta que lo contiene; con soloIcono queda el ícono con su nombre
 * para los lectores de pantalla. Se pinta con el color de botones, salvo que
 * la plantilla pida otro fondo porque ése se perdería sobre su tarjeta.
 */
export function BotonComprar({ producto, colores, onComprar, className = '', soloIcono = false, fondo }) {
  const pago = esPago(producto.precio);
  const texto = pago ? 'Comprar' : 'Descargar gratis';
  const Icono = pago ? ShoppingBag : Download;
  const color = fondo || colores.boton;

  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation();
        onComprar();
      }}
      aria-label={soloIcono ? texto : undefined}
      title={soloIcono ? texto : undefined}
      className={`inline-flex items-center justify-center gap-1.5 font-bold transition hover:opacity-90 ${className}`}
      style={{ backgroundColor: color, color: textoSobre(color) }}
    >
      <Icono className="w-4 h-4 shrink-0" aria-hidden="true" />
      {!soloIcono && texto}
    </button>
  );
}

/**
 * Pasar de un producto a otro deslizando: flechas, puntos y el dedo.
 *
 * El deslizamiento lo hace el navegador con scroll-snap, sin ninguna
 * librería; las flechas, los puntos y el teclado mueven ese mismo scroll, así
 * que hay una sola fuente de verdad sobre cuál se está viendo.
 */
function useCarrusel(cantidad, inicial) {
  const pista = useRef(null);
  const [actual, setActual] = useState(inicial);

  // Adónde va el desplazamiento suave que pidieron las flechas, los puntos o
  // el teclado. Mientras anima, el scroll pasa por posiciones que redondean al
  // producto anterior: hasta que llega, manda el destino y no el scroll.
  const pendiente = useRef(null);
  const respaldo = useRef(null);

  const soltar = () => {
    pendiente.current = null;
    clearTimeout(respaldo.current);
  };

  useEffect(() => {
    const el = pista.current;
    if (!el) return undefined;

    el.addEventListener('scrollend', soltar);
    return () => {
      el.removeEventListener('scrollend', soltar);
      clearTimeout(respaldo.current);
    };
    // soltar sólo toca refs: no hace falta volver a suscribirse en cada render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Abre en el producto que se tocó, sin animar el viaje desde el primero.
  useLayoutEffect(() => {
    const el = pista.current;
    if (el) el.scrollLeft = inicial * el.clientWidth;
    // Sólo al abrir: después el scroll lo mueve la persona.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const ultimo = cantidad - 1;
  const desde = () => (pendiente.current ?? actual);

  const ir = (indice) => {
    const destino = Math.max(0, Math.min(ultimo, indice));
    const el = pista.current;

    setActual(destino);

    if (el && typeof el.scrollTo === 'function') {
      pendiente.current = destino;
      clearTimeout(respaldo.current);
      // No todos los navegadores emiten scrollend.
      respaldo.current = setTimeout(soltar, MS_DE_RESPALDO);
      el.scrollTo({
        left: destino * el.clientWidth,
        behavior: prefiereMenosMovimiento() ? 'auto' : 'smooth',
      });
    }
  };

  // Lo que deslizó el dedo (o el foco al tabular) también cambia el actual.
  const alDesplazar = () => {
    const el = pista.current;
    if (!el || !el.clientWidth) return;

    if (pendiente.current !== null) {
      if (Math.abs(el.scrollLeft - pendiente.current * el.clientWidth) >= 1) return;
      soltar();
    }

    const indice = Math.round(el.scrollLeft / el.clientWidth);
    if (indice !== actual && indice >= 0 && indice <= ultimo) setActual(indice);
  };

  return {
    pista,
    actual,
    alDesplazar,
    anterior: () => ir(desde() - 1),
    siguiente: () => ir(desde() + 1),
    ir,
  };
}

/**
 * El detalle de los productos digitales de un grupo, para la página pública.
 *
 * Es un modal con el mismo marco que el detalle de un evento de la plantilla
 * (modalStyle, la ×, el cierre al tocar afuera), que abre en el producto que
 * se tocó y deja pasar al anterior y al siguiente del grupo.
 *
 * Con comprar, va directo al formulario de compra sin mostrar el detalle: es
 * lo que hace el botón chico de la tarjeta.
 */
function DetalleProducto({
  productos,
  inicial = 0,
  comprar = false,
  colores,
  modalStyle,
  claseCaja = 'rounded-lg p-8',
  onCerrar,
}) {
  const { apiUrl } = useContext(AuthContext);
  const [comprando, setComprando] = useState(comprar ? productos[inicial] : null);
  const carrusel = useCarrusel(productos.length, inicial);
  const caja = useRef(null);

  useBloqueoDeScroll(!comprar);

  // El teclado se escucha en todo el documento mientras el detalle está al
  // frente. Con el formulario de compra encima, las teclas son de él.
  const alApretar = useRef(null);
  alApretar.current = (e) => {
    if (comprando) return;
    if (e.key === 'Escape') {
      onCerrar();
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      carrusel.siguiente();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      carrusel.anterior();
    }
  };

  useEffect(() => {
    if (comprar) return undefined;
    const escuchar = (e) => alApretar.current(e);
    document.addEventListener('keydown', escuchar);
    caja.current?.focus();
    return () => document.removeEventListener('keydown', escuchar);
  }, [comprar]);

  const formulario = comprando && (
    <ComprarProducto
      producto={comprando}
      apiUrl={apiUrl}
      color={colores.boton}
      onCerrar={comprar ? onCerrar : () => setComprando(null)}
    />
  );

  if (comprar) return formulario;

  const varios = productos.length > 1;
  const { pista, actual } = carrusel;
  const botonFlecha = 'p-2 rounded-full border border-current transition disabled:opacity-30 disabled:cursor-default hover:opacity-80';

  return (
    <>
      <div
        className="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4"
        onClick={onCerrar}
      >
        <div
          ref={caja}
          role="dialog"
          aria-modal="true"
          aria-label={productos[actual]?.text}
          tabIndex={-1}
          className="max-w-2xl w-full max-h-[90vh] overflow-y-auto focus:outline-none"
          onClick={(e) => e.stopPropagation()}
        >
          <div className={claseCaja} style={modalStyle}>
            <div className="flex justify-end">
              <button
                type="button"
                onClick={onCerrar}
                aria-label="Cerrar"
                className="text-3xl font-bold opacity-60 hover:opacity-100 leading-none"
              >
                ×
              </button>
            </div>

            <section
              aria-roledescription={varios ? 'carrusel' : undefined}
              aria-label={varios ? 'Productos' : undefined}
            >
              <div
                ref={pista}
                onScroll={carrusel.alDesplazar}
                className="flex items-start overflow-x-auto snap-x snap-mandatory motion-safe:scroll-smooth"
                style={{ scrollbarWidth: 'none' }}
              >
                {productos.map((producto, i) => (
                  <div
                    key={producto.id}
                    role="group"
                    aria-roledescription="producto"
                    aria-label={`${i + 1} de ${productos.length}`}
                    className="w-full shrink-0 snap-center px-1"
                  >
                    <Producto producto={producto} colores={colores} onComprar={() => setComprando(producto)} />
                  </div>
                ))}
              </div>

              {varios && (
                <div className="flex items-center justify-center gap-4 mt-6">
                  <button
                    type="button"
                    onClick={carrusel.anterior}
                    disabled={actual === 0}
                    aria-label="Producto anterior"
                    className={botonFlecha}
                  >
                    <ChevronLeft className="w-5 h-5" />
                  </button>

                  <div className="flex flex-wrap items-center justify-center gap-2">
                    {productos.map((p, i) => (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => carrusel.ir(i)}
                        aria-label={`Ver producto ${i + 1} de ${productos.length}`}
                        aria-current={i === actual ? 'true' : undefined}
                        className={`h-2.5 rounded-full bg-current transition-all ${i === actual ? 'w-5' : 'w-2.5 opacity-40'}`}
                      />
                    ))}
                  </div>

                  <button
                    type="button"
                    onClick={carrusel.siguiente}
                    disabled={actual === productos.length - 1}
                    aria-label="Producto siguiente"
                    className={botonFlecha}
                  >
                    <ChevronRight className="w-5 h-5" />
                  </button>
                </div>
              )}
            </section>
          </div>
        </div>
      </div>

      {formulario}
    </>
  );
}

/**
 * Compartir el link propio del producto: el menú del sistema donde exista y,
 * si no, copiarlo con un aviso.
 */
export function BotonCompartir({ producto }) {
  const [aviso, setAviso] = useState(null);

  const compartir = async () => {
    try {
      const resultado = await compartirLink({ url: linkDeProducto(producto.id), titulo: producto.text });
      setAviso(resultado === 'copiado' ? 'Link copiado' : null);
    } catch (e) {
      setAviso('No se pudo copiar el link');
    }
  };

  return (
    <span className="inline-flex items-center gap-2">
      <button
        type="button"
        onClick={compartir}
        className="inline-flex items-center gap-1.5 text-sm font-semibold opacity-80 hover:opacity-100 transition"
      >
        <Share2 className="w-4 h-4 shrink-0" aria-hidden="true" />
        Compartir
      </button>
      <span role="status" className="text-sm opacity-70">{aviso}</span>
    </span>
  );
}

/**
 * Un producto entero: imagen, título, descripción, archivo, precio y los
 * botones. Es lo que muestra el detalle de la página y también /producto/:id,
 * que lo usa con un h1 y el color de títulos de la página.
 */
export function Producto({ producto, colores, onComprar, Titulo = 'h2', colorTitulo }) {
  return (
    <>
      {producto.image_url && (
        <img src={producto.image_url} alt={producto.text} className="w-full h-auto rounded-lg mb-6" />
      )}

      <Titulo className="text-3xl font-bold mb-4" style={colorTitulo ? { color: colorTitulo } : undefined}>
        {producto.text}
      </Titulo>

      {producto.description && (
        <p className="mb-4 text-lg leading-relaxed whitespace-pre-line">{producto.description}</p>
      )}

      {producto.archivo_nombre && (
        <p className="mb-4 text-sm opacity-70 flex items-center gap-1.5 min-w-0">
          <FileText className="w-4 h-4 shrink-0" aria-hidden="true" />
          <span className="truncate">{producto.archivo_nombre}</span>
          {producto.archivo_bytes > 0 && (
            <span className="shrink-0">· {describirPeso(producto.archivo_bytes)}</span>
          )}
        </p>
      )}

      <div className="flex flex-wrap items-center justify-between gap-4 mt-6">
        <span className="text-2xl font-bold">{describirPrecio(producto.precio)}</span>
        <BotonComprar producto={producto} colores={colores} onComprar={onComprar} className="px-5 py-3 rounded-lg" />
      </div>

      <div className="mt-4">
        <BotonCompartir producto={producto} />
      </div>
    </>
  );
}

export default DetalleProducto;
