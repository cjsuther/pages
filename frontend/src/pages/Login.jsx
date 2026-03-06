import React, { useState, useContext, useEffect } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { AuthContext } from '../App';
import { trackEvent } from '../utils/analytics';

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
        navigate('/');
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
    <div className="min-h-screen bg-black text-white">
      <nav className="border-b border-gray-800">
        <div className="max-w-7xl mx-auto px-6 py-6">
          <Link to="/">
            <img src="/logo.png" alt="Rezonar" className="h-10" />
          </Link>
        </div>
      </nav>

      <div className="flex items-center justify-center px-6 py-24">
        <div className="max-w-md w-full space-y-8">
          <div className="text-center space-y-4">
            <h1 className="text-5xl font-black tracking-tight">BIENVENIDO</h1>
            <p className="text-xl text-gray-400">Inicia sesión para continuar</p>
          </div>

          {error && (
            <div className="bg-red-900 border border-red-700 text-red-200 px-6 py-4 font-medium">
              {error}
            </div>
          )}

          <div className="space-y-4">
            <button
              onClick={handleGoogleLogin}
              className="w-full flex items-center justify-center gap-3 bg-white text-black py-4 px-6 font-bold hover:bg-gray-200 transition"
            >
              <svg className="w-5 h-5" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
              </svg>
              CONTINUAR CON GOOGLE
            </button>
          </div>

          <p className="text-center text-gray-500 text-sm mt-8">
            Al continuar, aceptas nuestros términos y condiciones
          </p>
        </div>
      </div>

      <footer className="border-t border-gray-800 py-12">
        <div className="max-w-7xl mx-auto px-6">
          <div className="flex flex-col md:flex-row justify-between items-center gap-6">
            <img src="/logo.png" alt="Rezonar" className="h-8 opacity-50" />
            <p className="text-gray-500 font-medium">© 2026 REZONAR</p>
          </div>
        </div>
      </footer>
    </div>
  );
}

export default Login;
