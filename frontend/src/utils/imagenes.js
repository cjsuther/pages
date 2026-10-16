/**
 * Topes de peso de las imágenes. Los mismos que valida la API en
 * api/handlers/UploadHandler.php: acá sólo se avisa antes de subir.
 */

export const MAX_BYTES = 5 * 1024 * 1024;

// WhatsApp descarta las vistas previas de más de ~600 KB y muestra el ícono
// del sitio en lugar del afiche del evento (#6).
export const MAX_BYTES_EVENTO = 600 * 1024;

/** El peso como lo diría una persona: "601 KB", "2,1 MB". */
export function describirPeso(bytes) {
  if (bytes < 1024 * 1024) {
    return `${Math.ceil(bytes / 1024)} KB`;
  }
  const mb = Math.round((bytes / (1024 * 1024)) * 10) / 10;
  return `${String(mb).replace('.', ',')} MB`;
}

/** Mensaje para el afiche de un evento que pasa el tope, o null si entra. */
export function excesoImagenEvento(file) {
  if (file.size <= MAX_BYTES_EVENTO) return null;
  return `La imagen pesa ${describirPeso(file.size)}; para eventos el máximo es 600 KB`;
}
