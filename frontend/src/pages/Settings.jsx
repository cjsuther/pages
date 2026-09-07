import React, { useState, useContext } from 'react';
import { Link } from 'react-router-dom';
import { AuthContext } from '../App';
import LocationSettings from '../components/LocationSettings';
import FollowingManager from '../components/FollowingManager';
import PageSearch from '../components/PageSearch';
import ClavesApi, { Conexiones } from '../components/ClavesApi';

function Settings() {
  const { token, user, logout } = useContext(AuthContext);
  const [activeTab, setActiveTab] = useState('location');

  if (!token) {
    return (
      <div className="min-h-screen bg-white text-tinta flex items-center justify-center p-4">
        <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 text-center">
          <h2 className="text-2xl font-bold mb-4">Acceso Denegado</h2>
          <p className="text-tinta-media mb-6">Debes iniciar sesión para acceder al perfil</p>
          <Link to="/login" className="bg-verde text-verde-tinta px-6 py-2 font-bold hover:bg-verde-oscuro hover:text-tinta transition">
            Iniciar Sesión
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-white text-tinta">
      <nav className="border-b border-borde">
        <div className="max-w-7xl mx-auto px-6 py-6">
          <div className="flex justify-between items-center">
            <Link to="/">
              <img src="/logo-negro.png" alt="Rezonar" className="h-10" />
            </Link>
            <div className="flex items-center gap-6">
              <Link to="/dashboard" className="text-tinta-media hover:text-tinta transition font-medium">
                Dashboard
              </Link>
              <div className="text-right">
                <p className="text-tinta-suave text-sm font-medium">{user?.email}</p>
              </div>
              <button
                onClick={logout}
                className="text-tinta-media hover:text-tinta transition font-medium"
              >
                Salir de la Cuenta
              </button>
            </div>
          </div>
        </div>
      </nav>

      <div className="max-w-7xl mx-auto px-6 py-16">
        <h1 className="text-4xl font-bold mb-16">Perfil</h1>

        <div className="grid grid-cols-1 lg:grid-cols-4 gap-8">
          <div className="lg:col-span-1">
            <div className="bg-white border border-borde rounded-2xl p-6">
              <nav className="space-y-3">
                <button
                  onClick={() => setActiveTab('location')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'location'
                      ? 'bg-verde text-verde-tinta'
                      : 'text-tinta-media hover:text-tinta'
                  }`}
                >
                  Mi ubicación
                </button>

                <button
                  onClick={() => setActiveTab('search')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'search'
                      ? 'bg-verde text-verde-tinta'
                      : 'text-tinta-media hover:text-tinta'
                  }`}
                >
                  Buscar páginas
                </button>

                <button
                  onClick={() => setActiveTab('following')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'following'
                      ? 'bg-verde text-verde-tinta'
                      : 'text-tinta-media hover:text-tinta'
                  }`}
                >
                  Páginas que sigo
                </button>

                <button
                  onClick={() => setActiveTab('claves')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'claves'
                      ? 'bg-verde text-verde-tinta'
                      : 'text-tinta-media hover:text-tinta'
                  }`}
                >
                  Claves de API
                </button>
              </nav>
            </div>
          </div>

          <div className="lg:col-span-3">
            {activeTab === 'location' && <LocationSettings />}
            {activeTab === 'search' && <PageSearch />}
            {activeTab === 'following' && <FollowingManager />}
            {activeTab === 'claves' && (
              <>
                <ClavesApi />
                <Conexiones />
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default Settings;
