import React, { useContext, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Menu, X } from 'lucide-react';
import { AuthContext } from '../App';
import NotificationBell from './NotificationBell';
import LocationIndicator from './LocationIndicator';

function Navigation() {
  const { token, user, logout } = useContext(AuthContext);
  const location = useLocation();
  const [isMenuOpen, setIsMenuOpen] = useState(false);

  const isActive = (path) => location.pathname === path;

  return (
    <nav className="border-b border-gray-800">
      <div className="max-w-7xl mx-auto px-6 py-6">
        <div className="flex justify-between items-center">
          <div className="flex items-center gap-8">
            <Link to="/">
              <img src="/logo.png" alt="Rezonar" className="h-10" />
            </Link>
            {token && (
              <div className="hidden md:flex gap-6">
                <Link
                  to="/"
                  className={`font-bold transition ${
                    isActive('/') ? 'text-white' : 'text-gray-400 hover:text-white'
                  }`}
                >
                  INICIO
                </Link>
                <Link
                  to="/pages"
                  className={`font-bold transition ${
                    isActive('/pages') ? 'text-white' : 'text-gray-400 hover:text-white'
                  }`}
                >
                  PÁGINAS
                </Link>
                <Link
                  to="/my-pages"
                  className={`font-bold transition ${
                    isActive('/my-pages') ? 'text-white' : 'text-gray-400 hover:text-white'
                  }`}
                >
                  MIS PÁGINAS
                </Link>
              </div>
            )}
          </div>

          <div className="hidden md:flex items-center gap-6">
            {token ? (
              <>
                <LocationIndicator />
                <NotificationBell />
                <div className="text-right">
                  <p className="text-gray-500 text-sm font-medium">{user?.email}</p>
                </div>
                <button onClick={logout} className="text-gray-400 hover:text-white transition font-medium">
                  Salir de la Cuenta
                </button>
              </>
            ) : (
              <Link to="/login" className="text-gray-300 hover:text-white transition font-medium">
                Iniciar Sesión / Registrarse
              </Link>
            )}
          </div>

          {token && (
            <button
              onClick={() => setIsMenuOpen(!isMenuOpen)}
              className="md:hidden text-gray-400 hover:text-white transition"
            >
              {isMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
            </button>
          )}
        </div>

        {isMenuOpen && token && (
          <div className="md:hidden mt-6 pt-6 border-t border-gray-800 space-y-4">
            <Link
              to="/"
              onClick={() => setIsMenuOpen(false)}
              className={`block font-bold transition ${
                isActive('/') ? 'text-white' : 'text-gray-400 hover:text-white'
              }`}
            >
              INICIO
            </Link>
            <Link
              to="/pages"
              onClick={() => setIsMenuOpen(false)}
              className={`block font-bold transition ${
                isActive('/pages') ? 'text-white' : 'text-gray-400 hover:text-white'
              }`}
            >
              PÁGINAS
            </Link>
            <Link
              to="/my-pages"
              onClick={() => setIsMenuOpen(false)}
              className={`block font-bold transition ${
                isActive('/my-pages') ? 'text-white' : 'text-gray-400 hover:text-white'
              }`}
            >
              MIS PÁGINAS
            </Link>
            <div className="flex items-center gap-4 pt-4 border-t border-gray-800">
              <LocationIndicator />
              <NotificationBell />
            </div>
            <div className="pt-2">
              <p className="text-gray-500 text-sm font-medium mb-3">{user?.email}</p>
              <button
                onClick={() => {
                  logout();
                  setIsMenuOpen(false);
                }}
                className="text-gray-400 hover:text-white transition font-medium"
              >
                Salir de la Cuenta
              </button>
            </div>
          </div>
        )}
      </div>
    </nav>
  );
}

export default Navigation;
