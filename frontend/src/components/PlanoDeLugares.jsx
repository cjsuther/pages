import React from 'react';
import {
  RADIO_BUTACA, huella, ladoDeMesa, lugaresDelElemento, describirLugar, alternarMesa,
} from '../utils/plano';

/** Píxeles por casillero como mínimo: por debajo, una butaca no se puede tocar con el dedo. */
const PIXELES_POR_CASILLERO = 26;

/** Y como máximo: en una pantalla ancha, un plano chico no tiene por qué ocuparla toda. */
const PIXELES_MAXIMOS_POR_CASILLERO = 34;

const COLORES = {
  libre: '#FFFFFF',
  trazo: '#4A4F45',
  ocupado: '#C8CEBF',
  mesa: '#F7F8F5',
  escenario: '#E2E6DC',
  texto: '#111311',
  textoSuave: '#6E7367',
};

/**
 * El plano como lo ve quien compra: toca butacas —o una mesa entera— para
 * elegir dónde sentarse.
 *
 * Es sólo la vista: lo elegido lo maneja quien lo usa, que es el que sabe el
 * máximo por compra y qué hacer con la elección.
 *
 * Sin `onCambiar` no hay nada para elegir y el plano queda para mirar: así lo
 * usa el link que muestra cómo viene la venta, donde tocar una butaca no
 * tendría ningún efecto y prometerlo sería mentir.
 */
function PlanoDeLugares({
  plano, ocupados = [], elegidos = [], onCambiar = null, maximo = Infinity, color = '#6FBE44',
}) {
  const soloMirar = typeof onCambiar !== 'function';

  const alternarLugar = (id) => {
    if (ocupados.includes(id)) return;

    if (elegidos.includes(id)) {
      onCambiar(elegidos.filter((e) => e !== id));
    } else if (elegidos.length < maximo) {
      onCambiar([...elegidos, id]);
    }
  };

  const alternarLaMesa = (elemento) => {
    onCambiar(alternarMesa(elemento, elegidos, ocupados, maximo));
  };

  return (
    <div>
      {/* El scroll horizontal es del plano y no de la página: en un teléfono
          un plano ancho se recorre con el dedo sin que se corra todo lo demás. */}
      <div className="overflow-x-auto border border-borde bg-white">
        <svg
          viewBox={`0 0 ${plano.ancho} ${plano.alto}`}
          width="100%"
          style={{
            minWidth: plano.ancho * PIXELES_POR_CASILLERO,
            maxWidth: plano.ancho * PIXELES_MAXIMOS_POR_CASILLERO,
            display: 'block',
            margin: '0 auto',
          }}
          role="group"
          aria-label="Plano del lugar"
        >
          {plano.elementos.map((elemento, i) => (
            <DibujoDelElemento
              // eslint-disable-next-line react/no-array-index-key
              key={i}
              elemento={elemento}
              ocupados={ocupados}
              elegidos={elegidos}
              color={color}
              onLugar={soloMirar ? null : alternarLugar}
              onMesa={soloMirar ? null : alternarLaMesa}
            />
          ))}
        </svg>
      </div>

      <Referencias color={color} soloMirar={soloMirar} />
    </div>
  );
}

/**
 * Un elemento del plano. Lo usan el comprador y el editor: si recibe onLugar
 * las butacas se pueden elegir; si no, son dibujo nomás.
 */
export function DibujoDelElemento({
  elemento, ocupados = [], elegidos = [], color = '#6FBE44', onLugar = null, onMesa = null,
}) {
  if (elemento.tipo === 'escenario') {
    const h = huella(elemento);
    return (
      <g>
        <rect x={h.x} y={h.y} width={h.ancho} height={h.alto} rx={0.2} fill={COLORES.escenario} />
        <text
          x={h.x + h.ancho / 2}
          y={h.y + h.alto / 2}
          textAnchor="middle"
          dominantBaseline="central"
          fontSize={0.55}
          fontWeight={700}
          fill={COLORES.textoSuave}
          style={{ textTransform: 'uppercase', letterSpacing: '0.08em' }}
        >
          {elemento.texto}
        </text>
      </g>
    );
  }

  const lugares = lugaresDelElemento(elemento);

  return (
    <g>
      {elemento.tipo === 'fila' ? (
        <text
          x={elemento.x + 0.5}
          y={elemento.y + 0.5}
          textAnchor="middle"
          dominantBaseline="central"
          fontSize={0.45}
          fontWeight={700}
          fill={COLORES.texto}
        >
          {elemento.nombre}
        </text>
      ) : (
        <Mesa elemento={elemento} onMesa={onMesa} />
      )}

      {lugares.map((lugar) => (
        <Butaca
          key={lugar.id}
          lugar={lugar}
          ocupado={ocupados.includes(lugar.id)}
          elegido={elegidos.includes(lugar.id)}
          color={color}
          onLugar={onLugar}
        />
      ))}
    </g>
  );
}

function Mesa({ elemento, onMesa }) {
  const lado = ladoDeMesa(elemento.lugares);
  const cx = elemento.x + lado / 2;
  const cy = elemento.y + lado / 2;
  const radio = lado / 2 - 0.45 - RADIO_BUTACA - 0.12;
  const interactiva = Boolean(onMesa);

  const props = {
    fill: COLORES.mesa,
    stroke: COLORES.trazo,
    strokeWidth: 0.05,
    style: interactiva ? { cursor: 'pointer' } : undefined,
    onClick: interactiva ? () => onMesa(elemento) : undefined,
  };

  return (
    <g>
      {elemento.forma === 'cuadrada' ? (
        <rect x={cx - radio} y={cy - radio} width={radio * 2} height={radio * 2} rx={0.1} {...props} />
      ) : (
        <circle cx={cx} cy={cy} r={radio} {...props} />
      )}
      <text
        x={cx}
        y={cy}
        textAnchor="middle"
        dominantBaseline="central"
        fontSize={0.42}
        fontWeight={700}
        fill={COLORES.texto}
        pointerEvents="none"
      >
        {elemento.nombre}
      </text>
    </g>
  );
}

function Butaca({ lugar, ocupado, elegido, color, onLugar }) {
  const interactiva = Boolean(onLugar) && !ocupado;

  let relleno = COLORES.libre;
  if (ocupado) relleno = COLORES.ocupado;
  if (elegido) relleno = color;

  const alternar = () => {
    if (interactiva) onLugar(lugar.id);
  };

  const accesible = onLugar
    ? {
      role: 'checkbox',
      'aria-checked': elegido,
      'aria-disabled': ocupado || undefined,
      'aria-label': `${describirLugar(lugar.id)}${ocupado ? ', ocupado' : ''}`,
      tabIndex: ocupado ? -1 : 0,
      onClick: alternar,
      onKeyDown: (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          alternar();
        }
      },
    }
    : {};

  return (
    <g
      {...accesible}
      className={onLugar ? 'plano-butaca' : undefined}
      style={{ cursor: interactiva ? 'pointer' : 'default' }}
    >
      <circle
        cx={lugar.cx}
        cy={lugar.cy}
        r={RADIO_BUTACA}
        fill={relleno}
        stroke={ocupado ? 'none' : COLORES.trazo}
        strokeWidth={elegido ? 0.08 : 0.05}
      />
      <text
        x={lugar.cx}
        y={lugar.cy}
        textAnchor="middle"
        dominantBaseline="central"
        fontSize={0.3}
        fill={ocupado ? COLORES.libre : (elegido ? COLORES.texto : COLORES.textoSuave)}
        fontWeight={elegido ? 700 : 400}
        pointerEvents="none"
      >
        {lugar.numero}
      </text>
    </g>
  );
}

function Referencias({ color, soloMirar = false }) {
  const items = [
    { etiqueta: soloMirar ? 'Sin vender' : 'Libre', fondo: COLORES.libre, borde: COLORES.trazo },
    // Sin nada para elegir, esa referencia no explicaría ningún color de la pantalla.
    ...(soloMirar ? [] : [{ etiqueta: 'Elegido', fondo: color, borde: COLORES.trazo }]),
    { etiqueta: soloMirar ? 'Vendido' : 'Ocupado', fondo: COLORES.ocupado, borde: COLORES.ocupado },
  ];

  return (
    <ul className="flex flex-wrap gap-4 mt-2 text-xs text-tinta-media">
      {items.map((item) => (
        <li key={item.etiqueta} className="flex items-center gap-1.5">
          <span
            className="inline-block w-3 h-3 rounded-full border"
            style={{ backgroundColor: item.fondo, borderColor: item.borde }}
          />
          {item.etiqueta}
        </li>
      ))}
    </ul>
  );
}

export default PlanoDeLugares;
