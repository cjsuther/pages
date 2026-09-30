import React, { useContext } from 'react';
import { Routes, Route, Link, Navigate, useLocation } from 'react-router-dom';
import { LogOut } from 'lucide-react';
import { AuthContext } from '../App';
import Login, { DESTINO } from '../pages/Login';
import Alta from './Alta';
import Hoy from './Hoy';
import Shows from './Shows';
import Show from './Show';
import Comediantes from './Comediantes';
import { URL_REZONAR } from '../utils/carcajada';

/**
 * Carcajada, en su propio subdominio.
 *
 * Es la misma aplicación y las mismas cuentas de Rezonar; lo único distinto
 * son las pantallas. La del QR queda afuera del marco: se abre en un bar, a
 * oscuras, y una barra de navegación ahí sólo invita a tocar cosas.
 */
function RutasCarcajada({ apiUrl }) {
  return (
    <Routes>
      <Route path="/hoy" element={<Hoy apiUrl={apiUrl} />} />
      <Route path="/fecha/:id" element={<Hoy apiUrl={apiUrl} />} />
      <Route path="/login" element={<ConMarco><Login /></ConMarco>} />
      <Route path="/" element={<ConMarco><ConSesion><Alta /></ConSesion></ConMarco>} />
      <Route path="/shows" element={<ConMarco><ConSesion><Shows /></ConSesion></ConMarco>} />
      <Route path="/shows/:id" element={<ConMarco><ConSesion><Show /></ConSesion></ConMarco>} />
      <Route path="/comediantes" element={<ConMarco><ConSesion><Comediantes /></ConSesion></ConMarco>} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

/**
 * Sin sesión no hay nada que mostrar: se manda a entrar y se vuelve acá.
 *
 * El destino se anota donde ya lo busca el login, que no lo lee de la URL:
 * entrar con Google se va del sitio y vuelve, y un parámetro no sobrevive ese
 * rodeo.
 */
function ConSesion({ children }) {
  const { token } = useContext(AuthContext);
  const { pathname } = useLocation();

  if (token) {
    return children;
  }

  try {
    sessionStorage.setItem(DESTINO, pathname);
  } catch (e) {
    // Sin sessionStorage se vuelve a la portada, que es la pantalla de alta.
  }

  return <Navigate to="/login" replace />;
}

function ConMarco({ children }) {
  const { token, user, logout } = useContext(AuthContext);

  return (
    <div className="min-h-screen bg-papel-hueso flex flex-col">
      <header className="bg-white border-b border-borde">
        <div className="max-w-3xl mx-auto px-5 h-16 flex items-center justify-between gap-4">
          <Link to="/" className="font-extrabold text-xl tracking-tight text-tinta">
            Carcajada
          </Link>

          <nav className="flex items-center gap-4 text-sm">
            {token && (
              <>
                <Link to="/shows" className="text-tinta-media hover:text-tinta font-medium">Shows</Link>
                <Link to="/comediantes" className="text-tinta-media hover:text-tinta font-medium">Comediantes</Link>
                <button
                  type="button"
                  onClick={logout}
                  className="text-tinta-suave hover:text-tinta"
                  aria-label={`Salir de la cuenta de ${user ? user.email : ''}`}
                >
                  <LogOut className="w-4 h-4" />
                </button>
              </>
            )}
          </nav>
        </div>
      </header>

      <main className="flex-1 max-w-3xl w-full mx-auto px-5 py-8">{children}</main>

      <footer className="max-w-3xl w-full mx-auto px-5 py-6 text-xs text-tinta-suave">
        Las cuentas y las páginas son de{' '}
        <a href={URL_REZONAR} target="_blank" rel="noreferrer" className="hover:text-tinta">rezon.ar</a>.
      </footer>
    </div>
  );
}

export default RutasCarcajada;
