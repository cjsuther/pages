import React, { useContext, useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Download, FileText, ShoppingBag } from 'lucide-react';
import { AuthContext } from '../App';
import ComprarProducto from './ComprarProducto';
import { describirPrecio, esPago } from '../utils/productos';
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
 * Los productos digitales de un grupo, para la página pública.
 *
 * Con varios se ven de a uno, como un carrusel: flechas, puntos y deslizar con
 * el dedo. El deslizamiento lo hace el navegador con scroll-snap, sin ninguna
 * librería; las flechas, los puntos y el teclado mueven ese mismo scroll, así
 * que hay una sola fuente de verdad sobre cuál se está viendo.
 *
 * El estilo lo pone cada plantilla (claseTarjeta, estiloTarjeta, claseImagen),
 * igual que con los eventos y la galería; los colores salen de su paleta.
 */
function GaleriaProductos({
  productos = [],
  titulo = 'Productos',
  colores,
  claseTarjeta = 'rounded-lg overflow-hidden',
  estiloTarjeta = {},
  claseImagen = 'w-full aspect-square object-cover',
}) {
  const { apiUrl } = useContext(AuthContext);
  const pista = useRef(null);
  const [actual, setActual] = useState(0);
  const [comprando, setComprando] = useState(null);

  // Adónde va el desplazamiento suave que pidieron las flechas, los puntos o
  // el teclado. Mientras anima, el scroll pasa por posiciones que redondean al
  // producto anterior: hasta que llega, manda el destino y no el scroll.
  const pendiente = useRef(null);
  const respaldo = useRef(null);

  const soltar = () => {
    pendiente.current = null;
    clearTimeout(respaldo.current);
  };

  const varios = productos.length > 1;

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
  }, [varios]);

  if (productos.length === 0) return null;

  const ultimo = productos.length - 1;
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

  const alApretarTecla = (e) => {
    if (e.key === 'ArrowRight') {
      e.preventDefault();
      ir(desde() + 1);
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      ir(desde() - 1);
    }
  };

  const tarjetas = productos.map((producto) => (
    <TarjetaProducto
      key={producto.id}
      producto={producto}
      colores={colores}
      claseTarjeta={claseTarjeta}
      estiloTarjeta={estiloTarjeta}
      claseImagen={claseImagen}
      onComprar={() => setComprando(producto)}
    />
  ));

  const botonFlecha = 'p-2 rounded-full transition disabled:opacity-30 disabled:cursor-default hover:opacity-80';
  const estiloFlecha = { backgroundColor: colores.tarjeta, color: colores.texto, border: `1px solid ${colores.bordeTarjeta}` };

  return (
    <>
      {varios ? (
        <section aria-roledescription="carrusel" aria-label={titulo} onKeyDown={alApretarTecla}>
          <div
            ref={pista}
            onScroll={alDesplazar}
            tabIndex={0}
            aria-label={`${titulo}: usá las flechas del teclado para recorrerlos`}
            className="flex overflow-x-auto snap-x snap-mandatory motion-safe:scroll-smooth focus:outline-none focus-visible:ring-2"
            style={{ scrollbarWidth: 'none' }}
          >
            {tarjetas.map((tarjeta, i) => (
              <div
                key={productos[i].id}
                role="group"
                aria-roledescription="producto"
                aria-label={`${i + 1} de ${productos.length}`}
                className="w-full shrink-0 snap-center px-1"
              >
                {tarjeta}
              </div>
            ))}
          </div>

          <div className="flex items-center justify-center gap-4 mt-4">
            <button
              type="button"
              onClick={() => ir(desde() - 1)}
              disabled={actual === 0}
              aria-label="Producto anterior"
              className={botonFlecha}
              style={estiloFlecha}
            >
              <ChevronLeft className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-2">
              {productos.map((p, i) => (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => ir(i)}
                  aria-label={`Ver producto ${i + 1} de ${productos.length}`}
                  aria-current={i === actual ? 'true' : undefined}
                  className="w-2.5 h-2.5 rounded-full transition"
                  style={{
                    backgroundColor: i === actual ? colores.acento : colores.bordeTarjeta || colores.texto,
                    opacity: i === actual ? 1 : 0.5,
                  }}
                />
              ))}
            </div>

            <button
              type="button"
              onClick={() => ir(desde() + 1)}
              disabled={actual === ultimo}
              aria-label="Producto siguiente"
              className={botonFlecha}
              style={estiloFlecha}
            >
              <ChevronRight className="w-5 h-5" />
            </button>
          </div>
        </section>
      ) : (
        tarjetas[0]
      )}

      {comprando && (
        <ComprarProducto
          producto={comprando}
          apiUrl={apiUrl}
          color={colores.boton}
          onCerrar={() => setComprando(null)}
        />
      )}
    </>
  );
}

/** Un producto: imagen, título, descripción, precio, archivo y el botón. */
function TarjetaProducto({ producto, colores, claseTarjeta, estiloTarjeta, claseImagen, onComprar }) {
  const pago = esPago(producto.precio);

  return (
    <article className={claseTarjeta} style={estiloTarjeta}>
      {producto.image_url && (
        <img src={producto.image_url} alt={producto.text} className={claseImagen} />
      )}

      <div className="p-5 space-y-3">
        <h3 className="font-bold text-xl">{producto.text}</h3>

        {producto.description && (
          <p className="opacity-80 whitespace-pre-line">{producto.description}</p>
        )}

        {producto.archivo_nombre && (
          <p className="text-sm opacity-60 flex items-center gap-1.5 min-w-0">
            <FileText className="w-4 h-4 shrink-0" />
            <span className="truncate">{producto.archivo_nombre}</span>
            {producto.archivo_bytes > 0 && (
              <span className="shrink-0">· {describirPeso(producto.archivo_bytes)}</span>
            )}
          </p>
        )}

        <div className="flex items-center justify-between gap-4 pt-1">
          <span className="text-2xl font-bold" style={{ color: colores.titulo }}>
            {describirPrecio(producto.precio)}
          </span>

          <button
            type="button"
            onClick={onComprar}
            className="inline-flex items-center gap-2 px-5 py-3 rounded-lg font-bold hover:opacity-90 transition"
            style={{ backgroundColor: colores.boton, color: textoSobre(colores.boton) }}
          >
            {pago ? <ShoppingBag className="w-4 h-4" /> : <Download className="w-4 h-4" />}
            {pago ? 'Comprar' : 'Descargar gratis'}
          </button>
        </div>
      </div>
    </article>
  );
}

export default GaleriaProductos;
