"""
Los posteos dirigidos al artista.

Cada uno muestra una sola cosa que Rezonar hace por él, con una pieza visual
distinta. La pieza cambia en todos a propósito: catorce tarjetas con la misma
composición se leen como relleno, y en un feed eso se nota más que en cualquier
otro lado.

La bajada de Instagram va acá al lado del diseño y no en un archivo aparte,
porque el titular y el texto son la misma decisión: si se separan, terminan
diciendo dos cosas distintas.
"""

# Un cuadrado tipo QR, dibujado con CSS. No es un QR de verdad —no lleva a
# ningún lado— y no tiene por qué serlo: es la forma lo que se reconoce.
QR = ''.join(
    f'<i class="{"on" if (x * 7 + y * 3) % 5 < 2 or (x in (0, 6) and y in (0, 6)) else ""}"></i>'
    for y in range(7) for x in range(7)
)

CSS_QR = """
.qr { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;
      width: 152px; height: 152px; }
.qr i { border-radius: 3px; background: transparent; }
.qr i.on { background: var(--texto); }
"""

POSTEOS = [
    {
        'slug': 'un-solo-link',
        'titulo': 'Instagram te da un link.<br><em>Que ese link sea todo.</em>',
        'bajada': 'Todas tus fechas, tus redes y tus entradas en una sola dirección '
                  'que no cambia nunca.',
        'css': """
.bio { background: var(--superficie); border: 2px solid var(--borde);
       border-radius: 40px; padding: 44px; }
.bio .cabeza { display: flex; align-items: center; gap: 28px; }
.bio .foto { width: 104px; height: 104px; border-radius: 999px;
             background: var(--acento); flex-shrink: 0; }
.bio .lineas { display: flex; flex-direction: column; gap: 14px; flex: 1; }
.bio .lineas i { display: block; height: 16px; border-radius: 999px;
                 background: var(--borde); }
.bio .enlace { margin-top: 36px; display: flex; align-items: center; gap: 18px;
               background: var(--acento); color: var(--sobre-acento);
               border-radius: 22px; padding: 26px 32px;
               font-size: 34px; font-weight: 600; }
.bio .enlace b { font-weight: 900; }
""",
        'pieza': """
<div class="bio">
  <div class="cabeza">
    <span class="foto"></span>
    <span class="lineas"><i style="width:46%"></i><i style="width:70%"></i></span>
  </div>
  <div class="enlace">rezon.ar/<b>tunombre</b></div>
</div>""",
        'texto': """Tenés un solo link en la bio. Que valga por todo.

Tu página de Rezonar junta tus fechas, tus redes y tus entradas en una sola dirección: rezon.ar/tunombre.

Cargás un show nuevo y ya está adentro. No hay que actualizar el link, ni cambiar el link, ni pedirle a nadie que se guarde otro link. Es siempre el mismo.

Se arma gratis y en diez minutos.

🔗 rezon.ar/artistas

#MusicaIndependiente #StandUpArgentina #TeatroIndependiente #Fechas #Recitales""",
    },
    {
        'slug': 'tu-publico-te-sigue',
        'titulo': 'Tu público no tiene que <em>acordarse de vos.</em>',
        'bajada': 'Te siguen desde tu página y les llega un aviso al teléfono cada vez '
                  'que publicás una fecha.',
        'css': """
.aviso { background: var(--superficie); border: 2px solid var(--borde);
         border-radius: 34px; padding: 38px 42px; display: flex; gap: 28px;
         align-items: flex-start; }
.aviso .icono { width: 74px; height: 74px; border-radius: 22px;
                background: var(--acento); flex-shrink: 0;
                display: flex; align-items: center; justify-content: center;
                font-size: 38px; }
.aviso .cuerpo { flex: 1; }
.aviso .titulo { font-size: 32px; font-weight: 900; }
.aviso .detalle { font-size: 29px; color: var(--apagado); margin-top: 8px;
                  line-height: 1.35; }
.aviso .hora { font-size: 24px; color: var(--apagado); }
.contador { margin-top: 30px; display: flex; align-items: baseline; gap: 18px; }
.contador b { font-size: 76px; font-weight: 900; color: var(--acento);
              line-height: 1; letter-spacing: -0.03em; }
.contador span { font-size: 30px; color: var(--apagado); }
""",
        'pieza': """
<div class="aviso">
  <span class="icono">🔔</span>
  <span class="cuerpo">
    <span class="titulo">Nueva fecha</span>
    <span class="detalle">Tu artista tocó publicar: viernes 20, a 4&nbsp;km tuyo.</span>
  </span>
  <span class="hora">ahora</span>
</div>
<div class="contador"><b>1</b><span>posteo → todos enterados</span></div>""",
        'texto': """Un árbol de links hay que ir a mirarlo. Tu página de Rezonar avisa sola.

Quien entra a tu página toca Seguir y elige si quiere enterarse de todas tus fechas o solo de las que caen cerca suyo.

Cuando publicás un show, le llega la notificación al teléfono. Sin newsletter, sin pedirle el mail, sin depender de que el algoritmo le muestre tu posteo.

Esto es lo que un Linktree no hace.

🔗 rezon.ar/artistas

#MusicaIndependiente #Ciclos #StandUp #GestionCultural""",
    },
    {
        'slug': 'vende-tus-entradas',
        'titulo': 'Vendé tus entradas <em>desde tu propia página.</em>',
        'bajada': 'Precio, cupo y control de acceso con QR. Tu público compra sin salir '
                  'de donde estaba.',
        'css': CSS_QR + """
.ticket { background: var(--superficie); border: 2px solid var(--borde);
          border-radius: 34px; padding: 40px; display: flex; gap: 40px;
          align-items: center; }
.ticket .datos { flex: 1; }
.ticket .evento { font-size: 36px; font-weight: 900; }
.ticket .donde { font-size: 28px; color: var(--apagado); margin-top: 10px; }
.ticket .estado { display: inline-block; margin-top: 22px; font-size: 25px;
                  font-weight: 600; padding: 10px 22px; border-radius: 999px;
                  background: var(--acento); color: var(--sobre-acento); }
.ticket .corte { width: 2px; align-self: stretch; background: var(--borde); }
""",
        'pieza': """
<div class="ticket">
  <span class="datos">
    <span class="evento">Viernes 20, 21&nbsp;h</span>
    <span class="donde">Niceto Club · Humboldt 1574</span>
    <span class="estado">Entrada válida</span>
  </span>
  <span class="corte"></span>
  <span class="qr">""" + QR + """</span>
</div>""",
        'texto': """Que la gente compre la entrada donde ya está mirando la fecha.

Activás la venta desde tu página: ponés el precio, el cupo y cuántas entradas por compra. El que entra compra ahí mismo, sin que lo mandes a otro sitio a averiguar.

Cada entrada sale con su QR y en la puerta las validás desde el teléfono. Sabés en todo momento cuántas vendiste y cuánto llevás recaudado.

Armar la página es gratis. Solo hay comisión si vendés, y la ves antes de activar nada.

🔗 rezon.ar/artistas

#Entradas #Recitales #StandUp #TeatroIndependiente""",
    },
    {
        'slug': 'cargala-hablando',
        'titulo': 'Dictale la fecha.<br><em>Que la cargue él.</em>',
        'bajada': 'Conectás ChatGPT o Claude a tu página y le decís el show. Lo deja '
                  'publicado, con afiche y entradas.',
        'css': """
.chat { display: flex; flex-direction: column; gap: 22px; }
.burbuja { border-radius: 30px; padding: 30px 34px; font-size: 31px;
           line-height: 1.38; max-width: 86%; }
.burbuja.vos { align-self: flex-end; background: var(--acento);
               color: var(--sobre-acento); border-bottom-right-radius: 10px;
               font-weight: 600; }
.burbuja.el { align-self: flex-start; background: var(--superficie);
              border: 2px solid var(--borde); color: var(--texto);
              border-bottom-left-radius: 10px; }
.burbuja.el b { color: var(--acento); font-weight: 600; }
""",
        'pieza': """
<div class="chat">
  <span class="burbuja vos">«Cargá un show el viernes 20 a las 21 en Niceto,
    entradas a 12.000 con cupo de 200.»</span>
  <span class="burbuja el">Listo. La fecha ya está en tu página, con el mapa
    puesto y <b>la venta abierta</b>.</span>
</div>""",
        'texto': """¿Y si cargar una fecha fuera decirla en voz alta?

Se puede conectar tu asistente —ChatGPT, Claude, Le Chat— a tus páginas de Rezonar. Le decís día, hora y dirección, y te deja el show publicado. La dirección se convierte en mapa sola.

También puede subir el afiche, abrir la venta de entradas y contarte cómo vienen las ventas.

Se conecta una vez, no se instala nada y no hay comandos que aprender. Está explicado paso por paso en rezon.ar/asistentes.

🔗 rezon.ar/asistentes

#IA #ChatGPT #Claude #GestionCultural #MusicaIndependiente""",
    },
    {
        'slug': 'con-tu-identidad',
        'titulo': 'La página es tuya. <em>Que se note.</em>',
        'bajada': 'Elegís plantilla, colores, foto y fondo. Ninguna plantilla con '
                  'nuestro logo arriba.',
        'css': """
.muestras { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; }
.muestra { border-radius: 30px; overflow: hidden; border: 2px solid var(--borde);
           height: 300px; display: flex; flex-direction: column; }
.muestra .arriba { height: 44%; }
.muestra .abajo { flex: 1; padding: 22px; display: flex; flex-direction: column;
                  gap: 12px; justify-content: center; }
.muestra .abajo i { display: block; height: 13px; border-radius: 999px; }
.muestra .nombre { margin-top: 18px; text-align: center; font-size: 25px;
                   font-weight: 600; color: var(--apagado); }
""",
        'pieza': """
<div class="muestras">
  <div>
    <div class="muestra" style="background:#111311">
      <span class="arriba" style="background:#6FBE44"></span>
      <span class="abajo">
        <i style="background:#6FBE44;width:70%"></i>
        <i style="background:#3A403700;border:2px solid #2E342C;width:100%"></i>
        <i style="background:#2E342C;width:88%"></i>
      </span>
    </div>
    <div class="nombre">Mínima</div>
  </div>
  <div>
    <div class="muestra" style="background:#FFF6E8">
      <span class="arriba" style="background:#E8643C"></span>
      <span class="abajo">
        <i style="background:#E8643C;width:62%"></i>
        <i style="background:#F0D3BE;width:100%"></i>
        <i style="background:#F0D3BE;width:80%"></i>
      </span>
    </div>
    <div class="nombre">Afiches</div>
  </div>
  <div>
    <div class="muestra" style="background:#101A2E">
      <span class="arriba" style="background:#5B8DEF"></span>
      <span class="abajo">
        <i style="background:#5B8DEF;width:75%"></i>
        <i style="background:#1E2B45;width:100%"></i>
        <i style="background:#1E2B45;width:66%"></i>
      </span>
    </div>
    <div class="nombre">Tarjetas</div>
  </div>
</div>""",
        'texto': """Tu página no tiene por qué parecerse a la de todos.

Elegís entre cinco plantillas y ponés tus colores, tu foto y tu fondo. Podés tener una para vos, otra para tu banda y otra para el ciclo que organizás, cada una con su identidad.

Y si tenés dominio propio, se puede apuntar a tu página: queda con tu nombre y sin el nuestro.

🔗 rezon.ar/artistas

#Diseño #MusicaIndependiente #Bandas #Ciclos""",
    },
    {
        'slug': 'qr-para-el-afiche',
        'titulo': 'Un QR que <em>nunca queda viejo.</em>',
        'bajada': 'Lo bajás listo para imprimir. Siempre lleva a tus fechas de ahora, '
                  'no a las del mes pasado.',
        'css': CSS_QR + """
.afiche { display: flex; gap: 40px; align-items: center; }
.afiche .papel { background: var(--superficie); border: 2px solid var(--borde);
                 border-radius: 30px; padding: 38px; display: flex;
                 flex-direction: column; align-items: center; gap: 20px; }
.afiche .papel .leyenda { font-size: 24px; font-weight: 600;
                          color: var(--apagado); letter-spacing: 0.06em; }
.afiche .flecha { font-size: 52px; color: var(--acento); }
.afiche .lista { flex: 1; display: flex; flex-direction: column; gap: 18px; }
.afiche .fecha { background: var(--superficie); border: 2px solid var(--borde);
                 border-radius: 22px; padding: 22px 28px; font-size: 29px;
                 display: flex; justify-content: space-between; gap: 18px; }
.afiche .fecha b { font-weight: 900; }
.afiche .fecha span { color: var(--apagado); }
""",
        'pieza': """
<div class="afiche">
  <div class="papel">
    <span class="qr">""" + QR + """</span>
    <span class="leyenda">EN EL AFICHE</span>
  </div>
  <span class="flecha">→</span>
  <div class="lista">
    <div class="fecha"><b>VIE 20</b><span>Niceto</span></div>
    <div class="fecha"><b>SÁB 28</b><span>La Tangente</span></div>
    <div class="fecha"><b>VIE 11</b><span>Xirgu</span></div>
  </div>
</div>""",
        'texto': """El afiche se imprime una vez. Tus fechas cambian todo el tiempo.

Rezonar te da un QR listo para poner en el flyer, en el póster de la sala o en la remera. Ese código lleva siempre a tus fechas de ahora.

El que lo escanea en marzo ve lo de marzo, aunque el papel sea de enero.

🔗 rezon.ar/artistas

#Afiches #Flyers #Recitales #Ciclos #GestionCultural""",
    },
    {
        'slug': 'quien-te-mira',
        'titulo': 'Enterate <em>quién te está mirando.</em>',
        'bajada': 'Cuánta gente entra, de dónde viene, desde qué ciudad y qué edad '
                  'tiene. Por página.',
        'css': """
.datos { display: flex; flex-direction: column; gap: 26px; }
.barras { background: var(--superficie); border: 2px solid var(--borde);
          border-radius: 30px; padding: 34px 38px;
          display: flex; flex-direction: column; gap: 22px; }
.barras .fila { display: flex; align-items: center; gap: 22px; font-size: 27px; }
.barras .fila .quien { width: 3.6em; font-weight: 600; }
.barras .fila .riel { flex: 1; height: 20px; border-radius: 999px;
                      background: var(--borde); overflow: hidden; }
.barras .fila .riel i { display: block; height: 100%; background: var(--acento);
                        border-radius: 999px; }
.barras .fila .valor { width: 2.6em; text-align: right; color: var(--apagado); }
.chips { display: flex; gap: 18px; flex-wrap: wrap; }
.chip { border: 2px solid var(--borde); border-radius: 999px;
        padding: 16px 28px; font-size: 26px; font-weight: 600; }
.chip b { color: var(--acento); font-weight: 900; }
""",
        'pieza': """
<div class="datos">
  <div class="barras">
    <span class="fila"><span class="quien">18-24</span>
      <span class="riel"><i style="width:46%"></i></span><span class="valor">46%</span></span>
    <span class="fila"><span class="quien">25-34</span>
      <span class="riel"><i style="width:78%"></i></span><span class="valor">78%</span></span>
    <span class="fila"><span class="quien">35-44</span>
      <span class="riel"><i style="width:31%"></i></span><span class="valor">31%</span></span>
  </div>
  <div class="chips">
    <span class="chip">Instagram <b>62%</b></span>
    <span class="chip">Buenos Aires <b>48%</b></span>
    <span class="chip">Teléfono <b>96%</b></span>
  </div>
</div>""",
        'texto': """¿A quién le estás hablando de verdad?

Cada página tiene su sección de métricas: cuánta gente entró, de dónde llegó, desde qué ciudad te miran y con qué dispositivo.

También la edad y el género de tu público, y las dos cosas cruzadas. Eso es lo que sirve para decidir dónde tocar, a qué hora publicar y a quién apuntarle cuando pagás una pauta.

Son los datos de tu página, no del sitio: los ves solo vos.

🔗 rezon.ar/artistas

#Metricas #PublicoObjetivo #GestionCultural #MusicaIndependiente""",
    },
    {
        'slug': 'entre-varios',
        'titulo': 'Una fecha, <em>dos páginas.</em>',
        'bajada': 'Armás un show en conjunto y aparece en las dos. O sumás a quien te '
                  'maneja las redes.',
        'css': """
.junta { display: flex; flex-direction: column; gap: 28px; }
.caras { display: flex; align-items: center; justify-content: center; gap: 0; }
.caras span { width: 128px; height: 128px; border-radius: 999px;
              border: 6px solid var(--fondo); display: flex; align-items: center;
              justify-content: center; font-size: 46px; font-weight: 900; }
.caras span:nth-child(2) { margin-left: -34px; }
.compartida { background: var(--superficie); border: 2px solid var(--borde);
              border-radius: 30px; padding: 32px 36px; text-align: center; }
.compartida .titulo { font-size: 34px; font-weight: 900; }
.compartida .donde { font-size: 28px; color: var(--apagado); margin-top: 10px; }
.compartida .marca { display: inline-block; margin-top: 20px; font-size: 24px;
                     font-weight: 600; letter-spacing: 0.08em;
                     text-transform: uppercase; padding: 10px 22px;
                     border-radius: 999px; border: 2px solid var(--acento);
                     color: var(--acento); }
""",
        'pieza': """
<div class="junta">
  <div class="caras">
    <span style="background:#6FBE44;color:#14310A">LB</span>
    <span style="background:#E8643C;color:#FFF6E8">RP</span>
  </div>
  <div class="compartida">
    <span class="titulo">Viernes 20 · Niceto</span>
    <span class="donde">La Banda + Rie Palermo</span>
    <span class="marca">En las dos páginas</span>
  </div>
</div>""",
        'texto': """Cuando la fecha es de dos, que esté en las dos páginas.

Invitás a otra página a colaborar en un show y el evento aparece en las dos, con los dos nombres. Cada uno lo comparte con su gente y las notificaciones salen para los dos públicos.

También podés sumar administradores a tu página: quien te maneja las redes carga las fechas sin que le pases tu contraseña.

🔗 rezon.ar/artistas

#Ciclos #Fechas #Colaboracion #MusicaIndependiente #StandUp""",
    },
]
