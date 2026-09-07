import React, { useState, useEffect, useContext } from 'react';
import { Search, Compass } from 'lucide-react';
import { AuthContext } from '../App';
import TarjetaPagina from './TarjetaPagina';
import { Campo, Cargando, Rotulo, Tarjeta, Vacio } from './ui';

/**
 * Buscador de páginas para seguir.
 *
 * Con el campo vacío muestra las páginas nuevas en vez de un cartel pidiendo
 * que se escriba algo: quien entra a "descubrir" casi nunca sabe todavía a
 * quién buscar.
 *
 * El botón de seguir es el mismo componente que en el resto del sitio. Antes
 * esta pantalla tenía su propia copia del diálogo de preferencias, con el
 * radio de distancia clavado en 30 km: seguir desde acá y seguir desde la
 * página del artista guardaban cosas distintas.
 */
function PageSearch() {
  const { token, apiUrl } = useContext(AuthContext);

  const [busqueda, setBusqueda] = useState('');
  const [resultados, setResultados] = useState(null);
  const [sugeridas, setSugeridas] = useState([]);
  const [seguidas, setSeguidas] = useState(new Set());
  const [cargando, setCargando] = useState(false);

  useEffect(() => {
    cargarSeguidas();
    cargarSugeridas();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Se espera a que deje de tipear: una consulta por tecla es una consulta de más.
  useEffect(() => {
    const termino = busqueda.trim();

    if (termino.length < 2) {
      setResultados(null);
      return undefined;
    }

    const id = setTimeout(() => buscar(termino), 300);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busqueda]);

  const cargarSeguidas = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/following.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setSeguidas(new Set((data.following || []).map(p => p.id)));
    } catch (err) {
      console.error('Error fetching following:', err);
    }
  };

  const cargarSugeridas = async () => {
    try {
      const response = await fetch(`${apiUrl}/public/recent-pages.php`);
      const data = await response.json();
      setSugeridas(data.pages || []);
    } catch (err) {
      console.error('Error loading recent pages:', err);
    }
  };

  const buscar = async (termino) => {
    setCargando(true);
    try {
      const response = await fetch(`${apiUrl}/public/search.php?q=${encodeURIComponent(termino)}`);
      const data = await response.json();
      setResultados((data.results || []).filter(r => r.type === 'page'));
    } catch (err) {
      console.error('Error searching pages:', err);
      setResultados([]);
    } finally {
      setCargando(false);
    }
  };

  // Lo que ya seguís no se ofrece de nuevo: para eso está la otra pestaña.
  const sinSeguidas = (lista) => lista.filter(p => !seguidas.has(p.id));

  const mostrando = resultados !== null ? sinSeguidas(resultados) : sinSeguidas(sugeridas);

  return (
    <div className="space-y-6">
      <div className="relative">
        <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-tinta-suave pointer-events-none" />
        <Campo
          type="search"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          placeholder="Buscá por nombre de artista, banda, ciclo o sala..."
          aria-label="Buscar páginas"
          className="pl-12 py-4"
        />
      </div>

      <Rotulo className="block">
        {resultados !== null
          ? `${mostrando.length} ${mostrando.length === 1 ? 'resultado' : 'resultados'}`
          : 'Páginas nuevas en Rezonar'}
      </Rotulo>

      {cargando ? (
        <Cargando texto="Buscando..." />
      ) : mostrando.length === 0 ? (
        <Tarjeta>
          <Vacio
            icono={Compass}
            titulo={
              resultados !== null
                ? 'No encontramos páginas con ese nombre'
                : 'Todavía no hay páginas para sugerirte'
            }
            detalle={
              resultados !== null
                ? 'Probá con menos palabras. Si el artista no está en Rezonar, pedile que arme su página: es gratis.'
                : null
            }
          />
        </Tarjeta>
      ) : (
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {mostrando.map((pagina) => (
            <TarjetaPagina key={pagina.id} pagina={pagina} />
          ))}
        </div>
      )}
    </div>
  );
}

export default PageSearch;
