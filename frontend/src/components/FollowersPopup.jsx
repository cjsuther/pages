import React, { useState, useContext } from 'react';
import { AuthContext } from '../App';
import { Users, X } from 'lucide-react';

function FollowersPopup({ pageId, followerCount, className = '' }) {
  const { apiUrl } = useContext(AuthContext);
  const [open, setOpen] = useState(false);
  const [followers, setFollowers] = useState([]);
  const [loading, setLoading] = useState(false);

  const openPopup = async (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (!followerCount) return;
    setOpen(true);
    setLoading(true);
    try {
      const res = await fetch(`${apiUrl}/public/followers.php?page_id=${pageId}`);
      const data = await res.json();
      setFollowers(data.followers || []);
    } catch (err) {
      console.error('Error fetching followers:', err);
    } finally {
      setLoading(false);
    }
  };

  const close = (e) => {
    e.stopPropagation();
    setOpen(false);
  };

  return (
    <>
      <button
        onClick={openPopup}
        className={`flex items-center gap-2 text-sm opacity-70 transition ${followerCount ? 'hover:opacity-100 cursor-pointer' : 'cursor-default'} ${className}`}
      >
        <Users className="w-4 h-4" />
        <span>{followerCount || 0} {followerCount === 1 ? 'seguidor' : 'seguidores'}</span>
      </button>

      {open && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
          onClick={close}
        >
          <div
            className="bg-white rounded-lg max-w-md w-full max-h-96 overflow-hidden flex flex-col shadow-xl"
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200">
              <h3 className="text-base font-bold text-gray-900">
                {followerCount} {followerCount === 1 ? 'seguidor' : 'seguidores'}
              </h3>
              <button onClick={() => setOpen(false)} className="text-gray-400 hover:text-gray-600 transition">
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="overflow-y-auto flex-1">
              {loading ? (
                <p className="p-4 text-center text-sm text-gray-500">Cargando...</p>
              ) : followers.length === 0 ? (
                <p className="p-4 text-center text-sm text-gray-500">Sin seguidores</p>
              ) : (
                <ul className="divide-y divide-gray-100">
                  {followers.map((f, i) => (
                    <li key={i} className="px-4 py-3 flex items-center justify-between gap-4">
                      <div className="min-w-0">
                        {f.page_slug ? (
                          <a
                            href={`/${f.page_slug}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-gray-900 hover:underline truncate block"
                          >
                            {f.page_title || f.email}
                          </a>
                        ) : (
                          <span className="font-medium text-gray-900 truncate block">{f.email}</span>
                        )}
                      </div>
                      <span className="text-xs text-gray-400 whitespace-nowrap flex-shrink-0">
                        {new Date(f.followed_at).toLocaleDateString('es-AR', {
                          day: '2-digit',
                          month: '2-digit',
                          year: 'numeric',
                        })}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default FollowersPopup;
