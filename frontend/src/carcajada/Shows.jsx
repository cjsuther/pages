import React, { useContext, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, Loader2, Users, Star } from 'lucide-react';
import { AuthContext } from '../App';
import { Boton, Campo, Selector, Tarjeta, Rotulo, Aviso, Cargando, Chip } from '../components/ui';
import { fechaLarga } from '../utils/carcajada';
import QrDelShow from './QrDelShow';

/**
 * Las fechas, para quien produce: las que vienen y las que ya pasaron.
 *
 * Un show pasado sin evaluar se marca, porque es lo que se olvida: la ficha
 * de cada comediante se arma con eso, y si nadie completa después del show no
 * hay con qué decidir la próxima fecha.
 */
function Shows() {
  const { token, apiUrl } = useContext(AuthContext);

  const [shows, setShows] = useState([]);
  const [ciclos, setCiclos] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [creando, setCreando] = useState(false);
  const [nuevo, setNuevo] = useState({ ciclo_id: '', fecha: '', hora: '', lugar: '' });
  const [guardando, setGuardando] = useState(false);

  const cabeceras = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const traer = async () => {
    try {
      const r = await fetch(`${apiUrl}/carcajada/shows.php`, { headers: cabeceras });
      const cuerpo = await r.json();

      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos cargar los shows');

      setShows(cuerpo.shows || []);
      setCiclos(cuerpo.ciclos || []);
      setNuevo((previo) => ({
        ...previo,
        ciclo_id: previo.ciclo_id || (cuerpo.ciclos && cuerpo.ciclos[0] ? cuerpo.ciclos[0].id : ''),
      }));
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
  }, []);

  const crear = async (e) => {
    e.preventDefault();
    setGuardando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/carcajada/shows.php`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify(nuevo),
      });
      const cuerpo = await r.json();

      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos crear el show');

      setCreando(false);
      setNuevo((previo) => ({ ...previo, fecha: '', hora: '', lugar: '' }));
      await traer();
    } catch (err) {
      setError(err.message);
    } finally {
      setGuardando(false);
    }
  };

  if (cargando) return <Cargando />;

  const hoy = new Date().toISOString().slice(0, 10);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <Rotulo>Producción</Rotulo>
          <h1 className="text-3xl font-bold tracking-tight text-tinta mt-1">Shows</h1>
        </div>
        <Boton onClick={() => setCreando((c) => !c)}>
          <Plus className="w-4 h-4" /> Nueva fecha
        </Boton>
      </div>

      {creando && (
        <Tarjeta className="p-6">
          <form onSubmit={crear} className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="show-ciclo" className="block text-sm font-semibold text-tinta mb-1.5">Ciclo</label>
              <Selector
                id="show-ciclo"
                value={nuevo.ciclo_id}
                onChange={(e) => setNuevo({ ...nuevo, ciclo_id: Number(e.target.value) })}
              >
                {ciclos.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
              </Selector>
            </div>
            <div>
              <label htmlFor="show-fecha" className="block text-sm font-semibold text-tinta mb-1.5">Fecha</label>
              <Campo
                id="show-fecha"
                type="date"
                value={nuevo.fecha}
                onChange={(e) => setNuevo({ ...nuevo, fecha: e.target.value })}
                required
              />
            </div>
            <div>
              <label htmlFor="show-hora" className="block text-sm font-semibold text-tinta mb-1.5">Hora</label>
              <Campo
                id="show-hora"
                type="time"
                value={nuevo.hora}
                onChange={(e) => setNuevo({ ...nuevo, hora: e.target.value })}
              />
            </div>
            <div>
              <label htmlFor="show-lugar" className="block text-sm font-semibold text-tinta mb-1.5">Lugar</label>
              <Campo
                id="show-lugar"
                value={nuevo.lugar}
                onChange={(e) => setNuevo({ ...nuevo, lugar: e.target.value })}
                placeholder="Humboldt 1574"
              />
            </div>
            <div className="sm:col-span-2">
              <Boton type="submit" disabled={guardando}>
                {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
                Crear fecha
              </Boton>
            </div>
          </form>
        </Tarjeta>
      )}

      {error && <Aviso tipo="error">{error}</Aviso>}

      <QrDelShow url={`${window.location.origin}/hoy`} />

      {shows.length === 0 ? (
        <Tarjeta className="p-6">
          <p className="text-tinta-media">Todavía no hay fechas cargadas.</p>
        </Tarjeta>
      ) : (
        <ul className="space-y-3">
          {shows.map((s) => {
            const paso = s.fecha < hoy;
            const faltaEvaluar = paso && s.comediantes > 0 && s.evaluados < s.comediantes;

            return (
              <li key={s.id}>
                <Link to={`/shows/${s.id}`} className="block">
                  <Tarjeta className="p-5 hover:border-borde-fuerte transition-colors">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <p className="font-bold text-tinta">{s.ciclo}</p>
                        <p className="text-sm text-tinta-media capitalize">{fechaLarga(s.fecha, s.hora)}</p>
                        {s.lugar && <p className="text-xs text-tinta-suave">{s.lugar}</p>}
                      </div>

                      <div className="flex items-center gap-2">
                        <Chip tono="neutro">
                          <Users className="w-3.5 h-3.5" /> {s.comediantes}
                        </Chip>
                        {/* Lo que se olvida es completar después del show, y sin
                            eso la ficha de cada uno queda sin datos. */}
                        {faltaEvaluar && (
                          <Chip tono="alerta">
                            <Star className="w-3.5 h-3.5" /> falta evaluar
                          </Chip>
                        )}
                      </div>
                    </div>
                  </Tarjeta>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

export default Shows;
