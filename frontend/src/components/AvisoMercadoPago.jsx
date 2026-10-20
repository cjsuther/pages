import React, { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { esPago } from '../utils/productos';

/**
 * El aviso de que para cobrar falta conectar Mercado Pago, para un producto
 * digital con precio. Es el mismo que se muestra en un evento que vende
 * entradas (PanelEntradas).
 *
 * Se pregunta recién cuando el precio pasa de 0: un producto gratis no
 * necesita Mercado Pago y no tiene sentido ir a buscarlo.
 */
function AvisoMercadoPago({ pageId, apiUrl, token, precio }) {
  const [configurado, setConfigurado] = useState(null);
  const pago = esPago(precio);

  useEffect(() => {
    if (!pago || configurado !== null) return;

    let vigente = true;

    fetch(`${apiUrl}/entradas/credenciales.php?page_id=${pageId}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((datos) => {
        if (vigente && datos) setConfigurado(Boolean(datos.cobros && datos.cobros.configurado));
      })
      .catch(() => {});

    return () => { vigente = false; };
  }, [pago, configurado, apiUrl, pageId, token]);

  if (!pago || configurado !== false) return null;

  return (
    <p className="flex items-start gap-2 text-amber-400 text-sm bg-amber-950 border border-amber-900 px-4 py-3">
      <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
      Para cobrar tenés que conectar Mercado Pago en la sección Entradas de la
      página. Con precio en 0 podés ofrecerlo gratis igual.
    </p>
  );
}

export default AvisoMercadoPago;
