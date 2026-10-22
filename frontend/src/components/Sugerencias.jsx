import React, { useContext, useEffect, useId, useState } from 'react';
import { Link } from 'react-router';
import { Calendar, ShoppingBag } from 'lucide-react';
import { AuthContext } from '../App';
import { conAlfa } from '../utils/colores';
import { describirPrecio } from '../utils/productos';

const DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

/**
 * La fecha corta de una tarjeta chica: "vie 24/10".
 *
 * Se arma a mano desde el Y-m-d y no con new Date('2026-10-24'), que es
 * medianoche UTC: en Argentina ya es el día anterior y la tarjeta diría jueves.
 */
export function fechaCorta(fecha) {
  const partes = /^(\d{4})-(\d{2})-(\d{2})/.exec(fecha || '');
  if (!partes) return '';

  const [, anio, mes, dia] = partes;
  const diaDeLaSemana = new Date(Number(anio), Number(mes) - 1, Number(dia)).getDay();

  return `${DIAS[diaDeLaSemana]} ${dia}/${mes}`;
}

/** Lo que va debajo del título: la fecha si es evento, el precio si es producto. */
function detalleDe(item) {
  return item.tipo === 'producto' ? describirPrecio(item.precio) : fechaCorta(item.event_date);
}

/** Lleva arriba de todo: pasar de un detalle a otro no cambia de pantalla en el router. */
function subir() {
  if (typeof window !== 'undefined' && window.scrollTo) {
    try { window.scrollTo(0, 0); } catch (e) { /* jsdom */ }
  }
}

/**
 * "También te puede interesar": hasta tres eventos o productos chiquitos
 * debajo del detalle de un evento o un producto (#30).
 *
 * Qué sale y en qué orden lo decide la API (api/lib/Sugerencias.php). Acá
 * sólo se dibuja: una grilla de 3 columnas también en el celular, con la
 * imagen cuadrada, el título en dos líneas y la fecha o el precio.
 *
 * Mientras carga, si falla o si no hay ninguna, no se dibuja nada —ni el
 * título—: es un agregado al final del detalle y no puede mover ni ensuciar
 * lo de arriba.
 *
 * @param tipo    'evento' o 'producto': lo que se está viendo (X)
 * @param id      el id del link de X
 * @param pagina  la página donde se ve X, si es otra que la dueña
 * @param colores la paleta de la página (utils/colores), si la hay
 */
function Sugerencias({ tipo, id, pagina, colores, apiUrl: apiUrlPropia, className = '' }) {
  const contexto = useContext(AuthContext) || {};
  const apiUrl = apiUrlPropia || contexto.apiUrl;
  const [items, setItems] = useState([]);
  const tituloId = useId();

  useEffect(() => {
    setItems([]);
    if (!id) return undefined;

    let vigente = true;
    const query = new URLSearchParams({ tipo, id: String(id) });
    if (pagina) query.set('pagina', String(pagina));

    fetch(`${apiUrl}/public/sugerencias.php?${query}`)
      .then(async (r) => {
        const data = await r.json().catch(() => ({}));
        if (vigente && r.ok && Array.isArray(data.sugerencias)) {
          setItems(data.sugerencias.slice(0, 3));
        }
      })
      .catch(() => {
        // Sin sugerencias no se pierde nada: el detalle sigue entero.
      });

    return () => { vigente = false; };
  }, [apiUrl, tipo, id, pagina]);

  if (items.length === 0) return null;

  const borde = colores ? colores.bordeTarjeta : 'rgba(0, 0, 0, 0.1)';
  const relleno = (colores && conAlfa(colores.texto, 0.08)) || 'rgba(0, 0, 0, 0.05)';

  return (
    <section aria-labelledby={tituloId} className={`text-left ${className}`}>
      <h2 id={tituloId} className="text-sm font-semibold opacity-70 mb-3">
        También te puede interesar
      </h2>

      <ul className="grid grid-cols-3 gap-2 sm:gap-3">
        {items.map((item) => {
          const Icono = item.tipo === 'producto' ? ShoppingBag : Calendar;

          return (
            <li key={`${item.tipo}-${item.id}`} className="min-w-0">
              <Link
                to={`/${item.tipo}/${item.id}`}
                onClick={subir}
                className="block h-full rounded-lg border p-1.5 transition-opacity hover:opacity-80"
                style={{ borderColor: borde, color: 'inherit' }}
              >
                {item.image_url ? (
                  <img
                    src={item.image_url}
                    alt=""
                    loading="lazy"
                    className="w-full aspect-square object-cover rounded-md"
                  />
                ) : (
                  <div
                    className="w-full aspect-square rounded-md flex items-center justify-center"
                    style={{ backgroundColor: relleno }}
                    aria-hidden="true"
                  >
                    <Icono className="w-6 h-6 opacity-50" />
                  </div>
                )}

                <p className="mt-1.5 text-xs font-semibold leading-tight line-clamp-2 break-words">
                  {item.text}
                </p>
                <p className="mt-0.5 text-xs opacity-70">{detalleDe(item)}</p>
              </Link>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

export default Sugerencias;
