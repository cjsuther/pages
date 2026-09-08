import React, { useContext, useState, useRef, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Menu, X, ChevronDown, Check, LogOut } from 'lucide-react';
import { AuthContext } from '../App';
import NotificationBell from './NotificationBell';
import LocationIndicator from './LocationIndicator';
import { Boton, Campo, Etiqueta } from './ui';

const SECCIONES = [
  { a: '/', texto: 'Inicio' },
  { a: '/pages', texto: 'Seguir páginas' },
  { a: '/my-pages', texto: 'Mis páginas' },
];

/**
 * Lo que sólo ve quien administra la plataforma. Esconder el enlace no es el
 * control de acceso —eso lo decide el servidor en cada pedido— sino no
 * ofrecerle a nadie una puerta que no le va a abrir.
 */
const SECCIONES_PLATAFORMA = [
  { a: '/comisiones', texto: 'Comisiones' },
];

function MenuPerfil({ alNavegar = () => {} }) {
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

  const iniciales = (user?.name || user?.email || '?').trim().charAt(0).toUpperCase();

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center gap-2 text-tinta-media hover:text-tinta transition-colors"
      >
        <span className="w-8 h-8 rounded-full bg-verde-claro text-verde-oscuro font-bold text-sm flex items-center justify-center border border-verde-medio">
          {iniciales}
        </span>
        <span className="text-sm font-medium max-w-[10rem] truncate hidden lg:inline">
          {user?.name || user?.email}
        </span>
        <ChevronDown className="w-3.5 h-3.5" />
      </button>

      {open && (
        <div className="absolute right-0 mt-3 w-72 bg-white border border-borde rounded-2xl shadow-lg shadow-tinta/5 z-50 p-5">
          <p className="text-xs text-tinta-suave mb-1">Sesión iniciada como</p>
          <p className="text-sm text-tinta mb-5 truncate font-medium">{user?.email}</p>

          <Etiqueta htmlFor="nombre-perfil">Tu nombre</Etiqueta>
          <Campo
            id="nombre-perfil"
            type="text"
            value={name}
            onChange={e => setName(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleSave()}
            placeholder="Cómo querés que te vean"
            className="mb-4 py-2"
          />

          <div className="flex items-center justify-between gap-3">
            <Boton onClick={handleSave} disabled={saving} tamano="sm">
              {saved ? <><Check className="w-3.5 h-3.5" /> Guardado</> : saving ? 'Guardando...' : 'Guardar'}
            </Boton>
            <button
              onClick={() => { logout(); setOpen(false); alNavegar(); }}
              className="flex items-center gap-1.5 text-sm text-tinta-suave hover:text-tinta transition-colors"
            >
              <LogOut className="w-3.5 h-3.5" />
              Cerrar sesión
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function Navigation() {
  const { token, esPlataforma } = useContext(AuthContext);
  const location = useLocation();
  const [isMenuOpen, setIsMenuOpen] = useState(false);

  const isActive = (path) => location.pathname === path;

  const cerrar = () => setIsMenuOpen(false);

  return (
    <nav className="border-b border-borde bg-white sticky top-0 z-40">
      <div className="max-w-7xl mx-auto px-5 sm:px-6">
        <div className="flex justify-between items-center h-16 sm:h-20 gap-4">
          <div className="flex items-center gap-8 min-w-0">
            <Link to="/" className="flex-shrink-0" aria-label="Ir al inicio de Rezonar">
              <img src="/logo-negro.png" alt="Rezonar" className="h-8 sm:h-9" />
            </Link>

            {token && (
              <div className="hidden md:flex gap-1">
                {[...SECCIONES, ...(esPlataforma ? SECCIONES_PLATAFORMA : [])].map(({ a, texto }) => (
                  <Link
                    key={a}
                    to={a}
                    className={`px-3 py-2 rounded-full text-sm font-semibold transition-colors ${
                      isActive(a)
                        ? 'bg-verde-claro text-verde-oscuro'
                        : 'text-tinta-media hover:text-tinta hover:bg-papel-hueso'
                    }`}
                  >
                    {texto}
                  </Link>
                ))}
              </div>
            )}
          </div>

          <div className="hidden md:flex items-center gap-4">
            {token ? (
              <>
                <LocationIndicator />
                <NotificationBell />
                <MenuPerfil />
              </>
            ) : (
              <>
                <Link
                  to="/artistas"
                  className="text-sm font-semibold text-tinta-media hover:text-tinta transition-colors"
                >
                  Para artistas
                </Link>
                <Link
                  to="/login"
                  className="text-sm font-semibold text-tinta-media hover:text-tinta transition-colors"
                >
                  Entrar
                </Link>
                <Boton a="/register" tamano="sm">Crear mi página</Boton>
              </>
            )}
          </div>

          <button
            onClick={() => setIsMenuOpen(!isMenuOpen)}
            className="md:hidden text-tinta-media hover:text-tinta transition-colors p-1"
            aria-label={isMenuOpen ? 'Cerrar menú' : 'Abrir menú'}
            aria-expanded={isMenuOpen}
          >
            {isMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
          </button>
        </div>

        {isMenuOpen && (
          <div className="md:hidden pb-6 pt-2 border-t border-borde space-y-1">
            {token ? (
              <>
                {[...SECCIONES, ...(esPlataforma ? SECCIONES_PLATAFORMA : [])].map(({ a, texto }) => (
                  <Link
                    key={a}
                    to={a}
                    onClick={cerrar}
                    className={`block px-3 py-2.5 rounded-xl font-semibold transition-colors ${
                      isActive(a)
                        ? 'bg-verde-claro text-verde-oscuro'
                        : 'text-tinta-media hover:text-tinta hover:bg-papel-hueso'
                    }`}
                  >
                    {texto}
                  </Link>
                ))}
                <div className="flex items-center gap-4 px-3 pt-4 mt-2 border-t border-borde">
                  <LocationIndicator />
                  <NotificationBell />
                </div>
                <div className="px-3 pt-3">
                  <MenuPerfil alNavegar={cerrar} />
                </div>
              </>
            ) : (
              <>
                <Link
                  to="/artistas"
                  onClick={cerrar}
                  className="block px-3 py-2.5 rounded-xl font-semibold text-tinta-media hover:text-tinta hover:bg-papel-hueso transition-colors"
                >
                  Para artistas
                </Link>
                <Link
                  to="/login"
                  onClick={cerrar}
                  className="block px-3 py-2.5 rounded-xl font-semibold text-tinta-media hover:text-tinta hover:bg-papel-hueso transition-colors"
                >
                  Entrar
                </Link>
                <div className="px-3 pt-3">
                  <Boton a="/register" onClick={cerrar} className="w-full">Crear mi página</Boton>
                </div>
              </>
            )}
          </div>
        )}
      </div>
    </nav>
  );
}

export default Navigation;
