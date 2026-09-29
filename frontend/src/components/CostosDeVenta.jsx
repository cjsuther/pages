import React, { useEffect, useState } from 'react';
import { Rotulo, Tarjeta } from './ui';

/**
 * Lo que se descuenta de cada entrada vendida, con los números del servidor.
 *
 * Quien está decidiendo si vender por acá tiene que poder hacer la cuenta
 * antes de registrarse. Los porcentajes no se escriben en el texto: salen de
 * la misma configuración que se usa al cobrar, así ninguna página puede
 * prometer una comisión distinta de la que se aplica. Si el pedido falla,
 * costos queda en null y quien lo usa no muestra números: mejor nada que un
 * número inventado.
 */
export function useCostos(apiUrl) {
  const [costos, setCostos] = useState(null);

  useEffect(() => {
    let vigente = true;
    fetch(`${apiUrl}/public/costos.php`)
      .then((r) => (r.ok ? r.json() : null))
      .then((cuerpo) => {
        if (vigente && cuerpo && typeof cuerpo.comision === 'number') setCostos(cuerpo);
      })
      .catch(() => {});
    return () => { vigente = false; };
  }, [apiUrl]);

  return costos;
}

/** Precio de ejemplo para hacer la cuenta: redondo, y del orden de una entrada real. */
export const PRECIO_EJEMPLO = 10000;

export const pesos = (n) => '$' + n.toLocaleString('es-AR', { maximumFractionDigits: 2 });

/**
 * Lo que queda de una entrada después de las dos comisiones.
 *
 * La nuestra se redondea hacia abajo, igual que Comision::sobre en el
 * servidor, para que el ejemplo dé el mismo número que la venta real.
 */
export function cuentaDeUnaEntrada(costos, precio = PRECIO_EJEMPLO) {
  const deRezonar = Math.floor(precio * costos.comision) / 100;
  const deMercadoPago = costos.mercadopago
    ? Math.round(precio * costos.mercadopago.porcentaje) / 100
    : 0;

  return { deRezonar, deMercadoPago, teQueda: precio - deRezonar - deMercadoPago };
}

/** La cuenta de una entrada de ejemplo, en una tarjeta. */
export function CuentaDeEjemplo({ costos, className = '' }) {
  const { deRezonar, deMercadoPago, teQueda } = cuentaDeUnaEntrada(costos);

  return (
    <Tarjeta className={`p-6 ${className}`}>
      <Rotulo>Una entrada de {pesos(PRECIO_EJEMPLO)}</Rotulo>
      <dl className="mt-4 space-y-2 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-tinta-media">Comisión de Rezonar</dt>
          <dd className="text-tinta tabular-nums">−{pesos(deRezonar)}</dd>
        </div>
        {costos.mercadopago && (
          <div className="flex justify-between gap-4">
            <dt className="text-tinta-media">Mercado Pago</dt>
            <dd className="text-tinta tabular-nums">−{pesos(deMercadoPago)}</dd>
          </div>
        )}
        <div className="flex justify-between gap-4 pt-3 mt-1 border-t border-borde font-bold">
          <dt className="text-tinta">Te queda</dt>
          <dd className="text-verde-oscuro tabular-nums">{pesos(teQueda)}</dd>
        </div>
      </dl>
    </Tarjeta>
  );
}
