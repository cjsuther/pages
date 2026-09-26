import React from 'react';
import { DoorOpen } from 'lucide-react';
import LinkConClave from './LinkConClave';

/**
 * El link de puerta de un evento.
 *
 * Con él, quien controla la entrada escanea los QR o busca a la persona en la
 * lista y marca que entró, sin cuenta en la plataforma.
 */
function LinkDePuerta({ linkId, apiUrl, token }) {
  return (
    <LinkConClave
      endpoint={`${apiUrl}/entradas/puerta.php?link_id=${linkId}`}
      token={token}
      icono={DoorOpen}
      titulo="Control de ingreso"
      descripcion="Un link para quien esté en la puerta: escanea el QR de cada entrada o busca a la persona en la lista, y marca que entró. No necesita cuenta en Rezonar."
      etiqueta="Link de puerta"
      textoCrear="Crear link de puerta"
      ayuda="Quien tenga este link ve los nombres de quienes compraron. Pasalo sólo a la gente de la puerta."
      avisoCambiar="El link actual va a dejar de funcionar y vas a tener que mandar el nuevo a la gente de la puerta."
      avisoDesactivar="El link va a dejar de funcionar. Lo que ya se marcó queda guardado."
    />
  );
}

export default LinkDePuerta;
