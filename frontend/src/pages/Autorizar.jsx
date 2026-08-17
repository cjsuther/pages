import React, { useState, useEffect, useContext } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';
import { DESTINO } from './Login';

/**
 * Pantalla donde la persona autoriza a una aplicación a administrar sus
 * eventos.
 *
 * Es la única parte del circuito de OAuth que vive en el frontend, y tiene que
 * ser así: la sesión de Rezonar es un token en el navegador, y sólo la
 * aplicación sabe leerlo. El servidor valida el pedido antes de que se muestre
 * nada y lo vuelve a validar al aprobar.
 *
 * Los parámetros no se tocan ni se completan: se reenvían tal como llegaron.
 * Cualquier cosa que agregáramos acá sería una decisión tomada por el
 * navegador sobre un permiso que concede la persona.
 */
function Autorizar() {
  const { token, apiUrl } = useContext(AuthContext);
  const [params] = useSearchParams();
  const navigate = useNavigate();

  const [pedido, setPedido] = useState(null);
  const [error, setError] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [autorizando, setAutorizando] = useState(false);

  const comoObjeto = () => Object.fromEntries(params.entries());

  useEffect(() => {
    const revisar = async () => {
      try {
        const r = await fetch(`${apiUrl}/oauth/revisar.php?${params.toString()}`);
        const cuerpo = await r.json();

        if (!r.ok) {
          setError(cuerpo.error || 'El pedido de autorización no es válido');
          return;
        }

        setPedido(cuerpo);
      } catch (e) {
        setError('No pudimos conectarnos al servidor');
      } finally {
        setCargando(false);
      }
    };

    revisar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /**
   * La vuelta la arma el servidor, no el navegador: es la única forma de
   * garantizar que el código vaya a una dirección registrada por la
   * aplicación.
   */
  const autorizar = async () => {
    setAutorizando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/oauth/aprobar.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(comoObjeto()),
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No pudimos autorizar la aplicación');
        return;
      }

      window.location.href = cuerpo.redirect_to;
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setAutorizando(false);
    }
  };

  const rechazar = () => navigate('/');

  if (cargando) {
    return <Marco><p className="text-gray-500">Revisando el pedido...</p></Marco>;
  }

  if (error && !pedido) {
    return (
      <Marco>
        <h1 className="text-2xl font-black mb-3">No pudimos seguir</h1>
        <p className="text-gray-400 mb-8">{error}</p>
        <button onClick={rechazar} className="text-gray-400 hover:text-white">Volver al inicio</button>
      </Marco>
    );
  }

  // Sin sesión no hay a nombre de quién autorizar. Se manda a entrar y se
  // vuelve acá con los parámetros intactos.
  if (!token) {
    return (
      <Marco>
        <h1 className="text-2xl font-black mb-3">Entrá para autorizar</h1>
        <p className="text-gray-400 mb-8">
          <strong className="text-white">{pedido.aplicacion}</strong> quiere acceso a tus
          páginas. Primero entrá a tu cuenta.
        </p>
        <button
          onClick={() => {
            // Entrar con Google se va del sitio y vuelve: el destino tiene que
            // sobrevivir ese rodeo, y un parámetro en la URL no lo hace.
            sessionStorage.setItem(DESTINO, window.location.pathname + window.location.search);
            navigate('/login');
          }}
          className="w-full py-4 bg-white text-black font-bold hover:bg-gray-200 transition"
        >
          ENTRAR
        </button>
      </Marco>
    );
  }

  return (
    <Marco>
      <p className="text-xs text-gray-500 tracking-widest mb-3">PEDIDO DE ACCESO</p>
      <h1 className="text-2xl font-black mb-6">
        {pedido.aplicacion} quiere administrar tus eventos
      </h1>

      <div className="border border-gray-800 bg-black p-4 mb-8">
        <p className="text-sm text-gray-400">{pedido.permiso}</p>
      </div>

      <p className="text-xs text-gray-600 mb-8">
        Vas a poder desconectarla cuando quieras desde Perfil → Claves de API.
      </p>

      {error && <p className="text-sm text-red-400 mb-6">{error}</p>}

      <div className="space-y-3">
        <button
          onClick={autorizar}
          disabled={autorizando}
          className="w-full py-4 bg-white text-black font-bold hover:bg-gray-200 transition disabled:opacity-50"
        >
          {autorizando ? 'AUTORIZANDO...' : 'AUTORIZAR'}
        </button>
        <button
          onClick={rechazar}
          disabled={autorizando}
          className="w-full py-4 text-gray-400 hover:text-white transition"
        >
          No, gracias
        </button>
      </div>
    </Marco>
  );
}

function Marco({ children }) {
  return (
    <div className="min-h-screen bg-black text-white flex items-center justify-center px-6">
      <div className="w-full max-w-md bg-gray-900 border border-gray-800 p-8">{children}</div>
    </div>
  );
}

export default Autorizar;
