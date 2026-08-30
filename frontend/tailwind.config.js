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
    },
  },
  plugins: [],
}
