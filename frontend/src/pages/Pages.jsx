import React, { useState, useEffect, useContext } from 'react';
import { Helmet } from 'react-helmet-async';
import { Compass, Users, Bell } from 'lucide-react';
import { AuthContext } from '../App';
import Marco from '../components/Marco';
import PageSearch from '../components/PageSearch';
import FollowingManager from '../components/FollowingManager';
import PanelAlertas from '../components/PanelAlertas';
import { Rotulo } from '../components/ui';

/**
 * Seguir páginas: descubrir, administrar lo que seguís y configurar los avisos.
 *
 * Las tres cosas son etapas de lo mismo y antes estaban en dos pestañas con la
 * activación de notificaciones colgada arriba de todo, que es donde nadie la
 * relaciona con nada. Ahora cada etapa es una pestaña y el número de páginas
 * seguidas se ve sin entrar.
 */
function Pages() {
  const { token, apiUrl } = useContext(AuthContext);
  const [seccion, setSeccion] = useState('descubrir');
  const [cantidad, setCantidad] = useState(null);

  useEffect(() => {
    const cargar = async () => {
      try {
        const response = await fetch(`${apiUrl}/pages/following.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        });
        const data = await response.json();
        setCantidad((data.following || []).length);
      } catch (err) {
        console.error('Error fetching following:', err);
      }
    };

    cargar();
  }, [apiUrl, token]);

  const SECCIONES = [
    { id: 'descubrir', texto: 'Descubrir', icono: Compass },
    { id: 'siguiendo', texto: 'Las que sigo', icono: Users, cuenta: cantidad },
    { id: 'alertas', texto: 'Mis alertas', icono: Bell },
  ];

  return (
    <Marco ancho="ancho">
      <Helmet>
        <title>Seguir páginas — Rezonar</title>
      </Helmet>

      <header className="mb-8">
        <Rotulo>Tu gente</Rotulo>
        <h1 className="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-balance">
          Seguí páginas y elegí de qué te enterás
        </h1>
        <p className="mt-3 text-tinta-media max-w-2xl">
          Cuando seguís a un artista te avisamos cada vez que publica una fecha. Vos decidís
          si querés todas sus fechas o solo las que caen cerca tuyo.
        </p>
      </header>

      <div className="flex flex-wrap gap-2 mb-8 border-b border-borde pb-4">
        {SECCIONES.map(({ id, texto, icono: Icono, cuenta }) => (
          <button
            key={id}
            onClick={() => setSeccion(id)}
            aria-current={seccion === id ? 'page' : undefined}
            className={`inline-flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold border transition-colors ${
              seccion === id
                ? 'bg-verde text-verde-tinta border-verde'
                : 'bg-white text-tinta-media border-borde hover:border-borde-fuerte hover:text-tinta'
            }`}
          >
            <Icono className="w-4 h-4" />
            {texto}
            {cuenta !== null && cuenta !== undefined && (
              <span
                className={`tabular-nums text-xs px-1.5 py-0.5 rounded-full ${
                  seccion === id ? 'bg-verde-tinta/15' : 'bg-papel-hueso'
                }`}
              >
                {cuenta}
              </span>
            )}
          </button>
        ))}
      </div>

      {seccion === 'descubrir' && <PageSearch />}
      {seccion === 'siguiendo' && <FollowingManager alCambiar={setCantidad} />}
      {seccion === 'alertas' && <PanelAlertas />}
    </Marco>
  );
}

export default Pages;
