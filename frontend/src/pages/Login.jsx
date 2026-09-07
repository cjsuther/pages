import React, { useState, useContext, useEffect } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { AuthContext } from '../App';
import { Helmet } from 'react-helmet-async';
import { PieDePagina } from '../components/Marco';
import { trackEvent } from '../utils/analytics';

/** Dónde se anota a dónde volver cuando hay que entrar primero. */
export const DESTINO = 'rezonar:despues-de-entrar';

/**
 * A dónde ir después de entrar.
 *
 * Se anota en sessionStorage y no en la URL porque entrar con Google se va del
 * sitio y vuelve: un parámetro no sobrevive ese rodeo.
 *
 * Sólo rutas de este sitio: un destino externo convertiría el login en un
 * trampolín para mandar gente a cualquier lado con la confianza que da haber
 * salido de acá.
 */
export function destinoSeguro(destino) {
  return typeof destino === 'string' && /^\/[^/\\]/.test(destino) ? destino : '/';
}

/** Lee el destino anotado y lo borra: sirve una sola vez. */
export function destinoPendiente() {
  const anotado = sessionStorage.getItem(DESTINO);
  sessionStorage.removeItem(DESTINO);

  return destinoSeguro(anotado);
}

function Login() {
  const { login, apiUrl } = useContext(AuthContext);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [error, setError] = useState('');

  useEffect(() => {
    const token = searchParams.get('token');
    const userParam = searchParams.get('user');
    const errorParam = searchParams.get('error');

    if (token && userParam) {
      try {
        const user = JSON.parse(decodeURIComponent(userParam));
        login(token, user);
        trackEvent.userLogin('oauth');
        navigate(destinoPendiente());
      } catch (err) {
        setError('Error al procesar el inicio de sesión');
      }
    } else if (errorParam) {
      setError('Error en autenticación: ' + errorParam);
    }
  }, [searchParams, login, navigate]);

  const handleGoogleLogin = () => {
    trackEvent.event('login_attempt', { method: 'google' });
    window.location.href = `${apiUrl}/auth/google-login.php`;
  };

  const handleAppleLogin = () => {
    trackEvent.event('login_attempt', { method: 'apple' });
    window.location.href = `${apiUrl}/auth/apple-login.php`;
  };

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet><title>Entrar — Rezonar</title></Helmet>

      <nav className="border-b border-borde">
        <div className="max-w-7xl mx-auto px-5 sm:px-6 h-16 sm:h-20 flex items-center">
          <Link to="/">
            <img src="/logo-negro.png" alt="Rezonar" className="h-8 sm:h-9" />
          </Link>
        </div>
      </nav>

      <div className="flex-1 flex items-center justify-center px-5 sm:px-6 py-16">
        <div className="max-w-md w-full">
          <div className="text-center">
            <h1 className="text-3xl sm:text-4xl font-bold tracking-tight text-balance">
              Entrá a Rezonar
            </h1>
            <p className="mt-3 text-tinta-media leading-relaxed">
              Con tu cuenta seguís a los artistas que te gustan, recibís un aviso cuando
              publican una fecha nueva y armás tu propia página.
            </p>
          </div>

          {error && (
            <div className="mt-6 px-4 py-3 rounded-xl border border-red-200 bg-red-50 text-red-800 text-sm">
              {error}
            </div>
          )}

          <button
            onClick={handleGoogleLogin}
            className="mt-8 w-full flex items-center justify-center gap-3 rounded-full bg-verde text-verde-tinta py-4 px-6 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
          >
            <svg className="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Continuar con Google
          </button>

          <p className="text-center text-tinta-suave text-sm mt-6">
            Al continuar aceptás nuestros términos y condiciones.
          </p>

          <p className="text-center text-sm mt-8 pt-8 border-t border-borde text-tinta-media">
            ¿Sos artista y querés saber qué te ofrecemos?{' '}
            <Link to="/artistas" className="text-verde-oscuro font-semibold hover:underline underline-offset-4">
              Mirá acá
            </Link>
          </p>
        </div>
      </div>

      <PieDePagina />
    </div>
  );
}

export default Login;
