import React, { useContext, useState, useRef, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Menu, X, ChevronDown, Check } from 'lucide-react';
import { AuthContext } from '../App';
import NotificationBell from './NotificationBell';
import LocationIndicator from './LocationIndicator';

function ProfileMenu() {
  const { user, token, logout, updateUser, apiUrl } = useContext(AuthContext);
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(user?.name || '');
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    setName(user?.name || '');
  }, [user?.name]);

  useEffect(() => {
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${apiUrl}/users/profile.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ name: name.trim() })
      });
      const data = await res.json();
      if (data.success) {
        updateUser({ ...user, name: data.user.name });
        setSaved(true);
        setTimeout(() => setSaved(false), 2000);
      }
    } catch (err) {
      console.error('Error updating name:', err);
    } finally {
      setSaving(false);
    }
  };

  const displayLabel = user?.name || user?.email;

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center gap-1 text-gray-400 hover:text-white transition"
      >
        <span className="text-sm font-medium">{displayLabel}</span>
        <ChevronDown className="w-3.5 h-3.5" />
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-72 bg-gray-900 border border-gray-700 rounded-lg shadow-xl z-50 p-4">
          <p className="text-xs text-gray-500 mb-1">Email</p>
          <p className="text-sm text-gray-300 mb-4 truncate">{user?.email}</p>

          <p className="text-xs text-gray-500 mb-1">Nombre</p>
          <input
            type="text"
            value={name}
            onChange={e => setName(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleSave()}
            placeholder="Tu nombre"
            className="w-full bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 rounded focus:border-gray-500 focus:outline-none mb-3"
          />

          <div className="flex items-center justify-between">
            <button
              onClick={handleSave}
              disabled={saving}
              className="flex items-center gap-1.5 px-4 py-1.5 bg-white text-black text-sm font-bold rounded hover:bg-gray-200 transition disabled:opacity-50"
            >
              {saved ? <><Check className="w-3.5 h-3.5" /> Guardado</> : saving ? 'Guardando...' : 'Guardar'}
            </button>
            <button
              onClick={() => { logout(); setOpen(false); }}
              className="text-sm text-gray-500 hover:text-white transition"
            >
              Cerrar sesión
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function Navigation() {
  const { token } = useContext(AuthContext);
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
                <ProfileMenu />
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
              <ProfileMenu />
            </div>
          </div>
        )}
      </div>
    </nav>
  );
}

export default Navigation;
