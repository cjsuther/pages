import React, { useContext, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, Loader2, ExternalLink } from 'lucide-react';
import { AuthContext } from '../App';
import { Boton, Campo, AreaTexto, Tarjeta, Rotulo, Aviso, Cargando } from '../components/ui';
import { URL_REZONAR, urlDeRezonar } from '../utils/carcajada';

/** Hasta cuántos caracteres entra la descripción del material (Carcajada::LARGO_MATERIAL). */
const LARGO_MATERIAL = 2000;

/**
 * El alta de un comediante: lo que completa cuando quiere anotarse.
 *
 * Se entra con la cuenta de Rezonar, así que la página, la foto y el
 * Instagram ya se saben: se muestran cargados y sólo se corrige lo que haga
 * falta. Pedir de nuevo lo que alguien ya cargó es la forma más rápida de que
 * abandone el formulario.
 *
 * Sin página de Rezonar no se puede seguir, y no es un capricho: el QR del
 * show manda a esa página.
 */
function Alta() {
  const { token, apiUrl } = useContext(AuthContext);

  const [datos, setDatos] = useState(null);
  const [form, setForm] = useState({
    nombre: '', instagram: '', foto_url: '', personas_comprometidas: 10,
    estudio_con: '', egreso_anio: '', material: '',
  });
  const [cargando, setCargando] = useState(true);
  const [guardando, setGuardando] = useState(false);
  const [guardado, setGuardado] = useState(false);
  const [error, setError] = useState(null);

  const cabeceras = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/carcajada/comediante.php`, { headers: cabeceras });
        const cuerpo = await r.json();

        if (!vigente) return;
        if (!r.ok) throw new Error(cuerpo.error || 'No pudimos cargar tus datos');

        setDatos(cuerpo);

        const yaEsta = cuerpo.comediante;
        const sugerido = cuerpo.sugerencia;

        setForm({
          nombre: (yaEsta && yaEsta.nombre) || (sugerido && sugerido.titulo) || '',
          instagram: (yaEsta && yaEsta.instagram) || (sugerido && sugerido.instagram) || '',
          foto_url: (yaEsta && yaEsta.foto_url) || (sugerido && sugerido.foto_url) || '',
          personas_comprometidas: yaEsta ? yaEsta.comprometidas : 10,
          estudio_con: (yaEsta && yaEsta.estudio_con) || '',
          egreso_anio: yaEsta && yaEsta.egreso_anio ? String(yaEsta.egreso_anio) : '',
          material: (yaEsta && yaEsta.material) || '',
        });
      } catch (e) {
        if (vigente) setError(e.message);
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const cambiar = (campo, valor) => {
    setForm((previo) => ({ ...previo, [campo]: valor }));
    setGuardado(false);
    setError(null);
  };

  const guardar = async (e) => {
    e.preventDefault();
    setGuardando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/carcajada/comediante.php`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify({ ...form, page_id: datos.sugerencia.page_id }),
      });
      const cuerpo = await r.json();

      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos guardar');

      // Si era la primera vez corresponde celebrarlo, y eso hay que mirarlo
      // antes de guardar la respuesta: después ya figura anotado.
      setGuardado(datos.comediante ? 'cambios' : 'alta');
      setDatos((previo) => ({ ...previo, comediante: cuerpo.comediante }));
    } catch (err) {
      setError(err.message);
    } finally {
      setGuardando(false);
    }
  };

  if (cargando) {
    return <Cargando />;
  }

  // Sin página no se puede anotar: el QR del show manda ahí.
  if (!datos || !datos.sugerencia) {
    return (
      <Tarjeta className="p-6 sm:p-8">
        <h1 className="text-2xl font-bold text-tinta mb-2">Primero, tu página de Rezonar</h1>
        <p className="text-tinta-media mb-6">
          Para anotarte necesitás una página en Rezonar: es la que va a abrir el público
          cuando escanee el QR durante el show, con tus redes y tus próximas fechas.
        </p>
        <Boton href={`${URL_REZONAR}/my-pages`} target="_blank" rel="noreferrer">
          Crear mi página <ExternalLink className="w-4 h-4" />
        </Boton>
        <p className="text-sm text-tinta-suave mt-4">
          Cuando la tengas, volvé a esta pantalla y recargá.
        </p>
      </Tarjeta>
    );
  }

  const anotado = Boolean(datos.comediante);

  return (
    <div className="space-y-6">
      <div>
        <Rotulo>{anotado ? 'Tus datos' : 'Anotarme'}</Rotulo>
        <h1 className="text-3xl font-bold tracking-tight text-tinta mt-1">
          {anotado ? 'Tu ficha de comediante' : 'Anotate a los shows'}
        </h1>
        <p className="text-tinta-media mt-1">
          {anotado
            ? 'Esto es lo que ve la producción y lo que aparece en la pantalla del show.'
            : 'Con esto te sumamos a la lista para las próximas fechas.'}
        </p>
      </div>

      <Tarjeta className="p-6 sm:p-8">
        <form onSubmit={guardar} className="space-y-5">
          <div>
            <Rotulo>Tu página</Rotulo>
            <p className="text-tinta font-semibold mt-1">
              {datos.sugerencia.titulo}{' '}
              <a
                href={urlDeRezonar(datos.sugerencia.url_slug)}
                target="_blank"
                rel="noreferrer"
                className="text-verde-oscuro font-normal hover:underline"
              >
                rezon.ar/{datos.sugerencia.url_slug}
              </a>
            </p>
          </div>

          <div>
            <label htmlFor="carcajada-nombre" className="block text-sm font-semibold text-tinta mb-1.5">
              ¿Con qué nombre querés aparecer?
            </label>
            <Campo
              id="carcajada-nombre"
              value={form.nombre}
              onChange={(e) => cambiar('nombre', e.target.value)}
              placeholder="Como te anuncian arriba del escenario"
              required
            />
          </div>

          <div>
            <label htmlFor="carcajada-instagram" className="block text-sm font-semibold text-tinta mb-1.5">
              Instagram
            </label>
            <Campo
              id="carcajada-instagram"
              value={form.instagram}
              onChange={(e) => cambiar('instagram', e.target.value)}
              placeholder="@tucuenta"
            />
          </div>

          <div>
            <label htmlFor="carcajada-foto" className="block text-sm font-semibold text-tinta mb-1.5">
              Foto para los flyers
            </label>
            <div className="flex items-start gap-4">
              {form.foto_url && (
                <img
                  src={form.foto_url}
                  alt=""
                  className="w-20 h-20 rounded-xl object-cover border border-borde shrink-0"
                />
              )}
              <div className="flex-1 min-w-0">
                <Campo
                  id="carcajada-foto"
                  value={form.foto_url}
                  onChange={(e) => cambiar('foto_url', e.target.value)}
                  placeholder="https://..."
                />
                <p className="text-xs text-tinta-suave mt-1">
                  Viene la de tu página. Si querés otra, subila a tu página de Rezonar y pegá
                  acá su dirección.
                </p>
              </div>
            </div>
          </div>

          <div>
            <label htmlFor="carcajada-personas" className="block text-sm font-semibold text-tinta mb-1.5">
              ¿Cuánta gente te comprometés a traer cuando te presentás?
            </label>
            <Campo
              id="carcajada-personas"
              type="number"
              min="0"
              max="500"
              value={form.personas_comprometidas}
              onChange={(e) => cambiar('personas_comprometidas', Number(e.target.value))}
              className="max-w-[10rem]"
            />
            <p className="text-xs text-tinta-suave mt-1">
              Sé realista: después se compara con la que viene de verdad, y es lo que miramos
              para armar las fechas.
            </p>
          </div>

          {/* La formación y el material son para la producción, al armar las
              fechas: no salen en la pantalla del show. */}
          <div className="grid gap-5 sm:grid-cols-[1fr_10rem]">
            <div>
              <label htmlFor="carcajada-estudio" className="block text-sm font-semibold text-tinta mb-1.5">
                ¿Con quién estudiaste?
              </label>
              <Campo
                id="carcajada-estudio"
                value={form.estudio_con}
                onChange={(e) => cambiar('estudio_con', e.target.value)}
                placeholder="Escuela, taller o profe"
                maxLength={120}
              />
            </div>

            <div>
              <label htmlFor="carcajada-egreso" className="block text-sm font-semibold text-tinta mb-1.5">
                Año de egreso
              </label>
              <Campo
                id="carcajada-egreso"
                type="number"
                inputMode="numeric"
                min="1950"
                max={new Date().getFullYear()}
                value={form.egreso_anio}
                onChange={(e) => cambiar('egreso_anio', e.target.value)}
                placeholder="2020"
              />
            </div>
          </div>

          <div>
            <label htmlFor="carcajada-material" className="block text-sm font-semibold text-tinta mb-1.5">
              Contanos de qué va tu material
            </label>
            <AreaTexto
              id="carcajada-material"
              rows={5}
              value={form.material}
              onChange={(e) => cambiar('material', e.target.value)}
              placeholder="Temas, estilo, cuánto dura tu set, si es apto para todo público..."
              maxLength={LARGO_MATERIAL}
            />
            <p className="text-xs text-tinta-suave mt-1">
              Lo lee la producción para armar las fechas; no sale en la pantalla del show.
            </p>
          </div>

          {error && <Aviso tipo="error">{error}</Aviso>}

          <div className="flex items-center gap-4">
            <Boton type="submit" disabled={guardando}>
              {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
              {guardando ? 'Guardando...' : (anotado ? 'Guardar cambios' : 'Anotarme')}
            </Boton>

            {guardado && !guardando && (
              <span className="flex items-center gap-2 text-verde-oscuro text-sm font-medium">
                <Check className="w-4 h-4" />
                {guardado === 'alta' ? '¡Listo, quedaste anotado!' : 'Guardado'}
              </span>
            )}
          </div>
        </form>
      </Tarjeta>

      {datos.productor && (
        <p className="text-sm text-tinta-media">
          Producís Carcajada: <Link to="/shows" className="text-verde-oscuro font-semibold hover:underline">armar los shows</Link>.
        </p>
      )}
    </div>
  );
}

export default Alta;
