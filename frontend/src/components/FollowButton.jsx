import React, { useState, useEffect, useContext } from 'react';
import { useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';

function FollowButton({ pageId }) {
  const { token, apiUrl, user } = useContext(AuthContext);
  const navigate = useNavigate();
  const [isFollowing, setIsFollowing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [notificationType, setNotificationType] = useState('all');

  useEffect(() => {
    if (token && pageId) {
      checkFollowStatus();
    } else {
      setLoading(false);
    }
  }, [token, pageId]);

  const checkFollowStatus = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php?page_id=${pageId}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setIsFollowing(data.is_following);
      if (data.is_following) {
        setNotificationType(data.notify_all_events ? 'all' : 'nearby');
      }
    } catch (err) {
      console.error('Error checking follow status:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleFollow = async () => {
    if (!token) {
      navigate('/login');
      return;
    }

    setShowModal(true);
  };

  const handleConfirmFollow = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          page_id: pageId,
          notify_all_events: notificationType === 'all',
          max_distance_km: 30
        })
      });

      if (response.ok) {
        setIsFollowing(true);
        setShowModal(false);
      }
    } catch (err) {
      console.error('Error following page:', err);
      alert('Error al seguir la página');
    }
  };

  const handleUnfollow = async () => {
    if (!confirm('¿Dejar de seguir esta página?')) {
      return;
    }

    try {
      const response = await fetch(`${apiUrl}/pages/follow.php?page_id=${pageId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (response.ok) {
        setIsFollowing(false);
      }
    } catch (err) {
      console.error('Error unfollowing page:', err);
      alert('Error al dejar de seguir la página');
    }
  };

  if (loading) {
    return null;
  }

  return (
    <>
      <button
        onClick={isFollowing ? handleUnfollow : handleFollow}
        className={`px-4 py-2 font-bold transition ${
          isFollowing
            ? 'bg-gray-800 text-white hover:bg-gray-700'
            : 'bg-white text-black hover:bg-gray-200'
        }`}
      >
        {isFollowing ? 'SIGUIENDO' : 'SEGUIR'}
      </button>

      {showModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg max-w-md w-full p-6">
            <h3 className="text-xl font-bold text-gray-900 mb-4">
              ¿Qué eventos quieres recibir?
            </h3>

            <div className="space-y-3 mb-6">
              <label
                className={`flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer transition ${
                  notificationType === 'all'
                    ? 'border-blue-600 bg-blue-50'
                    : 'border-gray-300 hover:border-gray-400'
                }`}
              >
                <input
                  type="radio"
                  name="notificationType"
                  checked={notificationType === 'all'}
                  onChange={() => setNotificationType('all')}
                  className="mt-1"
                />
                <div>
                  <div className="font-semibold text-gray-900">Todos los eventos</div>
                  <div className="text-sm text-gray-600">
                    Recibirás notificaciones de todos los eventos que publique esta página
                  </div>
                </div>
              </label>

              <label
                className={`flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer transition ${
                  notificationType === 'nearby'
                    ? 'border-blue-600 bg-blue-50'
                    : 'border-gray-300 hover:border-gray-400'
                }`}
              >
                <input
                  type="radio"
                  name="notificationType"
                  checked={notificationType === 'nearby'}
                  onChange={() => setNotificationType('nearby')}
                  className="mt-1"
                />
                <div>
                  <div className="font-semibold text-gray-900">Solo eventos cercanos</div>
                  <div className="text-sm text-gray-600">
                    Solo eventos a menos de 30 km de tu ubicación
                  </div>
                </div>
              </label>
            </div>

            <div className="flex gap-3">
              <button
                onClick={handleConfirmFollow}
                className="flex-1 bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition-colors font-semibold"
              >
                Seguir página
              </button>
              <button
                onClick={() => setShowModal(false)}
                className="flex-1 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300 transition-colors font-semibold"
              >
                Cancelar
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default FollowButton;
