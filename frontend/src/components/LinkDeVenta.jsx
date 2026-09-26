import React from 'react';
import { Share2 } from 'lucide-react';
import LinkConClave from './LinkConClave';

/**
 * El link para mostrarle a alguien cómo viene la venta de un evento.
 *
 * Es para el artista, el socio o quien produce: gente que necesita ver cómo
 * se está vendiendo pero no administra la página. Muestra cuántas van, cuánto
 * se recaudó y, si el evento tiene plano, qué lugares están vendidos. No
 * muestra quiénes compraron.
 */
function LinkDeVenta({ linkId, apiUrl, token }) {
  return (
    <LinkConClave
      endpoint={`${apiUrl}/entradas/compartir.php?link_id=${linkId}`}
      token={token}
      icono={Share2}
      titulo="Compartir cómo viene la venta"
      etiqueta="Link para compartir"
      textoCrear="Crear link para compartir"
      ayuda="Quien tenga este link ve lo recaudado del show, pero no los datos de quienes compraron."
      avisoCambiar="El link actual va a dejar de funcionar y vas a tener que mandar el nuevo."
      avisoDesactivar="El link va a dejar de funcionar y quien lo tenga no va a poder seguir mirando."
    />
  );
}

export default LinkDeVenta;
