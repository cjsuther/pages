import React, { useState, useContext } from 'react';
import { useParams } from 'react-router-dom';
import { AuthContext } from '../App';

/**
 * Página donde se suelta el archivo del afiche.
 *
 * Existe porque un asistente no puede mandarnos una imagen: los argumentos de
 * una herramienta son texto que el modelo escribe, y una imagen de verdad no
 * entra ahí. Entonces el asistente entrega este link y la persona hace lo
 * único que hace falta hacer a mano.
 *
 * No pide sesión: el token del link es la credencial, y el servidor lo revisa
 * de nuevo al recibir el archivo. Por eso la página no muestra ningún dato del
 * evento —quien tenga el link no tiene por qué ver nada más que el cuadro
 * donde soltar.
 */
function SubirImagen() {
  const { token } = useParams();
  const { apiUrl } = useContext(AuthContext);

  const [subiendo, setSubiendo] = useState(false);
  const [listo, setListo] = useState(false);
  const [error, setError] = useState(null);
  const [encima, setEncima] = useState(false);

  const subir = async (archivo) => {
    if (!archivo) return;

    setSubiendo(true);
    setError(null);

    try {
      const cuerpo = new FormData();
      cuerpo.append('image', archivo);

      const r = await fetch(`${apiUrl}/upload/con-token.php?token=${encodeURIComponent(token)}`, {
        method: 'POST',
        body: cuerpo,
      });
      const respuesta = await r.json();

      if (!r.ok) {
        setError(respuesta.error || 'No pudimos subir la imagen');
        return;
      }

      setListo(true);
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setSubiendo(false);
    }
  };

  return (
    <div className="min-h-screen bg-white text-tinta flex items-center justify-center px-6">
      <div className="w-full max-w-md bg-white border border-borde rounded-2xl p-6 sm:p-8">
        {listo ? (
          <>
            <h1 className="text-2xl font-bold mb-3">Listo</h1>
            <p className="text-tinta-media">
              La imagen ya quedó en el evento. Podés cerrar esta pestaña.
            </p>
          </>
        ) : (
          <>
            <p className="text-xs text-tinta-suave tracking-widest mb-3">Imagen del evento</p>
            <h1 className="text-2xl font-bold mb-6">Soltá el afiche acá</h1>

            <label
              onDragOver={(e) => {
                e.preventDefault();
                setEncima(true);
              }}
              onDragLeave={() => setEncima(false)}
              onDrop={(e) => {
                e.preventDefault();
                setEncima(false);
                subir(e.dataTransfer.files[0]);
              }}
              className={`block border-2 border-dashed p-10 text-center cursor-pointer transition ${
                encima ? 'border-verde bg-verde-claro' : 'border-borde-fuerte hover:border-tinta'
              }`}
            >
              <input
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                className="sr-only"
                aria-label="Elegir la imagen"
                onChange={(e) => subir(e.target.files[0])}
                disabled={subiendo}
              />
              <span className="text-tinta-media">
                {subiendo ? 'Subiendo...' : 'Arrastrá el archivo o hacé clic para elegirlo'}
              </span>
            </label>

            <p className="text-xs text-tinta-suave mt-4">
              JPG, PNG, GIF o WebP, hasta 5 MB. El link sirve una sola vez.
            </p>

            {error && <p role="alert" className="text-sm text-red-700 mt-4">{error}</p>}
          </>
        )}
      </div>
    </div>
  );
}

export default SubirImagen;
