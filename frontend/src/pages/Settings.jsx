import React, { useState, useContext } from 'react';
import { Link } from 'react-router-dom';
import { AuthContext } from '../App';
import LocationSettings from '../components/LocationSettings';
import FollowingManager from '../components/FollowingManager';
import PageSearch from '../components/PageSearch';
import ClavesApi from '../components/ClavesApi';

function Settings() {
  const { token, user, logout } = useContext(AuthContext);
  const [activeTab, setActiveTab] = useState('location');

  if (!token) {
    return (
      <div className="min-h-screen bg-black text-white flex items-center justify-center p-4">
        <div className="bg-gray-900 border border-gray-800 p-8 text-center">
          <h2 className="text-2xl font-bold mb-4">Acceso Denegado</h2>
          <p className="text-gray-400 mb-6">Debes iniciar sesión para acceder al perfil</p>
          <Link to="/login" className="bg-white text-black px-6 py-2 font-bold hover:bg-gray-200 transition">
            Iniciar Sesión
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-black text-white">
      <nav className="border-b border-gray-800">
        <div className="max-w-7xl mx-auto px-6 py-6">
          <div className="flex justify-between items-center">
            <Link to="/">
              <img src="/logo.png" alt="Rezonar" className="h-10" />
            </Link>
            <div className="flex items-center gap-6">
              <Link to="/dashboard" className="text-gray-400 hover:text-white transition font-medium">
                Dashboard
              </Link>
              <div className="text-right">
                <p className="text-gray-500 text-sm font-medium">{user?.email}</p>
              </div>
              <button
                onClick={logout}
                className="text-gray-400 hover:text-white transition font-medium"
              >
                Salir de la Cuenta
              </button>
            </div>
          </div>
        </div>
      </nav>

      <div className="max-w-7xl mx-auto px-6 py-16">
        <h1 className="text-4xl font-bold mb-16">PERFIL</h1>

        <div className="grid grid-cols-1 lg:grid-cols-4 gap-8">
          <div className="lg:col-span-1">
            <div className="bg-gray-900 border border-gray-800 p-6">
              <nav className="space-y-3">
                <button
                  onClick={() => setActiveTab('location')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'location'
                      ? 'bg-white text-black'
                      : 'text-gray-400 hover:text-white'
                  }`}
                >
                  MI UBICACIÓN
                </button>

                <button
                  onClick={() => setActiveTab('search')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'search'
                      ? 'bg-white text-black'
                      : 'text-gray-400 hover:text-white'
                  }`}
                >
                  BUSCAR PÁGINAS
                </button>

                <button
                  onClick={() => setActiveTab('following')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'following'
                      ? 'bg-white text-black'
                      : 'text-gray-400 hover:text-white'
                  }`}
                >
                  PÁGINAS QUE SIGO
                </button>

                <button
                  onClick={() => setActiveTab('claves')}
                  className={`w-full text-left px-4 py-3 font-bold transition ${
                    activeTab === 'claves'
                      ? 'bg-white text-black'
                      : 'text-gray-400 hover:text-white'
                  }`}
                >
                  CLAVES DE API
                </button>
              </nav>
            </div>
          </div>

          <div className="lg:col-span-3">
            {activeTab === 'location' && <LocationSettings />}
            {activeTab === 'search' && <PageSearch />}
            {activeTab === 'following' && <FollowingManager />}
            {activeTab === 'claves' && <ClavesApi />}
          </div>
        </div>
      </div>
    </div>
  );
}

export default Settings;
