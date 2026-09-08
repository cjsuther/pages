import React, { useState, useEffect, useContext } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, Check } from 'lucide-react';
import { AuthContext } from '../App';
import { textoSobre, conAlfa } from '../utils/colores';
import { RADIOS_KM, RADIO_POR_DEFECTO, cuerpoDePreferencia, leerPreferencia } from '../utils/alertas';
import { Boton, Modal } from './ui';
import { useBloqueoDeScroll } from '../hooks/useBloqueoDeScroll';
import EleccionDeAlerta from './EleccionDeAlerta';

/**
 * Botón de seguir.
 *
 * `colores` es opcional: cuando lo dibuja una página pública llega la paleta de
 * esa página y todo sale de ahí, porque esa página es del artista y no nuestra.
 * En el sitio de Rezonar no llega, y ahí usa el verde de la marca.
 *
 * Seguir abre la elección de qué avisos querer: es la decisión que hace que
 * seguir sirva de algo, y preguntarla acá evita que quede en un valor por
 * defecto que nadie revisó nunca.
 */
function FollowButton({ pageId, colores = null }) {
  const { token, apiUrl } = useContext(AuthContext);
  const navigate = useNavigate();

  const [isFollowing, setIsFollowing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const [modo, setModo] = useState('todas');
  const [radio, setRadio] = useState(RADIO_POR_DEFECTO);

  useBloqueoDeScroll(showModal);

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
        const { modo: m, radio: r } = leerPreferencia(data);
        setModo(m);
        setRadio(r);
      }
    } catch (err) {
      console.error('Error checking follow status:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleFollow = () => {
    if (!token) {
      navigate('/login');
      return;
    }

    setShowModal(true);
  };

  const guardar = async () => {
    setGuardando(true);
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(cuerpoDePreferencia(pageId, modo, radio))
      });

      if (response.ok) {
        setIsFollowing(true);
        setShowModal(false);
      }
    } catch (err) {
      console.error('Error following page:', err);
    } finally {
      setGuardando(false);
    }
  };

  const dejarDeSeguir = async () => {
    setGuardando(true);
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php?page_id=${pageId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (response.ok) {
        setIsFollowing(false);
        setShowModal(false);
      }
    } catch (err) {
      console.error('Error unfollowing page:', err);
    } finally {
      setGuardando(false);
    }
  };

  if (loading) {
    return null;
  }

  // Con paleta de la página, el botón se pinta con sus colores. Sin paleta —el
  // sitio de Rezonar— usa los botones del sistema.
  const botonPropio = (
    <button
      onClick={isFollowing ? () => setShowModal(true) : handleFollow}
      className="inline-flex items-center gap-2 px-4 py-2 rounded-full font-semibold transition-opacity hover:opacity-90"
      style={{
        backgroundColor: colores?.boton,
        color: colores ? textoSobre(colores.boton, colores.texto) : undefined,
      }}
    >
      {isFollowing ? <><Check className="w-4 h-4" /> Siguiendo</> : 'Seguir'}
    </button>
  );

  const botonDelSistema = isFollowing ? (
    <Boton variante="secundario" tamano="sm" onClick={() => setShowModal(true)}>
      <Check className="w-4 h-4" /> Siguiendo
    </Boton>
  ) : (
    <Boton tamano="sm" onClick={handleFollow}>
      <Bell className="w-4 h-4" /> Seguir
    </Boton>
  );

  return (
    <>
      {colores ? botonPropio : botonDelSistema}

      {/* El modal también sale con la paleta de la página cuando la hay: un
          recuadro blanco del sistema encima de una página oscura se lee como
          si fuera de otro sitio. */}
      {showModal && colores ? (
        <div
          className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
          onClick={() => setShowModal(false)}
        >
          <div
            role="dialog"
            aria-modal="true"
            className="rounded-2xl max-w-md w-full p-6 max-h-[90vh] overflow-y-auto"
            style={{
              backgroundColor: colores.tarjeta,
              color: colores.texto,
              border: `1px solid ${colores.bordeTarjeta}`,
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h3 className="text-xl font-bold mb-1">
              {isFollowing ? 'Tus avisos de esta página' : '¿De qué querés enterarte?'}
            </h3>
            <p className="text-sm opacity-70 mb-5">
              Te llega una notificación cuando publica una fecha nueva.
            </p>

            <div className="space-y-3 mb-6">
              {[
                ['todas', 'Todas sus fechas', 'Cada show que publique, toque donde toque.'],
                ['cerca', 'Solo si es cerca', `Nada más lo que caiga a menos de ${radio} km tuyo.`],
              ].map(([id, titulo, detalle]) => (
                <label
                  key={id}
                  className="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition"
                  style={
                    modo === id
                      ? { borderColor: colores.acento, backgroundColor: conAlfa(colores.acento, 0.15) }
                      : { borderColor: colores.bordeTarjeta }
                  }
                >
                  <input
                    type="radio"
                    name="alerta-pagina"
                    checked={modo === id}
                    onChange={() => setModo(id)}
                    className="mt-1"
                  />
                  <span>
                    <span className="block font-semibold">{titulo}</span>
                    <span className="block text-sm opacity-70 mt-0.5">{detalle}</span>
                  </span>
                </label>
              ))}

              {modo === 'cerca' && (
                <div className="flex flex-wrap gap-2 pl-4">
                  {RADIOS_KM.map((km) => (
                    <button
                      key={km}
                      type="button"
                      onClick={() => setRadio(km)}
                      className="px-3 py-1.5 rounded-full text-sm font-semibold border transition"
                      style={
                        radio === km
                          ? { backgroundColor: colores.boton, color: textoSobre(colores.boton, colores.texto), borderColor: 'transparent' }
                          : { borderColor: colores.bordeTarjeta, color: colores.texto }
                      }
                    >
                      {km} km
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div className="flex flex-wrap gap-3">
              <button
                onClick={guardar}
                disabled={guardando}
                className="flex-1 py-2.5 rounded-full font-semibold transition-opacity hover:opacity-90 disabled:opacity-50"
                style={{ backgroundColor: colores.boton, color: textoSobre(colores.boton, colores.texto) }}
              >
                {guardando ? 'Guardando...' : isFollowing ? 'Guardar' : 'Seguir página'}
              </button>
              <button
                onClick={() => setShowModal(false)}
                className="flex-1 py-2.5 rounded-full font-semibold border transition-opacity hover:opacity-80"
                style={{ borderColor: colores.bordeTarjeta, color: colores.texto }}
              >
                Cancelar
              </button>
            </div>

            {isFollowing && (
              <button
                onClick={dejarDeSeguir}
                disabled={guardando}
                className="mt-4 text-sm opacity-60 hover:opacity-100 transition-opacity"
              >
                Dejar de seguir
              </button>
            )}
          </div>
        </div>
      ) : (
        <Modal
          abierto={showModal}
          alCerrar={() => setShowModal(false)}
          titulo={isFollowing ? 'Tus avisos de esta página' : '¿De qué querés enterarte?'}
        >
          <p className="text-tinta-media -mt-3 mb-5">
            Te llega una notificación al teléfono cuando publica una fecha nueva.
          </p>

          <EleccionDeAlerta
            modo={modo}
            radio={radio}
            alCambiarModo={setModo}
            alCambiarRadio={setRadio}
            nombre="alerta-pagina"
          />

          <div className="flex flex-wrap gap-3 mt-6">
            <Boton onClick={guardar} disabled={guardando} className="flex-1">
              {guardando ? 'Guardando...' : isFollowing ? 'Guardar cambios' : 'Seguir página'}
            </Boton>
            <Boton variante="secundario" onClick={() => setShowModal(false)} className="flex-1">
              Cancelar
            </Boton>
          </div>

          {isFollowing && (
            <div className="mt-5 pt-5 border-t border-borde">
              <Boton variante="peligro" tamano="sm" onClick={dejarDeSeguir} disabled={guardando}>
                Dejar de seguir
              </Boton>
            </div>
          )}
        </Modal>
      )}
    </>
  );
}

export default FollowButton;
