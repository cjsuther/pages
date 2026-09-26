import React, { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Download } from 'lucide-react';
import { Boton, Tarjeta, Rotulo } from '../components/ui';

/**
 * El QR que se imprime y se deja en las mesas.
 *
 * Es uno solo y para siempre: apunta a una dirección fija que muestra el show
 * del día, así que no hay que imprimir uno nuevo cada fecha ni acordarse de
 * cambiar el cartel.
 */
function QrDelShow({ url }) {
  const [imagen, setImagen] = useState(null);

  useEffect(() => {
    let vigente = true;

    QRCode.toDataURL(url, { width: 600, margin: 1 })
      .then((dato) => { if (vigente) setImagen(dato); })
      .catch(() => { if (vigente) setImagen(null); });

    return () => { vigente = false; };
  }, [url]);

  return (
    <Tarjeta className="p-6">
      <Rotulo>Para las mesas</Rotulo>
      <h2 className="font-bold text-tinta mt-1 mb-1">QR del público</h2>
      <p className="text-sm text-tinta-media mb-4">
        Siempre muestra el show del día con su line-up, así que se imprime una vez y
        sirve para todas las fechas.
      </p>

      <div className="flex flex-wrap items-center gap-5">
        {imagen && <img src={imagen} alt={`Código QR de ${url}`} className="w-32 h-32" />}

        <div className="space-y-2">
          <p className="font-mono text-sm text-tinta break-all">{url}</p>
          {imagen && (
            <Boton variante="secundario" tamano="sm" href={imagen} download="carcajada-qr.png">
              <Download className="w-4 h-4" /> Descargar QR
            </Boton>
          )}
        </div>
      </div>
    </Tarjeta>
  );
}

export default QrDelShow;
