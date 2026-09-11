#!/usr/bin/env python3
"""
Arma los posteos: escribe el HTML de cada uno y lo convierte en imagen.

Se corre así, desde esta carpeta:

    python3 generar.py

Necesita Google Chrome instalado; lo usa en modo headless para sacar la foto.
Los HTML quedan en html/ a propósito: son la fuente, y tocar ahí y volver a
correr esto es más rápido que retocar catorce imágenes a mano.
"""

import pathlib
import subprocess
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))

import estilo
import contenido_artistas
import contenido_publico

AQUI = pathlib.Path(__file__).parent
CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'

GRUPOS = [
    ('artistas', contenido_artistas.POSTEOS),
    ('publico', contenido_publico.POSTEOS),
]


def escribir_html():
    """Un archivo por posteo, numerado en el orden en que conviene publicarlos."""
    destino = AQUI / 'html'
    destino.mkdir(exist_ok=True)

    hechos = []

    for tema, posteos in GRUPOS:
        for i, posteo in enumerate(posteos, start=1):
            nombre = f'{tema}-{i:02d}-{posteo["slug"]}'
            ruta = destino / f'{nombre}.html'
            ruta.write_text(estilo.pagina(tema, posteo['slug'], posteo), encoding='utf-8')
            hechos.append((tema, nombre, ruta))

    return hechos


def fotografiar(hechos):
    for tema, nombre, ruta in hechos:
        carpeta = AQUI / tema
        carpeta.mkdir(exist_ok=True)
        salida = carpeta / f'{nombre}.png'

        subprocess.run([
            CHROME, '--headless', '--disable-gpu', '--hide-scrollbars',
            f'--screenshot={salida}',
            f'--window-size={estilo.ANCHO},{estilo.ALTO}',
            '--default-background-color=00000000',
            f'file://{ruta}',
        ], check=True, capture_output=True)

        print(f'  {salida.relative_to(AQUI)}')


def escribir_textos():
    """
    Todas las bajadas en un archivo, para copiar y pegar.

    Van también acá y no sólo en el código porque quien publica no abre un .py.
    """
    partes = ['# Bajadas de los posteos\n',
              'Se generan desde `contenido_artistas.py` y `contenido_publico.py`. '
              'Si cambiás algo, cambialo ahí y volvé a correr `generar.py`, '
              'así el texto y la imagen no se separan.\n']

    titulos = {'artistas': 'Para artistas', 'publico': 'Para el público'}

    for tema, posteos in GRUPOS:
        partes.append(f'\n---\n\n## {titulos[tema]}\n')

        for i, posteo in enumerate(posteos, start=1):
            partes.append(f'\n### {i:02d} · {posteo["slug"]}\n')
            partes.append(f'`{tema}/{tema}-{i:02d}-{posteo["slug"]}.png`\n')
            partes.append(f'\n```\n{posteo["texto"]}\n```\n')

    (AQUI / 'textos.md').write_text('\n'.join(partes), encoding='utf-8')


if __name__ == '__main__':
    hechos = escribir_html()
    print(f'{len(hechos)} posteos:')
    fotografiar(hechos)
    escribir_textos()
    print('textos.md')
