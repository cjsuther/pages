import React, { useState, useEffect, useContext } from 'react';
import { Bell, X } from 'lucide-react';
import { AuthContext } from '../App';
import { estaSuscrito } from '../utils/pushNotifications';
import ActivarNotificaciones from './ActivarNotificaciones';

/**
 * Invitación a activar las notificaciones, para la home.
 *
 * La guía de instalación vive en ActivarNotificaciones y no se duplica acá:
 * lo que agrega este componente es el lugar donde ofrecerla. Estaba sólo en
 * una pantalla interna, así que quien nunca entró ahí no se enteró nunca de
 * que podía recibir avisos.
 *
 * Desaparece cuando ya están activadas: un botón que ofrece lo que ya tenés
 * es ruido, y desactivarlas se hace desde la pantalla de páginas.
 */
function BotonNotificaciones() {
  const { token } = useContext(AuthContext);
  const [suscrito, setSuscrito] = useState(null);
  const [abierto, setAbierto] = useState(false);

  useEffect(() => {
    let vigente = true;

    // El estado se consulta al service worker, así que puede tardar: hasta
    // saberlo no se muestra nada, para no ofrecer y esconder a los dos
    // segundos.
    estaSuscrito().then((r) => {
      if (vigente) setSuscrito(r);
    });

    return () => {
      vigente = false;
    };
  }, [token]);

  if (!token || suscrito !== false) {
    return null;
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setAbierto(true)}
        className="inline-flex items-center gap-2 px-5 py-3 bg-white text-black font-bold hover:bg-gray-200 transition"
      >
        <Bell className="w-4 h-4" />
        ACTIVÁ LAS NOTIFICACIONES
      </button>

      {abierto && (
        <div
          className="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4"
          onClick={() => setAbierto(false)}
        >
          <div
            role="dialog"
            aria-label="Activar notificaciones"
            className="w-full max-w-md max-h-[90vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="bg-gray-900 border border-gray-800 p-6">
              <div className="flex justify-between items-start gap-4 mb-2">
                <h2 className="text-xl font-black tracking-tight">
                  ENTERATE CUANDO PUBLICAN UN EVENTO
                </h2>
                <button
                  type="button"
                  onClick={() => setAbierto(false)}
                  aria-label="Cerrar"
                  className="text-gray-500 hover:text-white transition shrink-0"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <p className="text-sm text-gray-400 mb-6">
                Te avisamos cuando una página que seguís publica un evento nuevo. Para que
                lleguen al teléfono hay que instalar Rezonar como app; son dos pasos y se
                hacen una sola vez.
              </p>

              <ActivarNotificaciones compacto />
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default BotonNotificaciones;
