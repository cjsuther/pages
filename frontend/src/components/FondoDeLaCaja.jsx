import React from 'react';

/**
 * La imagen de fondo de una página, quieta mientras se scrollea.
 *
 * Antes esto era una línea de CSS —`background-attachment: fixed`— y en el
 * iPhone no funcionaba: WebKit ignora `fixed` y pinta el fondo como si fuera
 * `scroll`, así que la imagen se iba con el contenido. No es un bug de Safari
 * nada más: en iOS todos los navegadores son WebKit, también Chrome, y por eso
 * se veía igual de mal en los dos.
 *
 * El reemplazo es una capa propia pegada con `position: sticky`, que sí anda en
 * iOS. Son dos elementos y cada uno hace una cosa:
 *
 * - el de afuera cubre la caja entera —`inset-0` mide contra el padding, así
 *   que incluye el aire de arriba— y le marca a la capa de adentro hasta dónde
 *   puede deslizarse;
 * - el de adentro es la imagen, pegada al borde de arriba de la ventana.
 *
 * La imagen sigue siendo de la caja y no de la pantalla: lo que se salga del
 * recuadro lo recorta la caja, igual que antes.
 */
function FondoDeLaCaja({ imagen }) {
  if (!imagen) return null;

  return (
    // Detrás de todo el contenido. La caja se aísla —`isolate`— para que este
    // negativo no se vaya más atrás todavía y termine tapado por el color de
    // alrededor.
    <div className="absolute inset-0 pointer-events-none" style={{ zIndex: -1 }} aria-hidden="true">
      {/*
        Un alto de ventana: la imagen se apoya arriba y lo que no entra se
        recorta abajo, que es lo que hacía el fondo fijo contra la ventana.

        El ancho es el de la caja, no el de la ventana: por eso acá el tamaño
        es 100% y no los 580 píxeles en duro que necesitaba `fixed`.
      */}
      <div
        className="sticky top-0 h-screen"
        style={{
          backgroundImage: `url(${imagen})`,
          backgroundSize: '100% auto',
          backgroundPosition: 'top center',
          backgroundRepeat: 'no-repeat',
        }}
      />
    </div>
  );
}

export default FondoDeLaCaja;
