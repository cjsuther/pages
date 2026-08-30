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
