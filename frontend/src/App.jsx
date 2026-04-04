import React, { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { HelmetProvider } from 'react-helmet-async';
import { usePageTracking } from './hooks/usePageTracking';
import Home from './pages/Home';
import Login from './pages/Login';
import Register from './pages/Register';
import Pages from './pages/Pages';
import MyPages from './pages/MyPages';
import PageEditor from './pages/PageEditor';
import PublicPage from './pages/PublicPage';
import EventDetail from './pages/EventDetail';

const API_URL = 'http://localhost:8000/api';
//const API_URL = 'https://rezon.ar/api';

export const AuthContext = React.createContext(null);

function AppRoutes() {
  usePageTracking();
  return null;
}

const SSO_ALLOWED_REDIRECTS = ['entradas.rezon.ar'];

function doSSOHandoff(redirect, token) {
  fetch('/api/auth/sso-init.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, redirect }),
    redirect: 'follow'
  }).then(response => {
    if (response.redirected) window.location.href = response.url;
  }).catch(err => console.error('SSO error:', err));
}

function App() {
  const [token, setToken] = useState(localStorage.getItem('token'));
  const [user, setUser] = useState(null);

  useEffect(() => {
    if (token) {
      const userData = JSON.parse(localStorage.getItem('user') || 'null');
      setUser(userData);
    }
  }, [token]);

  // SSO Bridge: detectar ?sso_redirect= en la URL al cargar
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const redirectParam = params.get('sso_redirect');
    if (redirectParam && SSO_ALLOWED_REDIRECTS.includes(redirectParam)) {
      const currentToken = localStorage.getItem('token');
      if (currentToken) {
        doSSOHandoff(redirectParam, currentToken);
      } else {
        sessionStorage.setItem('sso_pending_redirect', redirectParam);
        window.location.href = '/login';
      }
    }
  }, []);

  // SSO Bridge: completar redirect pendiente luego del login
  useEffect(() => {
    if (token) {
      const pendingRedirect = sessionStorage.getItem('sso_pending_redirect');
      if (pendingRedirect && SSO_ALLOWED_REDIRECTS.includes(pendingRedirect)) {
        sessionStorage.removeItem('sso_pending_redirect');
        doSSOHandoff(pendingRedirect, token);
      }
    }
  }, [token]);

  const login = (token, user) => {
    localStorage.setItem('token', token);
    localStorage.setItem('user', JSON.stringify(user));
    setToken(token);
    setUser(user);
  };

  const logout = () => {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setToken(null);
    setUser(null);
  };

  const updateUser = (updatedUser) => {
    localStorage.setItem('user', JSON.stringify(updatedUser));
    setUser(updatedUser);
  };

  return (
    <HelmetProvider>
      <AuthContext.Provider value={{ token, user, login, logout, updateUser, apiUrl: API_URL }}>
        <BrowserRouter>
          <AppRoutes />
          <Routes>
            <Route path="/" element={<Home />} />
            <Route path="/login" element={!token ? <Login /> : <Navigate to="/" />} />
            <Route path="/register" element={!token ? <Register /> : <Navigate to="/" />} />
            <Route path="/pages" element={token ? <Pages /> : <Navigate to="/login" />} />
            <Route path="/my-pages" element={token ? <MyPages /> : <Navigate to="/login" />} />
            <Route path="/page/:id" element={token ? <PageEditor /> : <Navigate to="/login" />} />
            <Route path="/evento/:id" element={<EventDetail />} />
            <Route path="/:slug" element={<PublicPage />} />
          </Routes>
        </BrowserRouter>
      </AuthContext.Provider>
    </HelmetProvider>
  );
}

export default App;
