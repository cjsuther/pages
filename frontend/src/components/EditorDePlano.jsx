import React, { useRef, useState } from 'react';
import {
  Plus, Trash2, ArrowUp, ArrowDown, ArrowLeft, ArrowRight, LayoutGrid,
} from 'lucide-react';
import { DibujoDelElemento } from './PlanoDeLugares';
import {
  ANCHO_MAXIMO, ALTO_MAXIMO, MAX_BUTACAS_POR_FILA, MAX_LUGARES_POR_MESA, PATRON_NOMBRE,
  huella, lugaresDelElemento, lugaresDelPlano, lugaresRepetidos, resumirLugares,
  nuevoElemento, armarPlatea, redimensionar, dentroDelPlano,
} from '../utils/plano';
import { tipoDelElemento } from '../utils/tiposDeEntrada';

const PIXELES_POR_CASILLERO = 22;

const CLASE_CAMPO = 'w-full px-3 py-2 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none';

/**
 * Editor del plano de butacas y mesas de un evento.
 *
 * Se arrastra con el mouse o el dedo, y todo lo que se puede arrastrar también
 * se puede mover con las flechas del panel: en un teléfono arrastrar algo de
 * un casillero es difícil, y con teclado es imposible.
 *
 * Los lugares ya vendidos se ven ocupados. El servidor no deja sacarlos, y
 * acá se avisa antes para que no sea una sorpresa al guardar.
 *
 * Con varios tipos de entrada, cada fila o mesa elige de cuál son sus lugares.
 */
function EditorDePlano({ plano, onCambiar, ocupados = [], tipos = null }) {
  const [elegido, setElegido] = useState(null);
  const [platea, setPlatea] = useState({ filas: 8, butacas: 12 });
  const [armando, setArmando] = useState(false);
  const svgRef = useRef(null);
  const arrastre = useRef(null);

  const elemento = elegido !== null ? plano.elementos[elegido] : null;
  const repetidos = lugaresRepetidos(plano);
  const lugares = lugaresDelPlano(plano);
  const perdidos = ocupados.filter((id) => !lugares.includes(id));
  const conTipos = Boolean(tipos && tipos.length > 1);
  // Cuántos lugares quedaron de cada tipo: lo que se ve de un vistazo si
  // alguna zona quedó con el tipo equivocado.
  const lugaresPorTipo = conTipos
    ? tipos.map((t) => ({
      ...t,
      lugares: plano.elementos
        .filter((e) => tipoDelElemento(e, tipos) === t.id)
        .reduce((suma, e) => suma + lugaresDelElemento(e).length, 0),
    }))
    : [];

  const reemplazar = (indice, cambios) => {
    const elementos = plano.elementos.map((e, i) => (
      i === indice ? dentroDelPlano({ ...e, ...cambios }, plano) : e
    ));
    onCambiar({ ...plano, elementos });
  };

  const agregar = (tipo) => {
    const nuevo = nuevoElemento(tipo, plano);
    const h = huella(nuevo);

    // Si abajo ya no entra, el plano crece: agregar una fila no puede fallar
    // porque el lienzo quedó chico.
    const conLugar = h.y + h.alto > plano.alto || h.x + h.ancho > plano.ancho
      ? redimensionar(plano, Math.max(plano.ancho, h.x + h.ancho), Math.max(plano.alto, h.y + h.alto))
      : plano;

    onCambiar({ ...conLugar, elementos: [...conLugar.elementos, dentroDelPlano(nuevo, conLugar)] });
    setElegido(conLugar.elementos.length);
  };

  const borrar = () => {
    onCambiar({ ...plano, elementos: plano.elementos.filter((_, i) => i !== elegido) });
    setElegido(null);
  };

  const mover = (dx, dy) => {
    reemplazar(elegido, { x: elemento.x + dx, y: elemento.y + dy });
  };

  const armar = () => {
    onCambiar(armarPlatea(platea));
    setElegido(null);
    setArmando(false);
  };

  // ------------------------------------------------------------- arrastre

  /** La posición del puntero en casilleros del plano. */
  const enCasilleros = (evento) => {
    const svg = svgRef.current;
    const matriz = svg && svg.getScreenCTM ? svg.getScreenCTM() : null;

    if (!matriz) return null;

    const punto = svg.createSVGPoint();
    punto.x = evento.clientX;
    punto.y = evento.clientY;
    const p = punto.matrixTransform(matriz.inverse());

    return { x: p.x, y: p.y };
  };

  const empezarArrastre = (indice) => (evento) => {
    setElegido(indice);
    const inicio = enCasilleros(evento);

    if (!inicio) return;

    evento.preventDefault();
    if (svgRef.current.setPointerCapture) svgRef.current.setPointerCapture(evento.pointerId);

    arrastre.current = {
      indice,
      inicio,
      origen: { x: plano.elementos[indice].x, y: plano.elementos[indice].y },
    };
  };

  const arrastrar = (evento) => {
    const actual = arrastre.current;
    if (!actual) return;

    const p = enCasilleros(evento);
    if (!p) return;

    const x = Math.round(actual.origen.x + p.x - actual.inicio.x);
    const y = Math.round(actual.origen.y + p.y - actual.inicio.y);
    const previo = plano.elementos[actual.indice];

    if (x !== previo.x || y !== previo.y) {
      reemplazar(actual.indice, { x, y });
    }
  };

  const soltar = () => {
    arrastre.current = null;
  };

  // ---------------------------------------------------------------- vista

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <BotonDeHerramienta onClick={() => agregar('fila')}><Plus className="w-4 h-4" /> Fila</BotonDeHerramienta>
        <BotonDeHerramienta onClick={() => agregar('mesa')}><Plus className="w-4 h-4" /> Mesa</BotonDeHerramienta>
        <BotonDeHerramienta onClick={() => agregar('escenario')}><Plus className="w-4 h-4" /> Escenario</BotonDeHerramienta>
        <BotonDeHerramienta onClick={() => setArmando((a) => !a)} activo={armando}>
          <LayoutGrid className="w-4 h-4" /> Armar platea
        </BotonDeHerramienta>
      </div>

      {armando && (
        <div className="border border-borde bg-papel-hueso p-4 space-y-3">
          <p className="text-sm text-tinta-media">
            Arma un escenario y filas iguales de una. Reemplaza lo que hay en el plano.
          </p>
          <div className="grid grid-cols-2 gap-3">
            <CampoNumero
              id="platea-filas"
              etiqueta="Filas"
              min={1}
              max={26}
              value={platea.filas}
              onChange={(v) => setPlatea((p) => ({ ...p, filas: v }))}
            />
            <CampoNumero
              id="platea-butacas"
              etiqueta="Butacas por fila"
              min={1}
              max={MAX_BUTACAS_POR_FILA}
              value={platea.butacas}
              onChange={(v) => setPlatea((p) => ({ ...p, butacas: v }))}
            />
          </div>
          <button
            type="button"
            onClick={armar}
            disabled={ocupados.length > 0}
            className="rounded-full bg-tinta text-white px-4 py-2 text-sm font-semibold disabled:opacity-50"
          >
            Armar {Number(platea.filas) * Number(platea.butacas)} butacas
          </button>
          {ocupados.length > 0 && (
            <p className="text-xs text-tinta-suave">
              No se puede rearmar el plano entero: ya hay lugares vendidos.
            </p>
          )}
        </div>
      )}

      <div className="overflow-x-auto border border-borde bg-white">
        <svg
          ref={svgRef}
          viewBox={`0 0 ${plano.ancho} ${plano.alto}`}
          width="100%"
          style={{ minWidth: plano.ancho * PIXELES_POR_CASILLERO, display: 'block', touchAction: 'none' }}
          onPointerMove={arrastrar}
          onPointerUp={soltar}
          onPointerCancel={soltar}
          aria-label="Plano del evento"
          role="img"
        >
          <Cuadricula ancho={plano.ancho} alto={plano.alto} onVacio={() => setElegido(null)} />

          {plano.elementos.map((e, i) => {
            const h = huella(e);
            return (
              <g
                // eslint-disable-next-line react/no-array-index-key
                key={i}
                onPointerDown={empezarArrastre(i)}
                style={{ cursor: 'move' }}
                data-testid={`elemento-${i}`}
              >
                <rect x={h.x} y={h.y} width={h.ancho} height={h.alto} fill="transparent" />
                <DibujoDelElemento elemento={e} ocupados={ocupados} />
                {elegido === i && (
                  <rect
                    x={h.x - 0.1}
                    y={h.y - 0.1}
                    width={h.ancho + 0.2}
                    height={h.alto + 0.2}
                    fill="none"
                    stroke="#3E7C1E"
                    strokeWidth={0.08}
                    strokeDasharray="0.25 0.15"
                    pointerEvents="none"
                  />
                )}
              </g>
            );
          })}
        </svg>
      </div>

      <p className="text-sm text-tinta-media">
        <strong className="text-tinta">{lugares.length}</strong> lugares en el plano.
        {' '}Tocá una fila o una mesa para editarla, y arrastrala para moverla.
      </p>

      {conTipos && (
        <p className="text-sm text-tinta-media">
          {lugaresPorTipo.map((t) => `${t.nombre || 'Sin nombre'}: ${t.lugares}`).join(' · ')}
        </p>
      )}

      {repetidos.length > 0 && (
        <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">
          Hay lugares repetidos: {resumirLugares(repetidos.slice(0, 8))}. Cambiá el nombre de
          la fila o desde qué número empieza.
        </p>
      )}

      {perdidos.length > 0 && (
        <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">
          Estos lugares ya están vendidos y no pueden salir del plano: {resumirLugares(perdidos.slice(0, 8))}.
        </p>
      )}

      {elemento && (
        <PanelDelElemento
          elemento={elemento}
          tipos={conTipos ? tipos : null}
          vendidos={lugaresDelElemento(elemento).filter((l) => ocupados.includes(l.id)).length}
          onCambiar={(cambios) => reemplazar(elegido, cambios)}
          onMover={mover}
          onBorrar={borrar}
        />
      )}

      <details className="text-sm">
        <summary className="cursor-pointer text-tinta-media">Tamaño del plano</summary>
        <div className="grid grid-cols-2 gap-3 mt-3">
          <CampoNumero
            id="plano-ancho"
            etiqueta="Ancho (casilleros)"
            min={4}
            max={ANCHO_MAXIMO}
            value={plano.ancho}
            onChange={(v) => onCambiar(redimensionar(plano, v, plano.alto))}
          />
          <CampoNumero
            id="plano-alto"
            etiqueta="Alto (casilleros)"
            min={4}
            max={ALTO_MAXIMO}
            value={plano.alto}
            onChange={(v) => onCambiar(redimensionar(plano, plano.ancho, v))}
          />
        </div>
      </details>
    </div>
  );
}

function PanelDelElemento({ elemento, tipos, vendidos, onCambiar, onMover, onBorrar }) {
  const titulo = {
    fila: `Fila ${elemento.nombre}`,
    mesa: `Mesa ${elemento.nombre}`,
    escenario: elemento.texto,
  }[elemento.tipo];

  const nombreInvalido = elemento.tipo !== 'escenario' && !PATRON_NOMBRE.test(elemento.nombre);

  return (
    <div className="border border-verde bg-verde-claro p-4 space-y-3">
      <div className="flex items-center justify-between gap-2">
        <p className="font-bold text-tinta">{titulo}</p>
        <div className="flex items-center gap-1">
          <BotonMover etiqueta="Mover a la izquierda" onClick={() => onMover(-1, 0)}><ArrowLeft className="w-4 h-4" /></BotonMover>
          <BotonMover etiqueta="Mover arriba" onClick={() => onMover(0, -1)}><ArrowUp className="w-4 h-4" /></BotonMover>
          <BotonMover etiqueta="Mover abajo" onClick={() => onMover(0, 1)}><ArrowDown className="w-4 h-4" /></BotonMover>
          <BotonMover etiqueta="Mover a la derecha" onClick={() => onMover(1, 0)}><ArrowRight className="w-4 h-4" /></BotonMover>
        </div>
      </div>

      {elemento.tipo === 'fila' && (
        <div className="grid grid-cols-3 gap-3">
          <CampoTexto id="elemento-nombre" etiqueta="Fila" value={elemento.nombre} onChange={(v) => onCambiar({ nombre: v.toUpperCase() })} />
          <CampoNumero id="elemento-desde" etiqueta="Desde el n°" min={1} max={999} value={elemento.desde} onChange={(v) => onCambiar({ desde: v })} />
          <CampoNumero id="elemento-butacas" etiqueta="Butacas" min={1} max={MAX_BUTACAS_POR_FILA} value={elemento.butacas} onChange={(v) => onCambiar({ butacas: v })} />
        </div>
      )}

      {elemento.tipo === 'mesa' && (
        <div className="grid grid-cols-3 gap-3">
          <CampoTexto id="elemento-nombre" etiqueta="Mesa" value={elemento.nombre} onChange={(v) => onCambiar({ nombre: v })} />
          <CampoNumero id="elemento-lugares" etiqueta="Lugares" min={1} max={MAX_LUGARES_POR_MESA} value={elemento.lugares} onChange={(v) => onCambiar({ lugares: v })} />
          <div>
            <label htmlFor="elemento-forma" className="block text-xs font-semibold text-tinta mb-1">Forma</label>
            <select id="elemento-forma" value={elemento.forma} onChange={(e) => onCambiar({ forma: e.target.value })} className={CLASE_CAMPO}>
              <option value="redonda">Redonda</option>
              <option value="cuadrada">Cuadrada</option>
            </select>
          </div>
        </div>
      )}

      {elemento.tipo === 'escenario' && (
        <div className="grid grid-cols-3 gap-3">
          <CampoTexto id="elemento-texto" etiqueta="Texto" value={elemento.texto} onChange={(v) => onCambiar({ texto: v.slice(0, 40) })} />
          <CampoNumero id="elemento-ancho" etiqueta="Ancho" min={1} max={ANCHO_MAXIMO} value={elemento.ancho} onChange={(v) => onCambiar({ ancho: v })} />
          <CampoNumero id="elemento-alto" etiqueta="Alto" min={1} max={ALTO_MAXIMO} value={elemento.alto} onChange={(v) => onCambiar({ alto: v })} />
        </div>
      )}

      {tipos && elemento.tipo !== 'escenario' && (
        <div>
          <label htmlFor="elemento-entrada" className="block text-xs font-semibold text-tinta mb-1">Tipo de entrada</label>
          <select
            id="elemento-entrada"
            value={tipoDelElemento(elemento, tipos)}
            onChange={(e) => onCambiar({ entrada: e.target.value })}
            className={CLASE_CAMPO}
          >
            {tipos.map((t) => (
              <option key={t.id} value={t.id}>{t.nombre || 'Sin nombre'}</option>
            ))}
          </select>
          {vendidos > 0 && (
            <p className="text-xs text-tinta-suave mt-1">
              Lo ya vendido no cambia de precio: el tipo nuevo vale para lo que se venda de acá en más.
            </p>
          )}
        </div>
      )}

      {elemento.tipo === 'fila' && (
        <p className="text-xs text-tinta-suave">
          Para dejar un pasillo, cortá la fila en dos: otra fila con la misma letra que
          empiece desde el número que sigue.
        </p>
      )}

      {nombreInvalido && (
        <p className="text-xs text-red-700">El nombre tiene que ser de 1 a 6 letras o números, sin espacios.</p>
      )}

      <div className="flex items-center justify-between gap-3">
        {vendidos > 0 ? (
          <p className="text-xs text-tinta-suave">
            Tiene {vendidos} {vendidos === 1 ? 'lugar vendido' : 'lugares vendidos'}: no se puede borrar.
          </p>
        ) : <span />}
        <button
          type="button"
          onClick={onBorrar}
          disabled={vendidos > 0}
          className="inline-flex items-center gap-1.5 text-sm font-semibold text-red-700 disabled:opacity-40"
        >
          <Trash2 className="w-4 h-4" /> Borrar
        </button>
      </div>
    </div>
  );
}

function Cuadricula({ ancho, alto, onVacio }) {
  const lineas = [];

  for (let x = 1; x < ancho; x += 1) {
    lineas.push(<line key={`v${x}`} x1={x} y1={0} x2={x} y2={alto} />);
  }
  for (let y = 1; y < alto; y += 1) {
    lineas.push(<line key={`h${y}`} x1={0} y1={y} x2={ancho} y2={y} />);
  }

  return (
    <g>
      <rect x={0} y={0} width={ancho} height={alto} fill="#FFFFFF" onPointerDown={onVacio} />
      <g stroke="#EDF0E8" strokeWidth={0.03} pointerEvents="none">{lineas}</g>
    </g>
  );
}

function BotonDeHerramienta({ children, onClick, activo = false }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 border px-3 py-2 text-sm font-semibold transition ${
        activo ? 'border-verde bg-verde-claro text-tinta' : 'border-borde-fuerte text-tinta hover:border-tinta'
      }`}
    >
      {children}
    </button>
  );
}

function BotonMover({ children, etiqueta, onClick }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={etiqueta}
      title={etiqueta}
      className="p-1.5 border border-borde-fuerte bg-white text-tinta hover:border-tinta"
    >
      {children}
    </button>
  );
}

function CampoNumero({ id, etiqueta, value, onChange, min, max }) {
  return (
    <div>
      <label htmlFor={id} className="block text-xs font-semibold text-tinta mb-1">{etiqueta}</label>
      <input
        id={id}
        type="number"
        min={min}
        max={max}
        value={value}
        onChange={(e) => {
          const n = Number(e.target.value);
          if (e.target.value !== '' && !Number.isNaN(n)) onChange(Math.max(min, Math.min(max, Math.round(n))));
        }}
        className={CLASE_CAMPO}
      />
    </div>
  );
}

function CampoTexto({ id, etiqueta, value, onChange }) {
  return (
    <div>
      <label htmlFor={id} className="block text-xs font-semibold text-tinta mb-1">{etiqueta}</label>
      <input id={id} type="text" value={value} onChange={(e) => onChange(e.target.value)} className={CLASE_CAMPO} />
    </div>
  );
}

export default EditorDePlano;
