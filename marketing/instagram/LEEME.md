# Posteos para el feed de Instagram

Catorce posteos listos para publicar, con su bajada. Ocho le hablan al artista y
seis a su público.

## Los dos mundos

Quien pasa el feed tiene que saber en medio segundo si ese posteo le habla a él
o no. Por eso no cambia un detalle: se da vuelta el fondo entero.

| | Para artistas | Para el público |
|---|---|---|
| Fondo | Negro `#111311` | Blanco |
| Acento | Verde `#6FBE44` | El mismo verde |
| Verde de texto | `#6FBE44` | `#3E7C1E`, que sí contrasta sobre blanco |
| Etiqueta | Píldora verde rellena | Píldora con contorno |
| Logo | Versión clara | Versión negra |

El verde de la marca es el mismo en los dos. Es lo que los mantiene de la misma
casa siendo opuestos.

Cada posteo tiene una pieza visual distinta: una notificación, una entrada con
QR, un chat, un mapa. Catorce tarjetas con la misma composición se leen como
relleno, y en un feed eso se nota más que en cualquier otro lado.

## Qué hay acá

```
artistas/   las ocho imágenes, 1080 × 1350
publico/    las seis imágenes, 1080 × 1350
textos.md   todas las bajadas, para copiar y pegar
html/       la fuente de cada imagen
```

El formato es 4:5, que es el que más alto ocupa en el feed. En cuadrado se
desperdicia un tercio de la pantalla.

## Orden sugerido

No van alternados uno y uno. Conviene publicar de a tandas de tres o cuatro del
mismo mundo: la grilla del perfil se lee de a filas de tres, y así cada fila
queda de un color y se entiende sola.

Los dos primeros de cada grupo son los que mejor abren, porque plantean el
problema antes que la solución:

- Artistas: `01-un-solo-link`, después `02-tu-publico-te-sigue`.
- Público: `01-no-te-enteres-tarde`, después `02-te-avisamos`.

## Cambiar algo

El texto y el diseño de cada posteo están en el mismo lugar, a propósito: si se
separan, terminan diciendo dos cosas distintas.

- Contenido y bajadas: `contenido_artistas.py` y `contenido_publico.py`.
- Lo que comparten todos —colores, tipografía, composición—: `estilo.py`.

Después:

```bash
cd marketing/instagram
python3 generar.py
```

Vuelve a escribir los HTML, saca las imágenes y regenera `textos.md`. Necesita
Google Chrome instalado, que es lo que saca la foto.

La tipografía es Poppins, la misma del sitio, incrustada dentro de cada HTML.
Sin eso Chrome dibuja con la del sistema y las imágenes salen con otra letra.
