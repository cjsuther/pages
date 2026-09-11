"""
Los posteos dirigidos al público del artista.

Le hablan a quien va a los shows, no a quien los produce, y por eso el fondo es
claro: es el mismo verde de la marca apoyado sobre el otro lado. Quien mira el
feed tiene que saber en medio segundo si ese posteo le habla a él.

Nada de lo que se promete acá cuesta plata ni pide instalar nada, y eso se dice
en casi todos: es la objeción que aparece primero.
"""

POSTEOS = [
    {
        'slug': 'no-te-enteres-tarde',
        'titulo': 'Te enteraste <em>al día siguiente.</em> Otra vez.',
        'bajada': 'La historia dura 24 horas y el flyer se pierde en el feed. Seguí a '
                  'tus artistas y que te avisen ellos.',
        'css': """
.perdidas { display: flex; flex-direction: column; gap: 20px; }
.perdida { display: flex; align-items: center; gap: 26px;
           background: var(--superficie); border: 2px solid var(--borde);
           border-radius: 26px; padding: 26px 32px; font-size: 30px; }
.perdida .marca { width: 54px; height: 54px; border-radius: 999px;
                  flex-shrink: 0; display: flex; align-items: center;
                  justify-content: center; font-size: 28px; }
.perdida.mal .marca { background: #F3D9D3; }
.perdida.mal { color: var(--apagado); }
.perdida.bien .marca { background: var(--acento); }
.perdida.bien { font-weight: 600; border-color: var(--acento); }
""",
        'pieza': """
<div class="perdidas">
  <div class="perdida mal"><span class="marca">✕</span>La historia duró 24 horas</div>
  <div class="perdida mal"><span class="marca">✕</span>El flyer bajó en el feed</div>
  <div class="perdida bien"><span class="marca">✓</span>Te llegó el aviso al teléfono</div>
</div>""",
        'texto': """¿Cuántas veces te enteraste de un show al día siguiente?

La historia dura 24 horas. El flyer baja en el feed a los tres posteos. Y el algoritmo decide si te muestra o no a la banda que te gusta.

En Rezonar seguís a tus artistas y te avisan ellos. Cada vez que publican una fecha, te llega una notificación al teléfono.

Gratis, sin instalar nada.

🔗 rezon.ar

#Recitales #StandUp #TeatroIndependiente #AgendaCultural #BuenosAires""",
    },
    {
        'slug': 'te-avisamos',
        'titulo': 'Tocás <em>Seguir</em> una vez. Te avisamos siempre.',
        'bajada': 'Cuando el artista publica una fecha, te llega la notificación. Sin '
                  'newsletter y sin dar tu mail.',
        'css': """
.pila { display: flex; flex-direction: column; gap: 22px; align-items: center; }
.boton { background: var(--acento); color: var(--sobre-acento);
         border-radius: 999px; padding: 26px 66px; font-size: 38px;
         font-weight: 900; display: inline-flex; align-items: center; gap: 16px; }
.hacia { font-size: 42px; color: var(--acento-suave); }
.notif { width: 100%; background: var(--superficie);
         border: 2px solid var(--borde); border-radius: 32px;
         padding: 32px 36px; display: flex; gap: 26px; align-items: flex-start; }
.notif .icono { width: 68px; height: 68px; border-radius: 20px;
                background: var(--acento); flex-shrink: 0; display: flex;
                align-items: center; justify-content: center; font-size: 34px; }
.notif .titulo { font-size: 31px; font-weight: 900; }
.notif .detalle { font-size: 28px; color: var(--apagado); margin-top: 6px;
                  line-height: 1.35; }
""",
        'pieza': """
<div class="pila">
  <span class="boton">＋ Seguir</span>
  <span class="hacia">↓</span>
  <div class="notif">
    <span class="icono">🔔</span>
    <span>
      <span class="titulo">Nueva fecha</span>
      <span class="detalle">Viernes 20 a las 21, en Niceto. A 4&nbsp;km tuyo.</span>
    </span>
  </div>
</div>""",
        'texto': """Una sola vez. Después te enterás siempre.

Entrás a la página del artista, tocás Seguir y elegís: querés saber de todas sus fechas, o solo de las que caen cerca tuyo.

Listo. Cuando publica un show, te llega la notificación al teléfono.

No hay que dar el mail, no hay newsletter y no depende de que el algoritmo te lo muestre.

🔗 rezon.ar

#Recitales #AgendaCultural #StandUp #Musica""",
    },
    {
        'slug': 'cerca-tuyo',
        'titulo': 'Las fechas <em>que te quedan cerca.</em>',
        'bajada': 'Elegís a cuántos kilómetros te interesa enterarte. Lo que pasa lejos '
                  'no te llena el teléfono.',
        'css': """
.evento { background: var(--superficie); border: 2px solid var(--borde);
          border-radius: 32px; overflow: hidden; }
.evento .cabeza { background: var(--acento); height: 118px; position: relative; }
.evento .cabeza .km { position: absolute; right: 28px; top: 28px;
                      background: var(--fondo); color: var(--texto);
                      border-radius: 999px; padding: 12px 26px;
                      font-size: 26px; font-weight: 900; }
.evento .cuerpo { padding: 32px 36px; }
.evento .titulo { font-size: 38px; font-weight: 900; }
.evento .donde { font-size: 29px; color: var(--apagado); margin-top: 10px; }
.dial { margin-top: 26px; display: flex; align-items: center; gap: 20px; }
.dial .riel { flex: 1; height: 16px; border-radius: 999px;
              background: var(--borde); overflow: hidden; }
.dial .riel i { display: block; height: 100%; width: 34%;
                background: var(--acento); border-radius: 999px; }
.dial .valor { font-size: 28px; font-weight: 600; color: var(--apagado); }
""",
        'pieza': """
<div class="evento">
  <span class="cabeza"><span class="km">a 4 km</span></span>
  <span class="cuerpo">
    <span class="titulo">Viernes 20, 21&nbsp;h</span>
    <span class="donde">Niceto Club · Palermo</span>
    <span class="dial">
      <span class="riel"><i></i></span>
      <span class="valor">30 km</span>
    </span>
  </span>
</div>""",
        'texto': """No todo lo que se publica te sirve. Lo que te queda cerca, sí.

En Rezonar decís a cuántos kilómetros te interesa enterarte. Si seguís a una banda que toca en todo el país, te avisamos solo cuando caen cerca tuyo.

Y en la home podés ver directamente qué se viene cerca, sin seguir a nadie.

🔗 rezon.ar

#AgendaCultural #BuenosAires #Recitales #QueHacerHoy""",
    },
    {
        'slug': 'entrada-ahi-mismo',
        'titulo': 'La entrada, <em>ahí mismo.</em>',
        'bajada': 'Sin saltar a otra ticketera ni averiguar dónde se compra para cada '
                  'fecha. Te llega con su QR.',
        'css': """
.pasos { display: flex; align-items: center; gap: 22px; }
.paso { flex: 1; background: var(--superficie); border: 2px solid var(--borde);
        border-radius: 28px; padding: 30px 24px; text-align: center; }
.paso .n { width: 58px; height: 58px; border-radius: 999px;
           background: var(--acento); color: var(--sobre-acento);
           font-size: 28px; font-weight: 900; margin: 0 auto 18px;
           display: flex; align-items: center; justify-content: center; }
.paso .que { font-size: 27px; font-weight: 600; line-height: 1.3; }
.pasos .flecha { font-size: 38px; color: var(--acento-suave); }
.cierre { margin-top: 26px; text-align: center; font-size: 29px;
          font-weight: 600; color: var(--apagado); }
""",
        'pieza': """
<div class="pasos">
  <div class="paso"><span class="n">1</span><span class="que">Ves la fecha</span></div>
  <span class="flecha">→</span>
  <div class="paso"><span class="n">2</span><span class="que">Comprás</span></div>
  <span class="flecha">→</span>
  <div class="paso"><span class="n">3</span><span class="que">Te llega el QR</span></div>
</div>
<div class="cierre">Todo sin salir de la página del artista.</div>""",
        'texto': """Ves la fecha, comprás la entrada y te llega el QR. Ahí mismo.

Cuando el artista vende por Rezonar, la entrada se compra en su propia página. No hay que saltar a otra ticketera ni averiguar dónde se vende para cada show.

Te llega por mail con su código QR y en la puerta lo escanean del teléfono.

🔗 rezon.ar

#Entradas #Recitales #StandUp #TeatroIndependiente""",
    },
    {
        'slug': 'todo-en-un-mapa',
        'titulo': 'Todo lo que se viene, <em>en un mapa.</em>',
        'bajada': 'Mirás qué hay cerca esta semana y decidís por dónde te queda, no por '
                  'quién te apareció en el feed.',
        'css': """
.mapa { position: relative; height: 430px; border-radius: 34px;
        background: var(--superficie); border: 2px solid var(--borde);
        overflow: hidden; }
/* Una trama de calles, sin mapa de verdad: lo que se reconoce es la forma.
   Las dos avenidas más gruesas son lo que la diferencia de una tabla. */
.mapa .calles { position: absolute; inset: 0;
  background-image:
    linear-gradient(var(--borde) 2px, transparent 2px),
    linear-gradient(90deg, var(--borde) 2px, transparent 2px);
  background-size: 96px 96px; opacity: 0.85; }
.mapa .avenida { position: absolute; background: var(--borde); }
.mapa .avenida.h { left: 0; right: 0; height: 12px; }
.mapa .avenida.v { top: 0; bottom: 0; width: 12px; }
.mapa .rio { position: absolute; right: -60px; top: -40px; width: 300px;
             height: 620px; background: var(--acento); opacity: 0.10;
             transform: rotate(24deg); border-radius: 999px; }
.pin { position: absolute; width: 30px; height: 30px; border-radius: 999px;
       background: var(--acento); border: 5px solid var(--fondo); }
.pin.grande { width: 86px; height: 86px; display: flex; align-items: center;
              justify-content: center; font-size: 27px; font-weight: 900;
              color: var(--sobre-acento); }
.vos { position: absolute; width: 150px; height: 150px; border-radius: 999px;
       border: 3px dashed var(--acento); }
""",
        'pieza': """
<div class="mapa">
  <span class="calles"></span>
  <span class="avenida h" style="top:47%"></span>
  <span class="avenida v" style="left:40%"></span>
  <span class="rio"></span>
  <span class="vos" style="left:34%;top:26%"></span>
  <span class="pin grande" style="left:57%;top:52%">3</span>
  <span class="pin" style="left:16%;top:20%"></span>
  <span class="pin" style="left:78%;top:24%"></span>
  <span class="pin" style="left:24%;top:74%"></span>
  <span class="pin" style="left:68%;top:80%"></span>
  <span class="pin" style="left:88%;top:62%"></span>
</div>""",
        'texto': """¿Qué hay cerca esta semana?

En la home de Rezonar podés ver las próximas fechas en un mapa. Mirás qué se viene alrededor tuyo y decidís por dónde te queda, no por quién te apareció en el feed.

Bandas, stand up, teatro, ciclos. Todo junto y en un solo lugar.

🔗 rezon.ar

#AgendaCultural #BuenosAires #QueHacerHoy #Recitales #StandUp""",
    },
    {
        'slug': 'estan-todos',
        'titulo': 'Tus artistas, <em>todos en el mismo lugar.</em>',
        'bajada': 'Buscás por nombre, seguís a los que te interesan y armás tu propia '
                  'agenda. Gratis.',
        'css': """
.grilla { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
.tarjeta { background: var(--superficie); border: 2px solid var(--borde);
           border-radius: 28px; padding: 28px 22px; text-align: center; }
.tarjeta .foto { width: 92px; height: 92px; border-radius: 999px;
                 margin: 0 auto 18px; display: flex; align-items: center;
                 justify-content: center; font-size: 34px; font-weight: 900; }
.tarjeta .nombre { font-size: 25px; font-weight: 600; }
.tarjeta .seguir { margin-top: 16px; font-size: 23px; font-weight: 600;
                   border-radius: 999px; padding: 10px 0;
                   background: var(--acento); color: var(--sobre-acento); }
.tarjeta.siguiendo .seguir { background: transparent; color: var(--apagado);
                             border: 2px solid var(--borde); }
""",
        'pieza': """
<div class="grilla">
  <div class="tarjeta siguiendo">
    <span class="foto" style="background:#CDE6BB;color:#14310A">LB</span>
    <span class="nombre">La Banda</span>
    <span class="seguir">Siguiendo</span>
  </div>
  <div class="tarjeta">
    <span class="foto" style="background:#F3D9D3;color:#8A3A22">RP</span>
    <span class="nombre">Rie Palermo</span>
    <span class="seguir">Seguir</span>
  </div>
  <div class="tarjeta">
    <span class="foto" style="background:#D8E2F5;color:#26406F">CX</span>
    <span class="nombre">Ciclo X</span>
    <span class="seguir">Seguir</span>
  </div>
</div>""",
        'texto': """Bandas, comediantes, ciclos y salas. Todos en el mismo lugar.

Buscás por nombre, tocás Seguir a los que te interesan y armás tu propia agenda. Cada vez que alguno publica una fecha, te enterás.

Es gratis y no hace falta instalar nada.

Y si el artista que buscás todavía no está, pedile que arme su página: es gratis para él también.

🔗 rezon.ar

#AgendaCultural #Recitales #StandUp #TeatroIndependiente #BuenosAires""",
    },
]
