import React, { useContext } from 'react';
import { Helmet } from 'react-helmet-async';
import {
  CalendarDays, Bell, Ticket, Palette, QrCode, Users, Link2, MapPin,
  ArrowRight, Check, Instagram, Globe, Sparkles,
} from 'lucide-react';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { PieDePagina } from '../components/Marco';
import { Boton, Rotulo, Tarjeta, TituloSeccion } from '../components/ui';

/**
 * La página que le explica a un artista para qué le sirve Rezonar.
 *
 * Está escrita para alguien que ya tiene un Linktree o nada: el argumento no
 * es "juntá tus links" —eso lo hace cualquiera— sino que acá su público lo
 * puede seguir y se entera de cada fecha sin tener que entrar a mirar.
 */

const LO_QUE_TIENE = [
  {
    icono: CalendarDays,
    titulo: 'Todas tus fechas, ordenadas',
    detalle: 'Cargás un show con día, hora y dirección. Cuando pasa, se va solo de la página: nunca queda una fecha vieja arriba.',
  },
  {
    icono: MapPin,
    titulo: 'Dónde es, con mapa',
    detalle: 'Cada fecha lleva su ubicación y el link para llegar. Tu público no tiene que preguntarte la dirección por privado.',
  },
  {
    icono: Bell,
    titulo: 'Avisos a quien te sigue',
    detalle: 'Publicás una fecha y le llega una notificación al teléfono a cada persona que te sigue. Esto es lo que un Linktree no hace.',
  },
  {
    icono: Ticket,
    titulo: 'Vendé tus entradas',
    detalle: 'Podés cobrar desde tu propia página, con QR y control de acceso, sin mandar a la gente a otro sitio.',
  },
  {
    icono: Link2,
    titulo: 'Tus links y tus redes',
    detalle: 'Spotify, YouTube, Instagram, prensa, lo que sea. Agrupados como vos quieras y en el orden que quieras.',
  },
  {
    icono: Palette,
    titulo: 'Con tu identidad',
    detalle: 'Elegís plantilla, colores, foto y fondo. La página es tuya, no una plantilla con nuestro logo arriba.',
  },
  {
    icono: QrCode,
    titulo: 'Un QR para el afiche',
    detalle: 'Te lo bajás listo para imprimir. El mismo código lleva siempre a tus fechas de ahora, no a las del mes pasado.',
  },
  {
    icono: Sparkles,
    titulo: 'Cargala hablando',
    detalle: 'Podés conectar ChatGPT o Claude a tu página y cargar una fecha dictándola, con afiche y entradas incluidas.',
  },
  {
    icono: Users,
    titulo: 'Entre varios',
    detalle: 'Sumás a quien maneja tus redes, o armás una fecha en conjunto con otra página y aparece en las dos.',
  },
];

const PASOS_INSTAGRAM = [
  {
    titulo: 'Copiá tu link',
    detalle: 'Cuando armás la página elegís tu dirección: rezon.ar/tunombre. Esa es la que va.',
  },
  {
    titulo: 'Editá tu perfil',
    detalle: 'En Instagram: Editar perfil → Enlaces → Agregar enlace externo. Pegá el link ahí.',
  },
  {
    titulo: 'Listo, no lo tocás más',
    detalle: 'El link no cambia nunca. Cargás fechas nuevas y el mismo link ya las muestra.',
  },
];

const PREGUNTAS = [
  {
    q: '¿Cuánto sale?',
    a: 'Armar tu página, cargar fechas y que te sigan es gratis. Si vendés entradas por Rezonar se aplica una comisión, que ves antes de activar la venta.',
  },
  {
    q: '¿Sirve si ya tengo Linktree?',
    a: 'Sí, y podés tener los dos. La diferencia es que acá tu público te sigue: un Linktree hay que ir a mirarlo, tu página de Rezonar avisa sola.',
  },
  {
    q: '¿Puedo tener más de una página?',
    a: 'Sí. Una para vos, una para tu banda, una para el ciclo que organizás. Se manejan todas desde la misma cuenta.',
  },
  {
    q: '¿Puedo cargar las fechas con ChatGPT o Claude?',
    a: 'Sí. Se conecta tu asistente a tus páginas una vez y después le dictás el show: día, hora, dirección, afiche y entradas. Está explicado paso por paso en la página de asistentes.',
  },
  {
    q: '¿Y si tengo dominio propio?',
    a: 'Se puede apuntar tu dominio a la página, así queda con tu nombre y sin el nuestro.',
  },
];

function ParaArtistas() {
  const { token } = useContext(AuthContext);
  const destinoCrear = token ? '/my-pages' : '/register';

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet>
        <title>Rezonar para artistas — Un solo link con todas tus fechas</title>
        <meta name="description" content="Armá tu página gratis, cargá tus fechas y ponela en tu Instagram. Tu público te sigue y recibe un aviso cada vez que publicás un show nuevo." />
        <meta property="og:title" content="Rezonar para artistas — Un solo link con todas tus fechas" />
        <meta property="og:description" content="Armá tu página gratis, cargá tus fechas y ponela en tu Instagram. Tu público te sigue y recibe un aviso cada vez que publicás un show nuevo." />
        <meta property="og:type" content="website" />
        <link rel="canonical" href={window.location.href} />
      </Helmet>

      <Navigation />

      <main className="flex-1">

        {/* ------------------------------------------------------------ hero */}

        <section className="border-b border-borde">
          <div className="max-w-7xl mx-auto px-5 sm:px-6 py-14 sm:py-20">
            <div className="grid lg:grid-cols-[1.15fr_1fr] gap-12 lg:gap-16 items-center">
              <div>
                <Rotulo>Para artistas, bandas, ciclos y salas</Rotulo>
                <h1 className="mt-4 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.05] text-balance">
                  Instagram te da un link.{' '}
                  <span className="text-verde-oscuro">Que ese link sea todo.</span>
                </h1>
                <p className="mt-5 text-lg text-tinta-media max-w-xl leading-relaxed">
                  Tu página de Rezonar tiene tus fechas, tus redes y tus entradas. Y a
                  diferencia de un árbol de links, tu público te puede seguir: cada vez que
                  publicás un show, le llega un aviso al teléfono.
                </p>

                <div className="mt-8 flex flex-wrap gap-3">
                  <Boton a={destinoCrear} tamano="lg">
                    Armar mi página gratis <ArrowRight className="w-4 h-4" />
                  </Boton>
                  <Boton a="/" variante="secundario" tamano="lg">
                    Ver páginas de otros
                  </Boton>
                </div>

                <ul className="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm text-tinta-media">
                  {['Gratis', 'Sin instalar nada', 'Lista en diez minutos'].map((t) => (
                    <li key={t} className="inline-flex items-center gap-1.5">
                      <Check className="w-4 h-4 text-verde-oscuro" /> {t}
                    </li>
                  ))}
                </ul>
              </div>

              {/* Cómo se ve el link donde de verdad importa: la bio. */}
              <div className="lg:justify-self-end w-full max-w-sm">
                <Tarjeta className="p-6">
                  <div className="flex items-center gap-2 text-tinta-suave text-xs font-semibold mb-5">
                    <Instagram className="w-4 h-4" />
                    TU PERFIL
                  </div>

                  <div className="flex items-center gap-4">
                    <span className="w-16 h-16 rounded-full bg-verde-claro border border-verde-medio flex-shrink-0" />
                    <div className="space-y-1.5 flex-1">
                      <span className="block h-2.5 w-24 rounded-full bg-borde-fuerte" />
                      <span className="block h-2 w-32 rounded-full bg-borde" />
                    </div>
                  </div>

                  <div className="mt-5 space-y-2">
                    <span className="block h-2 w-full rounded-full bg-borde" />
                    <span className="block h-2 w-3/4 rounded-full bg-borde" />
                  </div>

                  <div className="mt-5 flex items-center gap-2 px-3 py-2.5 rounded-xl bg-verde-claro border border-verde-medio">
                    <Globe className="w-4 h-4 text-verde-oscuro flex-shrink-0" />
                    <span className="font-semibold text-verde-oscuro text-sm truncate">
                      rezon.ar/tunombre
                    </span>
                  </div>

                  <p className="mt-4 text-xs text-tinta-suave leading-relaxed">
                    Un link que no vence. Cargás una fecha nueva y ya está adentro.
                  </p>
                </Tarjeta>
              </div>
            </div>
          </div>
        </section>

        {/* ------------------------------------------------------- el problema */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Hoy tus fechas viven en cuatro lados"
              bajada="Y ninguno de los cuatro le avisa a nadie."
            />

            <div className="grid sm:grid-cols-3 gap-4">
              {[
                ['La historia dura 24 horas', 'Quien no entró ese día no se enteró, y no hay forma de que lo encuentre después.'],
                ['El flyer se pierde en el feed', 'A los tres posteos ya bajó. El que te busca en marzo ve la fecha de enero.'],
                ['Cada show en otra ticketera', 'Tu público tiene que adivinar dónde se compra para cada fecha.'],
              ].map(([titulo, detalle]) => (
                <Tarjeta key={titulo} className="p-6">
                  <h3 className="font-bold text-tinta">{titulo}</h3>
                  <p className="text-sm text-tinta-media mt-2 leading-relaxed">{detalle}</p>
                </Tarjeta>
              ))}
            </div>
          </div>
        </section>

        {/* ------------------------------------------- la diferencia: seguir */}

        <section className="border-b border-borde bg-verde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-16">
            <div className="grid md:grid-cols-[1fr_auto] gap-10 items-center">
              <div>
                <Rotulo className="!text-verde-tinta/70">La diferencia</Rotulo>
                <h2 className="mt-4 text-3xl sm:text-4xl font-bold tracking-tight text-verde-tinta text-balance leading-tight">
                  Tu público no tiene que acordarse de vos.
                </h2>
                <p className="mt-4 text-verde-tinta/85 leading-relaxed max-w-xl">
                  El que entra a tu página toca <strong className="font-bold">Seguir</strong> y elige si
                  quiere enterarse de todas tus fechas o solo de las que caen cerca suyo. Cuando
                  cargás un show nuevo, le llega la notificación. Sin newsletter, sin pedirle el
                  mail, sin depender de que el algoritmo le muestre tu posteo.
                </p>
              </div>

              <div className="md:w-64">
                <div className="bg-white rounded-2xl p-5 border border-verde-tinta/10">
                  <div className="flex items-center gap-2 mb-3">
                    <Bell className="w-4 h-4 text-verde-oscuro" />
                    <Rotulo>Rezonar</Rotulo>
                  </div>
                  <p className="font-bold text-tinta leading-snug">Nueva fecha</p>
                  <p className="text-sm text-tinta-media mt-1 leading-snug">
                    Tu artista tocó publicar: viernes 20, a 4 km tuyo.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* --------------------------------------------------- lo que incluye */}

        <section className="border-b border-borde">
          <div className="max-w-7xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Lo que tiene tu página"
              bajada="Todo esto entra en el mismo link."
            />

            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {LO_QUE_TIENE.map(({ icono: Icono, titulo, detalle }) => (
                <Tarjeta key={titulo} className="p-6 flex flex-col gap-3">
                  <span className="w-10 h-10 rounded-full bg-verde-claro text-verde-oscuro border border-verde-medio flex items-center justify-center">
                    <Icono className="w-5 h-5" />
                  </span>
                  <h3 className="font-bold text-tinta leading-snug">{titulo}</h3>
                  <p className="text-sm text-tinta-media leading-relaxed">{detalle}</p>
                </Tarjeta>
              ))}
            </div>
          </div>
        </section>

        {/* ------------------------------------------------ ponerlo en la bio */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Cómo lo ponés en tu Instagram"
              bajada="Se hace una vez y no se toca nunca más."
            />

            <ol className="grid sm:grid-cols-3 gap-4">
              {PASOS_INSTAGRAM.map(({ titulo, detalle }, i) => (
                <li key={titulo}>
                  <Tarjeta className="p-6 h-full">
                    <span className="inline-flex items-center justify-center w-9 h-9 rounded-full bg-verde text-verde-tinta font-bold mb-4">
                      {i + 1}
                    </span>
                    <h3 className="font-bold text-tinta">{titulo}</h3>
                    <p className="text-sm text-tinta-media mt-2 leading-relaxed">{detalle}</p>
                  </Tarjeta>
                </li>
              ))}
            </ol>
          </div>
        </section>

        {/* ------------------------------------------------ con un asistente */}

        <section className="border-b border-borde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
            <div className="grid md:grid-cols-[1fr_auto] gap-10 items-center">
              <div>
                <Rotulo>Si ya usás ChatGPT o Claude</Rotulo>
                <h2 className="mt-4 text-3xl sm:text-4xl font-bold tracking-tight text-balance leading-tight">
                  Dictale la fecha y que la cargue él.
                </h2>
                <p className="mt-4 text-tinta-media leading-relaxed max-w-xl">
                  Se puede conectar tu asistente a tus páginas: le decís día, hora y
                  dirección y te deja el show publicado, con el afiche y la venta de
                  entradas abierta. Se conecta una vez, no se instala nada y no hay
                  comandos que aprender.
                </p>
                <div className="mt-7">
                  <Boton a="/asistentes" tamano="lg">
                    Cómo se conecta, paso por paso <ArrowRight className="w-4 h-4" />
                  </Boton>
                </div>
              </div>

              <div className="md:w-72">
                <Tarjeta className="p-5">
                  <div className="flex items-center gap-2 mb-3">
                    <Sparkles className="w-4 h-4 text-verde-oscuro" />
                    <Rotulo>Vos le escribís</Rotulo>
                  </div>
                  <p className="text-tinta leading-snug">
                    «Cargá un show el viernes 20 a las 21 en Niceto, entradas a 12.000.»
                  </p>
                  <div className="mt-4 pt-4 border-t border-borde flex items-center gap-2">
                    <Check className="w-4 h-4 text-verde-oscuro flex-shrink-0" />
                    <p className="text-sm text-tinta-media">Listo, ya está en tu página.</p>
                  </div>
                </Tarjeta>
              </div>
            </div>
          </div>
        </section>

        {/* ------------------------------------------------------- preguntas */}

        <section className="border-b border-borde">
          <div className="max-w-3xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion titulo="Preguntas" />

            <dl className="divide-y divide-borde border-y border-borde">
              {PREGUNTAS.map(({ q, a }) => (
                <div key={q} className="py-6">
                  <dt className="font-bold text-tinta">{q}</dt>
                  <dd className="text-tinta-media mt-2 leading-relaxed">{a}</dd>
                </div>
              ))}
            </dl>
          </div>
        </section>

        {/* ------------------------------------------------------ cierre */}

        <section>
          <div className="max-w-3xl mx-auto px-5 sm:px-6 py-20 text-center">
            <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-balance leading-tight">
              Tu próxima fecha merece un lugar donde encontrarla.
            </h2>
            <p className="mt-4 text-tinta-media text-lg">
              Armás la página, la pegás en tu bio y ya está.
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <Boton a={destinoCrear} tamano="lg">
                Armar mi página gratis <ArrowRight className="w-4 h-4" />
              </Boton>
              {!token && (
                <Boton a="/login" variante="secundario" tamano="lg">Ya tengo cuenta</Boton>
              )}
            </div>
            <p className="mt-6 text-sm text-tinta-suave">
              ¿Dudas? Escribinos a{' '}
              <a href="mailto:hola@rezon.ar" className="text-verde-oscuro font-semibold hover:underline underline-offset-4">
                hola@rezon.ar
              </a>
            </p>
          </div>
        </section>
      </main>

      <PieDePagina />
    </div>
  );
}

export default ParaArtistas;
