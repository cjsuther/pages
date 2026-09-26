import React, { useContext, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Plus, X, Loader2, Check } from 'lucide-react';
import { AuthContext } from '../App';
import { Boton, Campo, Selector, Tarjeta, Rotulo, Aviso, Cargando, Chip } from '../components/ui';
import { fechaLarga, cumplimiento } from '../utils/carcajada';

/**
 * Un show: quiénes se presentan y, después, cómo les fue.
 *
 * Las dos cosas viven en la misma pantalla porque son el mismo momento visto
 * dos veces: antes del show se arma la lista, después se completa sobre esa
 * misma lista. Separarlas obligaría a buscar de nuevo la fecha y a acordarse
 * de quién había tocado.
 */
function Show() {
  const { id } = useParams();
  const { token, apiUrl } = useContext(AuthContext);

  const [show, setShow] = useState(null);
  const [comediantes, setComediantes] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [aSumar, setASumar] = useState('');

  const cabeceras = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const traer = async () => {
    try {
      const r = await fetch(`${apiUrl}/carcajada/show.php?id=${id}`, { headers: cabeceras });
      const cuerpo = await r.json();

      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos cargar el show');

      setShow(cuerpo.show);
      setComediantes(cuerpo.comediantes || []);
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    traer();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const accion = async (cuerpo) => {
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/carcajada/show.php?id=${id}`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify(cuerpo),
      });
      const respuesta = await r.json();

      if (!r.ok) throw new Error(respuesta.error || 'No se pudo completar');

      setShow(respuesta.show);
    } catch (e) {
      setError(e.message);
    }
  };

  if (cargando) return <Cargando />;

  if (!show) {
    return <Aviso tipo="error">{error || 'Ese show no existe'}</Aviso>;
  }

  const enElShow = show.lineup.map((l) => l.comediante_id);
  const disponibles = comediantes.filter((c) => !enElShow.includes(c.id));
  const paso = show.fecha < new Date().toISOString().slice(0, 10);

  return (
    <div className="space-y-6">
      <Link to="/shows" className="inline-flex items-center gap-1.5 text-sm text-tinta-media hover:text-tinta">
        <ArrowLeft className="w-4 h-4" /> Volver a los shows
      </Link>

      <div>
        <Rotulo>{show.ciclo}</Rotulo>
        <h1 className="text-3xl font-bold tracking-tight text-tinta mt-1 capitalize">
          {fechaLarga(show.fecha, show.hora)}
        </h1>
        {show.lugar && <p className="text-tinta-media">{show.lugar}</p>}
      </div>

      {error && <Aviso tipo="error">{error}</Aviso>}

      <Tarjeta className="p-6">
        <h2 className="font-bold text-tinta mb-4">Line-up</h2>

        {show.lineup.length === 0 ? (
          <p className="text-tinta-media text-sm mb-4">Todavía no hay nadie en esta fecha.</p>
        ) : (
          <ul className="divide-y divide-borde mb-5">
            {show.lineup.map((l, i) => (
              <EnElLineup
                key={l.id}
                lugar={i + 1}
                linea={l}
                paso={paso}
                onEvaluar={(datos) => accion({ accion: 'evaluar', comediante_id: l.comediante_id, ...datos })}
                onSacar={() => accion({ accion: 'sacar', comediante_id: l.comediante_id })}
              />
            ))}
          </ul>
        )}

        {disponibles.length > 0 && (
          <div className="flex flex-wrap gap-3 items-end">
            <div className="flex-1 min-w-[14rem]">
              <label htmlFor="sumar-comediante" className="block text-sm font-semibold text-tinta mb-1.5">
                Sumar comediante
              </label>
              <Selector id="sumar-comediante" value={aSumar} onChange={(e) => setASumar(e.target.value)}>
                <option value="">Elegir...</option>
                {disponibles.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.nombre}{c.comprometidas ? ` · trae ${c.comprometidas}` : ''}
                  </option>
                ))}
              </Selector>
            </div>
            <Boton
              disabled={!aSumar}
              onClick={() => {
                accion({ accion: 'sumar', comediante_id: Number(aSumar) });
                setASumar('');
              }}
            >
              <Plus className="w-4 h-4" /> Sumar
            </Boton>
          </div>
        )}
      </Tarjeta>
    </div>
  );
}

/**
 * Una persona en el line-up.
 *
 * Después de la fecha aparecen el puntaje y la gente que trajo, al lado de la
 * que había prometido: esa comparación es la que sirve para armar la próxima.
 */
function EnElLineup({ lugar, linea, paso, onEvaluar, onSacar }) {
  const [form, setForm] = useState({
    puntaje: linea.puntaje === null ? '' : String(linea.puntaje),
    personas_traidas: linea.personas_traidas === null ? '' : String(linea.personas_traidas),
    comentario: linea.comentario || '',
  });
  const [guardando, setGuardando] = useState(false);
  const [guardado, setGuardado] = useState(false);

  const guardar = async () => {
    setGuardando(true);
    setGuardado(false);
    await onEvaluar(form);
    setGuardando(false);
    setGuardado(true);
  };

  return (
    <li className="py-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-3 min-w-0">
          <span className="text-sm font-bold text-tinta-suave w-5 shrink-0">{lugar}</span>
          <div className="min-w-0">
            <p className="font-semibold text-tinta truncate">{linea.nombre}</p>
            <p className="text-xs text-tinta-suave">
              Se compromete a traer {linea.comprometidas}
              {linea.personas_traidas !== null && ` · trajo ${linea.personas_traidas}`}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 shrink-0">
          {linea.puntaje !== null && <Chip tono="verde">{linea.puntaje}/5</Chip>}
          <button
            type="button"
            onClick={onSacar}
            aria-label={`Sacar a ${linea.nombre} del line-up`}
            className="text-tinta-suave hover:text-red-700 p-1"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      </div>

      {paso && (
        <div className="mt-3 pl-8 grid gap-3 sm:grid-cols-[6rem_8rem_1fr_auto] sm:items-end">
          <div>
            <label htmlFor={`puntaje-${linea.id}`} className="block text-xs font-semibold text-tinta mb-1">
              Puntaje
            </label>
            <Selector
              id={`puntaje-${linea.id}`}
              value={form.puntaje}
              onChange={(e) => setForm({ ...form, puntaje: e.target.value })}
            >
              <option value="">–</option>
              {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
            </Selector>
          </div>

          <div>
            <label htmlFor={`trajo-${linea.id}`} className="block text-xs font-semibold text-tinta mb-1">
              Trajo
            </label>
            <Campo
              id={`trajo-${linea.id}`}
              type="number"
              min="0"
              value={form.personas_traidas}
              onChange={(e) => setForm({ ...form, personas_traidas: e.target.value })}
            />
          </div>

          <div>
            <label htmlFor={`comentario-${linea.id}`} className="block text-xs font-semibold text-tinta mb-1">
              Comentario
            </label>
            <Campo
              id={`comentario-${linea.id}`}
              value={form.comentario}
              onChange={(e) => setForm({ ...form, comentario: e.target.value })}
              placeholder="Cómo estuvo"
            />
          </div>

          <Boton variante="secundario" tamano="sm" onClick={guardar} disabled={guardando}>
            {guardando && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
            {guardado && !guardando ? <Check className="w-3.5 h-3.5" /> : null}
            Guardar
          </Boton>
        </div>
      )}
    </li>
  );
}

export default Show;
