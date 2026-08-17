import React, { useState, useEffect, useContext } from 'react';
import { Copy, Check, Trash2 } from 'lucide-react';
import { AuthContext } from '../App';

/**
 * Claves de API: lo que conecta un asistente con las páginas de la persona.
 *
 * La clave se muestra entera una única vez, al crearla. Después el servidor
 * sólo guarda su hash, así que no hay forma de volver a mostrarla ni siquiera
 * queriendo: si se pierde, se genera otra y se revoca la vieja.
 */
function ClavesApi() {
  const { token, apiUrl } = useContext(AuthContext);
  const [claves, setClaves] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [nombre, setNombre] = useState('');
  const [recienCreada, setRecienCreada] = useState(null);
  const [copiada, setCopiada] = useState(false);
  const [error, setError] = useState(null);

  const cabeceras = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };

  const cargar = async () => {
    try {
      const r = await fetch(`${apiUrl}/users/claves.php`, { headers: cabeceras });
      const cuerpo = await r.json();

      if (r.ok) setClaves(cuerpo.claves || []);
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    cargar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const crear = async (e) => {
    e.preventDefault();
    setError(null);

    const r = await fetch(`${apiUrl}/users/claves.php`, {
      method: 'POST',
      headers: cabeceras,
      body: JSON.stringify({ nombre }),
    });
    const cuerpo = await r.json();

    if (!r.ok) {
      setError(cuerpo.error || 'No pudimos crear la clave');
      return;
    }

    setRecienCreada(cuerpo.clave);
    setCopiada(false);
    setNombre('');
    cargar();
  };

  const revocar = async (id) => {
    if (!window.confirm('¿Revocar esta clave? Lo que la esté usando va a dejar de funcionar.')) return;

    await fetch(`${apiUrl}/users/claves.php?id=${id}`, { method: 'DELETE', headers: cabeceras });
    cargar();
  };

  const copiar = () => {
    navigator.clipboard.writeText(recienCreada);
    setCopiada(true);
  };

  return (
    <div className="bg-gray-900 border border-gray-800 p-8">
      <h2 className="text-2xl font-black mb-2 tracking-tight">CLAVES DE API</h2>
      <p className="text-sm text-gray-500 mb-8">
        Sirven para conectar un asistente a tus páginas y que pueda crear y administrar
        eventos por vos. Da acceso a todo lo que vos podés hacer: compartila como
        compartirías una contraseña.
      </p>

      {recienCreada && (
        <div className="border border-emerald-800 bg-emerald-950 p-4 mb-6">
          <p className="text-sm text-emerald-300 font-bold mb-2">
            Guardala ahora: no la vas a poder volver a ver.
          </p>
          <div className="flex items-center gap-3">
            <code className="flex-1 text-xs text-white bg-black px-3 py-2 overflow-x-auto whitespace-nowrap">
              {recienCreada}
            </code>
            <button
              type="button"
              onClick={copiar}
              className="shrink-0 text-gray-300 hover:text-white"
              aria-label="Copiar la clave"
            >
              {copiada ? <Check className="w-5 h-5 text-emerald-400" /> : <Copy className="w-5 h-5" />}
            </button>
          </div>
        </div>
      )}

      <form onSubmit={crear} className="flex gap-3 mb-8">
        <input
          type="text"
          value={nombre}
          onChange={(e) => setNombre(e.target.value)}
          placeholder="Para qué la vas a usar"
          aria-label="Nombre de la clave"
          className="flex-1 bg-black border border-gray-700 px-4 py-3 text-white focus:border-white focus:outline-none"
        />
        <button
          type="submit"
          disabled={!nombre.trim()}
          className="px-6 py-3 bg-white text-black font-bold hover:bg-gray-200 transition disabled:opacity-40"
        >
          CREAR
        </button>
      </form>

      {error && <p className="text-sm text-red-400 mb-6">{error}</p>}

      {cargando ? (
        <p className="text-gray-500">Cargando...</p>
      ) : claves.length === 0 ? (
        <p className="text-gray-500 text-sm">Todavía no creaste ninguna.</p>
      ) : (
        <ul className="divide-y divide-gray-800 border-t border-gray-800">
          {claves.map((c) => (
            <li key={c.id} className="py-4 flex items-center justify-between gap-4">
              <div className="min-w-0">
                <p className="text-white font-bold truncate">{c.nombre}</p>
                <p className="text-xs text-gray-600 font-mono">
                  {c.prefijo}…
                  {c.ultimo_uso_en ? ` · usada por última vez el ${c.ultimo_uso_en.slice(0, 10)}` : ' · sin usar todavía'}
                </p>
              </div>
              <button
                type="button"
                onClick={() => revocar(c.id)}
                className="shrink-0 text-gray-500 hover:text-red-400 transition"
                aria-label={`Revocar ${c.nombre}`}
              >
                <Trash2 className="w-4 h-4" />
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-10 border-t border-gray-800 pt-6">
        <p className="text-sm font-bold text-gray-400 mb-2">CÓMO CONECTARLO</p>
        <p className="text-xs text-gray-600 mb-3">
          En tu cliente de MCP, agregá un servidor remoto con esta dirección. Si el
          cliente sabe autorizarse solo no hace falta ninguna clave: te va a mandar a
          Rezonar a dar el permiso.
        </p>
        <code className="block text-xs text-gray-300 bg-black px-3 py-2 overflow-x-auto whitespace-nowrap">
          https://rezon.ar/mcp
        </code>
      </div>
    </div>
  );
}

/**
 * Aplicaciones que la persona autorizó por OAuth.
 *
 * Va acá al lado de las claves porque son las dos formas de conectar algo, y
 * quien viene a cortar un acceso no tiene por qué saber cuál de las dos usó.
 */
export function Conexiones() {
  const { token, apiUrl } = useContext(AuthContext);
  const [conexiones, setConexiones] = useState([]);
  const [cargando, setCargando] = useState(true);

  const cabeceras = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };

  const cargar = async () => {
    try {
      const r = await fetch(`${apiUrl}/oauth/conexiones.php`, { headers: cabeceras });
      const cuerpo = await r.json();

      if (r.ok) setConexiones(cuerpo.conexiones || []);
    } catch (e) {
      // Sin conexiones que mostrar, la sección simplemente no aparece.
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    cargar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const desconectar = async (clientId, nombre) => {
    if (!window.confirm(`¿Desconectar ${nombre}? Va a perder el acceso a tus páginas.`)) return;

    await fetch(`${apiUrl}/oauth/conexiones.php?client_id=${encodeURIComponent(clientId)}`, {
      method: 'DELETE',
      headers: cabeceras,
    });
    cargar();
  };

  if (cargando || conexiones.length === 0) {
    return null;
  }

  return (
    <div className="bg-gray-900 border border-gray-800 p-8 mt-8">
      <h2 className="text-2xl font-black mb-2 tracking-tight">APLICACIONES CONECTADAS</h2>
      <p className="text-sm text-gray-500 mb-8">
        Programas a los que les diste permiso para administrar tus eventos.
      </p>

      <ul className="divide-y divide-gray-800 border-t border-gray-800">
        {conexiones.map((c) => (
          <li key={c.client_id} className="py-4 flex items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="text-white font-bold truncate">{c.nombre || 'Aplicación sin nombre'}</p>
              <p className="text-xs text-gray-600">
                {c.ultimo_uso_en ? `Última vez el ${c.ultimo_uso_en.slice(0, 10)}` : 'Sin usar todavía'}
              </p>
            </div>
            <button
              type="button"
              onClick={() => desconectar(c.client_id, c.nombre)}
              className="shrink-0 text-sm text-gray-500 hover:text-red-400 transition"
            >
              Desconectar
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default ClavesApi;
