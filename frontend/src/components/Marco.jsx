import React from 'react';
import { Link } from 'react-router-dom';
import Navigation from './Navigation';

/**
 * El marco del sitio: barra arriba, contenido, pie abajo.
 *
 * Todas las pantallas de Rezonar lo usan. Que el ancho, el fondo y el pie
 * estén definidos en un solo lugar es lo que hace que el rediseño se sostenga
 * cuando alguien agregue una pantalla nueva el mes que viene.
 */

const ANCHOS = {
  angosto: 'max-w-3xl',
  normal: 'max-w-5xl',
  ancho: 'max-w-7xl',
};

export function PieDePagina() {
  return (
    <footer className="border-t border-borde mt-auto">
      <div className="max-w-7xl mx-auto px-5 sm:px-6 py-10">
        <div className="flex flex-col md:flex-row justify-between items-center gap-6">
          <Link to="/">
            <img src="/logo-negro.png" alt="Rezonar" className="h-7 opacity-70 hover:opacity-100 transition-opacity" />
          </Link>

          <nav className="flex flex-wrap justify-center gap-x-7 gap-y-2 text-sm">
            <Link to="/artistas" className="text-tinta-media hover:text-verde-oscuro font-medium transition-colors">
              Para artistas
            </Link>
            <Link to="/pages" className="text-tinta-media hover:text-verde-oscuro font-medium transition-colors">
              Descubrir páginas
            </Link>
            <Link to="/asistentes" className="text-tinta-media hover:text-verde-oscuro font-medium transition-colors">
              Conectar un asistente
            </Link>
            <a href="mailto:hola@rezon.ar" className="text-tinta-media hover:text-verde-oscuro font-medium transition-colors">
              Soporte
            </a>
          </nav>

          <p className="text-sm text-tinta-suave">© {new Date().getFullYear()} Rezonar</p>
        </div>
      </div>
    </footer>
  );
}

function Marco({ ancho = 'normal', className = '', sinPie = false, children }) {
  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Navigation />
      <main className={`flex-1 w-full ${ANCHOS[ancho] || ANCHOS.normal} mx-auto px-5 sm:px-6 py-10 sm:py-14 ${className}`}>
        {children}
      </main>
      {!sinPie && <PieDePagina />}
    </div>
  );
}

export default Marco;
