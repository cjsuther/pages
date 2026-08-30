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
import ItemEditor from './pages/ItemEditor';
import PublicPage from './pages/PublicPage';
import EventDetail from './pages/EventDetail';
import EstadoOrden from './pages/EstadoOrden';
import Autorizar from './pages/Autorizar';
import SubirImagen from './pages/SubirImagen';
import { paginaDelDominio } from './utils/dominio';

// La URL de la API sale de VITE_API_URL (ver .env.production). El valor por
// defecto es el de desarrollo, así que `npm run dev` funciona sin configurar
// nada y el build de producción no depende de editar este archivo a mano.
const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

export const AuthContext = React.createContext(null);

function AppRoutes() {
  usePageTracking();
  return null;
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
            {/* En un dominio propio la raíz es la página, no el home de
                Rezonar. El resto de las rutas —/evento, /entrada— andan igual
                en los dos dominios: es la misma aplicación. */}
            <Route
              path="/"
              element={paginaDelDominio()
                ? <PublicPage slugForzado={paginaDelDominio()} />
                : <Home />}
            />
            <Route path="/login" element={!token ? <Login /> : <Navigate to="/" />} />
            <Route path="/register" element={!token ? <Register /> : <Navigate to="/" />} />
            <Route path="/pages" element={token ? <Pages /> : <Navigate to="/login" />} />
            <Route path="/my-pages" element={token ? <MyPages /> : <Navigate to="/login" />} />
            <Route path="/page/:id" element={token ? <PageEditor /> : <Navigate to="/login" />} />
            <Route path="/page/:id/item/:linkId" element={token ? <ItemEditor /> : <Navigate to="/login" />} />
            <Route path="/evento/:id" element={<EventDetail />} />
            <Route path="/entrada/:codigo" element={<EstadoOrden apiUrl={API_URL} />} />
            {/* Antes de /:slug: es una ruta del sitio, no una página pública. */}
            <Route path="/oauth/authorize" element={<Autorizar />} />
            <Route path="/subir/:token" element={<SubirImagen />} />
            <Route path="/:slug" element={<PublicPage />} />
          </Routes>
        </BrowserRouter>
      </AuthContext.Provider>
    </HelmetProvider>
  );
}

export default App;
