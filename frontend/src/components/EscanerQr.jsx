import React, { useEffect, useRef, useState } from 'react';
import jsQR from 'jsqr';

/** Cada cuánto se mira un cuadro de la cámara. Más seguido sólo gasta batería. */
const MILISEGUNDOS_ENTRE_LECTURAS = 150;

/** Ancho al que se achica el cuadro antes de buscar el QR: más grande no lo encuentra mejor. */
const ANCHO_DE_LECTURA = 640;

/**
 * Lee códigos QR con la cámara trasera.
 *
 * Usa jsQR y no el lector del navegador (BarcodeDetector) porque ese no existe
 * en Safari de iPhone, que es justamente el teléfono que más se ve en una
 * puerta.
 *
 * Mientras `pausado` es true la cámara sigue prendida pero no lee: así, al
 * mostrar el resultado de una entrada, el mismo QR todavía delante de la
 * cámara no se vuelve a leer veinte veces.
 */
function EscanerQr({ onLeer, pausado = false }) {
  const video = useRef(null);
  const lienzo = useRef(null);
  const pausadoRef = useRef(pausado);
  const onLeerRef = useRef(onLeer);
  const [error, setError] = useState(null);

  pausadoRef.current = pausado;
  onLeerRef.current = onLeer;

  useEffect(() => {
    let flujo = null;
    let temporizador = null;
    let vigente = true;

    const leer = () => {
      if (!vigente) return;

      const v = video.current;
      const c = lienzo.current;

      if (!pausadoRef.current && v && c && v.readyState >= 2 && v.videoWidth > 0) {
        const escala = Math.min(1, ANCHO_DE_LECTURA / v.videoWidth);
        const ancho = Math.round(v.videoWidth * escala);
        const alto = Math.round(v.videoHeight * escala);

        c.width = ancho;
        c.height = alto;

        const contexto = c.getContext('2d', { willReadFrequently: true });
        contexto.drawImage(v, 0, 0, ancho, alto);

        const cuadro = contexto.getImageData(0, 0, ancho, alto);
        const qr = jsQR(cuadro.data, ancho, alto, { inversionAttempts: 'dontInvert' });

        if (qr && qr.data) {
          onLeerRef.current(qr.data);
        }
      }

      temporizador = setTimeout(leer, MILISEGUNDOS_ENTRE_LECTURAS);
    };

    (async () => {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setError('Este navegador no puede usar la cámara. Buscá a la persona en la lista.');
        return;
      }

      try {
        flujo = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' },
          audio: false,
        });

        if (!vigente) {
          flujo.getTracks().forEach((t) => t.stop());
          return;
        }

        video.current.srcObject = flujo;
        await video.current.play();
        leer();
      } catch (e) {
        if (!vigente) return;

        setError(e && e.name === 'NotAllowedError'
          ? 'No hay permiso para usar la cámara. Habilitalo en el navegador, o buscá a la persona en la lista.'
          : 'No se pudo prender la cámara. Buscá a la persona en la lista.');
      }
    })();

    // Al cerrar el escáner la cámara se apaga de verdad: si quedara prendida,
    // el teléfono seguiría mostrando que la está usando.
    return () => {
      vigente = false;
      clearTimeout(temporizador);
      if (flujo) flujo.getTracks().forEach((t) => t.stop());
    };
  }, []);

  if (error) {
    return (
      <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">
        {error}
      </p>
    );
  }

  return (
    <div className="relative bg-black overflow-hidden aspect-square max-h-[60vh] mx-auto">
      {/* muted y playsInline son los que dejan reproducir el video en iPhone
          sin que se abra a pantalla completa. */}
      <video ref={video} muted playsInline className="w-full h-full object-cover" />
      <canvas ref={lienzo} className="hidden" />
      <div className="absolute inset-[15%] border-4 border-white/80 rounded-lg pointer-events-none" />
      {pausado && <div className="absolute inset-0 bg-black/40" />}
    </div>
  );
}

export default EscanerQr;
