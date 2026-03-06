import React, { useState } from 'react';
import Navigation from '../components/Navigation';
import PageSearch from '../components/PageSearch';
import FollowingManager from '../components/FollowingManager';

function Pages() {
  const [activeTab, setActiveTab] = useState('search');

  return (
    <div className="min-h-screen bg-black text-white">
      <Navigation />

      <div className="max-w-7xl mx-auto px-6 py-16">
        <h1 className="text-4xl font-bold mb-8">PÁGINAS</h1>

        <div className="flex gap-4 mb-8">
          <button
            onClick={() => setActiveTab('search')}
            className={`px-6 py-3 font-bold transition ${
              activeTab === 'search'
                ? 'bg-white text-black'
                : 'bg-gray-900 text-gray-400 hover:text-white border border-gray-800'
            }`}
          >
            BUSCAR PÁGINAS
          </button>
          <button
            onClick={() => setActiveTab('following')}
            className={`px-6 py-3 font-bold transition ${
              activeTab === 'following'
                ? 'bg-white text-black'
                : 'bg-gray-900 text-gray-400 hover:text-white border border-gray-800'
            }`}
          >
            PÁGINAS QUE SIGO
          </button>
        </div>

        {activeTab === 'search' && <PageSearch />}
        {activeTab === 'following' && <FollowingManager />}
      </div>
    </div>
  );
}

export default Pages;
