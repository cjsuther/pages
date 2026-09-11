import React, { useState, useEffect, useContext } from 'react';
import { Bell } from 'lucide-react';
import { AuthContext } from '../App';
import { estaSuscrito } from '../utils/pushNotifications';
import { diagnosticar, PASOS } from '../utils/pwa';
import ActivarNotificaciones from './ActivarNotificaciones';

/**
 * La invitación a activar las notificaciones, para la home.
 *
 * Antes era un botón que abría un popup con la explicación adentro. El botón
 * quedaba a mitad de la home, que en un teléfono son varias pantallas de
 * scroll, y lo que había que hacer para que llegara un aviso sólo se leía
 * después de tocarlo. Ofrecer algo así es no ofrecerlo.
 *
 * Ahora la explicación está a la vista y el paso que toca hacer también. La
 * guía no se duplica acá: vive en ActivarNotificaciones, que es la que sabe
 * qué corresponde en cada teléfono —agregar a inicio en un iPhone, instalar en
 * un Android, habilitar a mano si el permiso está bloqueado—.
 *
 * Desaparece cuando ya están activadas: ofrecer lo que ya tenés es ruido, y
 * desactivarlas se hace desde la pantalla de páginas. Y desaparece en una
 * computadora, donde no se llegan a activar nunca.
 */
function AvisoNotificaciones() {
  const { token } = useContext(AuthContext);
  const [suscrito, setSuscrito] = useState(null);

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

  if (!token || suscrito !== false || diagnosticar().paso === PASOS.SOLO_MOVIL) {
    return null;
  }

  return (
    <div className="rounded-2xl border border-verde-medio bg-verde-claro p-5 sm:p-6">
      <div className="flex items-start gap-3">
        <span className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-verde-medio bg-white text-verde-oscuro">
          <Bell className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <h2 className="text-lg font-bold tracking-tight text-tinta">
            Que te avisemos al teléfono
          </h2>
          <p className="mt-1 text-sm leading-relaxed text-tinta-media">
            Cuando una página que seguís publica una fecha nueva, te llega un aviso. Se
            configura una sola vez y son los pasos de acá abajo.
          </p>
        </div>
      </div>

      <div className="mt-5">
        <ActivarNotificaciones compacto />
      </div>
    </div>
  );
}

export default AvisoNotificaciones;
