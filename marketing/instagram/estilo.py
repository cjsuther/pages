"""
El diseño de los posteos: dos mundos de color y las piezas que se repiten.

La diferencia entre los dos no es decorativa. Quien ve el feed tiene que saber
en medio segundo si ese posteo le habla a él o no, y por eso el fondo se da
vuelta entero: los de artistas son oscuros —el lado de atrás del escenario— y
los del público son claros. El verde de la marca es el mismo en los dos, que es
lo que los mantiene de la misma casa.
"""

import base64
import pathlib

RAIZ = pathlib.Path(__file__).resolve().parents[2]
PUBLICO = RAIZ / 'frontend' / 'public'

# Instagram muestra el feed en 4:5. Es el formato que más alto ocupa en
# pantalla; en cuadrado se desperdicia un tercio del espacio.
ANCHO, ALTO = 1080, 1350


def _incrustar(ruta, mime):
    datos = base64.b64encode(pathlib.Path(ruta).read_bytes()).decode()
    return f'data:{mime};base64,{datos}'


def _fuentes():
    """Poppins incrustada. Sin esto, Chrome renderiza con la del sistema."""
    caras = []
    for peso in (400, 600, 900):
        for conjunto in ('latin', 'latin-ext'):
            archivo = PUBLICO / 'fonts' / f'poppins-{conjunto}-{peso}.woff2'
            caras.append(f"""
@font-face {{
  font-family: 'Poppins';
  font-style: normal;
  font-weight: {peso};
  src: url('{_incrustar(archivo, 'font/woff2')}') format('woff2');
}}""")
    return '\n'.join(caras)


LOGO_CLARO = _incrustar(PUBLICO / 'logo.png', 'image/png')
LOGO_OSCURO = _incrustar(PUBLICO / 'logo-negro.png', 'image/png')

# Los dos mundos. El verde es el mismo; lo que cambia es sobre qué se apoya.
TEMAS = {
    'artistas': {
        'fondo': '#111311',
        'superficie': '#1B1F1A',
        'borde': '#2E342C',
        'texto': '#FFFFFF',
        'apagado': '#9AA394',
        'acento': '#6FBE44',
        'acento_suave': '#CDE6BB',
        'sobre_acento': '#14310A',
        'logo': LOGO_CLARO,
        'etiqueta': 'Para artistas',
    },
    'publico': {
        'fondo': '#FFFFFF',
        'superficie': '#F7F8F5',
        'borde': '#E2E6DC',
        'texto': '#111311',
        'apagado': '#4A4F45',
        'acento': '#6FBE44',
        'acento_suave': '#3E7C1E',
        'sobre_acento': '#14310A',
        'logo': LOGO_OSCURO,
        'etiqueta': 'Para vos que vas',
    },
}

CSS = """
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  width: %(ancho)dpx;
  height: %(alto)dpx;
  overflow: hidden;
}

body {
  font-family: 'Poppins', sans-serif;
  background: var(--fondo);
  color: var(--texto);
  display: flex;
  flex-direction: column;
  padding: 84px 76px 68px;
  -webkit-font-smoothing: antialiased;
}

/* El acento entra por el borde de arriba: marca la casa antes de que se lea
   una sola palabra, y no le come lugar al contenido. */
body::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 14px;
  background: var(--acento);
}

.etiqueta {
  align-self: flex-start;
  font-size: 24px;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  padding: 12px 24px;
  border-radius: 999px;
}

.etiqueta.artistas { background: var(--acento); color: var(--sobre-acento); }
.etiqueta.publico  { color: var(--acento-suave); border: 2px solid var(--acento); }

h1 {
  margin-top: 44px;
  font-size: 86px;
  font-weight: 900;
  line-height: 1.02;
  letter-spacing: -0.035em;
  text-wrap: balance;
}

h1 em { font-style: normal; color: var(--acento); }

/* En el mundo claro el verde de marca no contrasta bastante para texto
   grande sobre blanco, así que ahí se usa el verde oscuro. */
.publico h1 em { color: var(--acento-suave); }

.bajada {
  margin-top: 30px;
  font-size: 33px;
  font-weight: 400;
  line-height: 1.42;
  color: var(--apagado);
  /* Tres renglones largos y no cinco cortos: con la medida angosta quedaba
     media imagen vacía a la derecha mientras la pieza de abajo ocupaba todo
     el ancho, y eso se lee como un error de armado. */
  max-width: 19.5em;
}

/* El aire sobrante se reparte arriba y abajo de la pieza, en vez de juntarse
   todo en un solo hueco entre el texto y ella. Con `auto` de los dos lados,
   flexbox parte la diferencia. */
.pieza {
  margin-top: auto;
  margin-bottom: auto;
  padding-bottom: 46px;
}

.pie {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  border-top: 2px solid var(--borde);
  padding-top: 32px;
}

.pie img { height: 46px; }

.pie span {
  font-size: 27px;
  font-weight: 600;
  color: var(--apagado);
}
"""


def pagina(tema, clases, cuerpo):
    t = TEMAS[tema]
    variables = '\n'.join(f'  --{k.replace("_", "-")}: {v};'
                          for k, v in t.items() if k not in ('logo', 'etiqueta'))

    return f"""<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<style>
{_fuentes()}
:root {{
{variables}
}}
{CSS % {'ancho': ANCHO, 'alto': ALTO}}
{cuerpo['css']}
</style></head>
<body class="{tema} {clases}">
  <span class="etiqueta {tema}">{t['etiqueta']}</span>
  <h1>{cuerpo['titulo']}</h1>
  <p class="bajada">{cuerpo['bajada']}</p>
  <div class="pieza">{cuerpo['pieza']}</div>
  <div class="pie">
    <img src="{t['logo']}" alt="Rezonar">
    <span>rezon.ar</span>
  </div>
</body></html>"""
