import React, { useState, useContext, useEffect } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AuthContext } from '../App';
import { Helmet } from 'react-helmet-async';
import { PieDePagina } from '../components/Marco';
import { trackEvent } from '../utils/analytics';

function Register() {
  const { login, apiUrl } = useContext(AuthContext);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const token = searchParams.get('token');
    const userParam = searchParams.get('user');
    const errorParam = searchParams.get('error');

    if (token && userParam) {
      try {
        const user = JSON.parse(decodeURIComponent(userParam));
        login(token, user);
        trackEvent.userRegister('oauth');
        navigate('/dashboard');
      } catch (err) {
        setError('Error al procesar el registro');
      }
    } else if (errorParam) {
      setError('Error en autenticación: ' + errorParam);
    }
  }, [searchParams, login, navigate]);

  const handleGoogleLogin = () => {
    trackEvent.event('register_attempt', { method: 'google' });
    window.location.href = `${apiUrl}/auth/google-login.php`;
  };

  const handleAppleLogin = () => {
    trackEvent.event('register_attempt', { method: 'apple' });
    window.location.href = `${apiUrl}/auth/apple-login.php`;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');

    if (password !== confirmPassword) {
      setError('Las contraseñas no coinciden');
      return;
    }

    if (password.length < 6) {
      setError('La contraseña debe tener al menos 6 caracteres');
      return;
    }

    setLoading(true);

    try {
      const response = await fetch(`${apiUrl}/auth/register.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Error al registrarse');
      }

      login(data.token, data.user);
      trackEvent.userRegister('email');
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet><title>Crear cuenta — Rezonar</title></Helmet>

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
              Creá tu cuenta
            </h1>
            <p className="mt-3 text-tinta-media leading-relaxed">
              Gratis. Te sirve para seguir artistas y para armar tu propia página.
            </p>
          </div>

          {error && (
            <div className="mt-6 px-4 py-3 rounded-xl border border-red-200 bg-red-50 text-red-800 text-sm">
              {error}
            </div>
          )}

          <div className="space-y-3 mt-8">
            <button
              onClick={handleGoogleLogin}
              className="w-full flex items-center justify-center gap-3 rounded-full bg-white border border-borde-fuerte text-tinta py-3.5 px-6 font-semibold hover:border-tinta transition-colors"
            >
              <svg className="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
              </svg>
              Continuar con Google
            </button>

            <button
              onClick={handleAppleLogin}
              className="w-full flex items-center justify-center gap-3 rounded-full bg-white border border-borde-fuerte text-tinta py-3.5 px-6 font-semibold hover:border-tinta transition-colors"
            >
              <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>
              </svg>
              Continuar con Apple
            </button>
          </div>

          <div className="relative my-7">
            <div className="absolute inset-0 flex items-center" aria-hidden="true">
              <div className="w-full border-t border-borde" />
            </div>
            <div className="relative flex justify-center text-sm">
              <span className="px-4 bg-white text-tinta-suave">o con tu email</span>
            </div>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="register-email" className="block text-sm font-semibold text-tinta mb-1.5">
                Email
              </label>
              <input
                id="register-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none transition-colors"
                required
              />
            </div>

            <div>
              <label htmlFor="register-password" className="block text-sm font-semibold text-tinta mb-1.5">
                Contraseña
              </label>
              <input
                id="register-password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none transition-colors"
                required
              />
            </div>

            <div>
              <label htmlFor="register-password-confirm" className="block text-sm font-semibold text-tinta mb-1.5">
                Repetí la contraseña
              </label>
              <input
                id="register-password-confirm"
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none transition-colors"
                required
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full rounded-full bg-verde text-verde-tinta py-3.5 px-6 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {loading ? 'Creando tu cuenta...' : 'Crear cuenta'}
            </button>
          </form>

          <p className="text-center text-tinta-media text-sm mt-6">
            ¿Ya tenés cuenta?{' '}
            <Link to="/login" className="text-verde-oscuro font-semibold hover:underline underline-offset-4">
              Entrá
            </Link>
          </p>
        </div>
      </div>

      <PieDePagina />
    </div>
  );
}

export default Register;
