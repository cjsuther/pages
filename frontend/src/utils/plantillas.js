import { borde, alrededor } from './colores';

/**
 * Ancho de la columna de una página pública.
 *
 * Las páginas se muestran siempre en formato mobile, también en una pantalla
 * grande: la misma columna angosta centrada. Es lo que hace Linktree, y evita
 * que una página se vea de una manera en el teléfono —por donde entra casi
 * todo el mundo— y de otra en la computadora.
 *
 * Ojo al tocar las plantillas: los prefijos `md:` de Tailwind miran el ancho
 * de la ventana, no el de este contenedor. Angostar la columna no alcanza para
 * que una grilla `md:grid-cols-2` se vuelva de una sola columna; hay que
 * sacarla a mano.
 */
export const ANCHO_COLUMNA = 'max-w-[580px]';

/**
 * Clases de la caja donde vive el contenido.
 *
 * Hasta 640px la caja es la pantalla: ocupa todo el ancho, sin margen ni
 * esquinas redondeadas, que es como se ve en un teléfono. De ahí para arriba
 * sobra lugar a los costados, así que se despega y queda como un recuadro
 * apoyado sobre el color de la página.
 *
 * El corte es `sm:` y no `md:` a propósito: 640 es el primer breakpoint por
 * encima de los 580 de la caja, o sea el ancho a partir del cual realmente
 * sobra espacio.
 */
export const CLASES_CAJA = `${ANCHO_COLUMNA} mx-auto overflow-hidden sm:rounded-3xl sm:border`;

/**
 * Clases de lo que rodea al recuadro.
 *
 * El aire de arriba y abajo va acá como padding, y no como margen de la caja:
 * un margen vertical se escapa fuera del contenedor —colapso de márgenes— y
 * termina empujando la página entera hacia abajo, dejando ver una franja del
 * fondo del navegador arriba de todo.
 */
export const CLASES_ALREDEDOR = 'min-h-screen sm:py-8';

/**
 * El fondo —color e imagen— es de la caja, no de la pantalla.
 *
 * Antes se pintaba la pantalla entera: en una computadora la imagen de fondo
 * se derramaba por todos lados y el contenido flotaba encima sin forma. Ahora
 * queda contenido en el recuadro.
 *
 * La imagen ocupa el ancho de la caja y se apoya arriba, conservando su
 * proporción. Así se ve entera y del mismo tamaño en cualquier página: antes se
 * estiraba para cubrir todo el alto, y cuanto más contenido tenía la página más
 * se agrandaba, hasta quedar irreconocible.
 *
 * Debajo de donde termina la imagen sigue el color de fondo.
 */
export function estiloDeCaja({ backgroundColor, backgroundImage, textColor }) {
  return {
    backgroundColor,
    borderColor: borde(textColor) || 'transparent',
    ...(backgroundImage && {
      backgroundImage: `url(${backgroundImage})`,
      // Ancho completo y alto proporcional: la imagen no se recorta ni se
      // deforma, y se ve igual tenga la página dos links o veinte.
      backgroundSize: '100% auto',
      backgroundPosition: 'top center',
      backgroundRepeat: 'no-repeat',
    }),
  };
}

/**
 * Lo que queda alrededor del recuadro.
 *
 * Un tono distinto del fondo de la página, para que el recuadro se vea
 * apoyado sobre algo. Sólo aparece cuando sobra ancho: en un teléfono el
 * recuadro ocupa toda la pantalla y esto no se ve nunca.
 */
export function estiloDeAlrededor({ backgroundColor, textColor }) {
  return { backgroundColor: alrededor(backgroundColor, textColor) || backgroundColor, color: textColor };
}
