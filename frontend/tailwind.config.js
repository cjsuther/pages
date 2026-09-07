export default {
  content: [
    "./index.html",
    "./src/**/*.{js,jsx}"
  ],
  theme: {
    extend: {
      // Una sola definición para todo: las utilidades de Tailwind y el body
      // salen de acá, así no puede quedar media aplicación con otra.
      fontFamily: {
        sans: [
          'Poppins',
          '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto',
          'Helvetica Neue', 'Arial', 'sans-serif',
        ],
      },

      // La identidad del sitio: fondo blanco, tipografía negra y el verde de
      // la marca como único acento. Los grises tienen una pizca de verde para
      // que no se lean como el gris por defecto de Tailwind al lado del acento.
      colors: {
        verde: {
          DEFAULT: '#6FBE44',  // el de la marca; relleno de botones y marcas
          claro: '#EDF6E6',    // fondos de apoyo
          medio: '#CDE6BB',    // bordes y separadores sobre fondo verde
          oscuro: '#3E7C1E',   // verde sobre blanco que sí se lee (texto, links)
          tinta: '#14310A',    // texto sobre relleno verde
        },
        tinta: {
          DEFAULT: '#111311',  // negro tipográfico
          media: '#4A4F45',    // texto secundario
          suave: '#6E7367',    // texto terciario, placeholders
        },
        papel: {
          DEFAULT: '#FFFFFF',
          hueso: '#F7F8F5',    // superficies levantadas sobre blanco
        },
        borde: {
          DEFAULT: '#E2E6DC',
          fuerte: '#C8CEBF',
        },
      },
    },
  },
  plugins: [],
}
