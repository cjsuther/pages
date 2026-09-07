import React, { useState, useEffect, useContext, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, Compass, Globe2, AlertTriangle } from 'lucide-react';
import { AuthContext } from '../App';
import ActivarNotificaciones from './ActivarNotificaciones';
import LocationSettings from './LocationSettings';
import { Aviso, Cargando, Rotulo, Tarjeta } from './ui';
import { diagnosticar, PASOS } from '../utils/pwa';
import { handleApiResponse } from '../utils/apiHandler';

/**
 * Todo lo que hace que un aviso llegue, en una sola pantalla.
 *
 * Son tres cosas separadas que antes vivían en tres lugares distintos: el
 * permiso del teléfono, la ubicación —sin la cual "solo eventos cercanos" no
 * puede decidir nada— y qué pidió recibir de cada página. Repartidas, era
 * imposible entender por qué no llegaba un aviso; juntas, el hueco se ve.
 */
function PanelAlertas() {
  const { token, apiUrl, logout } = useContext(AuthContext);
  const navigate = useNavigate();

  const [following, setFollowing] = useState([]);
  const [ubicacion, setUbicacion] = useState(null);
  const [cargando, setCargando] = useState(true);

  // En una computadora las notificaciones no se llegan a activar, y
  // ActivarNotificaciones no dibuja nada. Sin esto el "Paso 1" quedaba como un
  // título con la nada abajo, que se lee como que algo se rompió.
  const soloEnElTelefono = diagnosticar().paso === PASOS.SOLO_MOVIL;

  useEffect(() => {
    Promise.all([cargarSeguidas(), cargarUbicacion()]).finally(() => setCargando(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const cargarSeguidas = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/following.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setFollowing(data.following || []);
    } catch (err) {
      console.error('Error fetching following:', err);
    }
  };

  const cargarUbicacion = async () => {
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      await handleApiResponse(response, navigate, logout);
      const data = await response.json();
      if (data.latitude && data.longitude) {
        setUbicacion(data);
      }
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        console.error('Error loading location:', err);
      }
    }
  };

  const resumen = useMemo(() => {
    const todas = following.filter(p => p.notify_all_events).length;

    return { todas, cerca: following.length - todas, total: following.length };
  }, [following]);

  // El agujero silencioso: pediste "solo lo que esté cerca" y no hay un "cerca"
  // con el que comparar. Sin este aviso, esas páginas simplemente no notifican
  // nunca y no hay forma de darse cuenta.
  const faltaUbicacion = resumen.cerca > 0 && !ubicacion;

  if (cargando) {
    return <Cargando texto="Revisando tus alertas..." />;
  }

  return (
    <div className="space-y-6">
      {faltaUbicacion && (
        <Aviso tipo="atencion">
          <span className="flex items-start gap-2">
            <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span>
              <strong className="font-semibold">
                {resumen.cerca} {resumen.cerca === 1 ? 'página está configurada' : 'páginas están configuradas'} para
                avisarte solo si la fecha es cerca
              </strong>, pero no tenemos tu ubicación. Hasta que la cargues, esos avisos no
              se pueden mandar.
            </span>
          </span>
        </Aviso>
      )}

      {/* ------------------------------------------------ paso 1: el teléfono */}

      <section>
        <div className="flex items-baseline gap-3 mb-3">
          <Rotulo>Paso 1</Rotulo>
          <h3 className="text-lg font-bold text-tinta">Permiso del teléfono</h3>
        </div>
        <p className="text-tinta-media mb-4 max-w-2xl">
          Sin esto no llega ningún aviso. Se activa una vez por dispositivo.
        </p>
        {soloEnElTelefono ? (
          <Aviso>
            Los avisos llegan al teléfono, así que se activan desde ahí. Abrí
            rezon.ar en tu celular, entrá a esta misma pantalla y vas a ver el botón.
          </Aviso>
        ) : (
          <ActivarNotificaciones />
        )}
      </section>

      {/* ----------------------------------------------- paso 2: la ubicación */}

      <section>
        <div className="flex items-baseline gap-3 mb-3">
          <Rotulo>Paso 2</Rotulo>
          <h3 className="text-lg font-bold text-tinta">Dónde estás</h3>
        </div>
        <p className="text-tinta-media mb-4 max-w-2xl">
          Hace falta solo si elegiste enterarte únicamente de las fechas cercanas. Es un
          punto de referencia: no se comparte con las páginas que seguís.
        </p>
        <LocationSettings alGuardar={cargarUbicacion} />
      </section>

      {/* --------------------------------------------- paso 3: qué pediste */}

      <section>
        <div className="flex items-baseline gap-3 mb-3">
          <Rotulo>Paso 3</Rotulo>
          <h3 className="text-lg font-bold text-tinta">Qué pediste recibir</h3>
        </div>
        <p className="text-tinta-media mb-4 max-w-2xl">
          Se elige por página, en la pestaña <strong className="font-semibold text-tinta">Las que sigo</strong>.
        </p>

        {resumen.total === 0 ? (
          <Tarjeta className="p-6">
            <p className="text-tinta-media">
              Todavía no seguís ninguna página, así que no hay avisos para configurar.
            </p>
          </Tarjeta>
        ) : (
          <div className="grid sm:grid-cols-3 gap-4">
            {[
              { icono: Bell, valor: resumen.total, texto: resumen.total === 1 ? 'página seguida' : 'páginas seguidas', destacada: true },
              { icono: Globe2, valor: resumen.todas, texto: 'te avisan de todo' },
              { icono: Compass, valor: resumen.cerca, texto: 'solo si es cerca' },
            ].map(({ icono: Icono, valor, texto, destacada }) => (
              <Tarjeta key={texto} destacada={destacada} className="p-5">
                <Icono className={`w-5 h-5 mb-3 ${destacada ? 'text-verde-oscuro' : 'text-tinta-suave'}`} />
                <p className="text-3xl font-bold text-tinta tabular-nums leading-none">{valor}</p>
                <p className="text-sm text-tinta-media mt-1.5">{texto}</p>
              </Tarjeta>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

export default PanelAlertas;
