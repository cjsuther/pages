import React, { useContext, useState } from 'react';
import { Helmet } from 'react-helmet-async';
import {
  MessageSquare, CalendarPlus, Ticket, BarChart3, ImagePlus, ShieldCheck,
  ArrowRight, Check, Copy, Terminal, AlertTriangle,
} from 'lucide-react';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { PieDePagina } from '../components/Marco';
import ClavesApi, { Conexiones } from '../components/ClavesApi';
import { Aviso, Boton, Chip, Rotulo, Tarjeta, TituloSeccion } from '../components/ui';

/**
 * Cómo conectar un asistente a Rezonar, paso por paso y por aplicación.
 *
 * Está escrita para alguien que usa ChatGPT o Claude todos los días y no sabe
 * lo que es un MCP, así que la palabra aparece una vez y después no se usa más:
 * lo único que hay que hacer es pegar una dirección en un menú.
 *
 * Los menús de estas aplicaciones se mueven seguido. Por eso cada paso dice
 * dónde está hoy pero el título de la sección es lo que no cambia —la
 * dirección del servidor—, y hay un aviso explícito de que si el menú no está
 * donde dice, lo que hay que buscar es "conectores".
 */

/** La dirección que se pega. Es lo único que hay que copiar en la mayoría. */
const URL_MCP = 'https://rezon.ar/mcp';

const LO_QUE_PUEDE = [
  {
    icono: CalendarPlus,
    titulo: 'Cargar una fecha entera',
    detalle: 'Le pasás el día, la hora y la dirección, y te la deja publicada. La dirección se convierte en mapa sola, no tenés que buscar coordenadas.',
  },
  {
    icono: ImagePlus,
    titulo: 'Subir el afiche',
    detalle: 'Le mandás la imagen y la sube a tu fecha. Si tu asistente no puede manejar archivos, te devuelve un link para subirla vos desde el teléfono.',
  },
  {
    icono: Ticket,
    titulo: 'Abrir la venta de entradas',
    detalle: 'Precio, cupo y cuántas entradas por compra. También puede poner una fecha como gratuita con inscripción.',
  },
  {
    icono: BarChart3,
    titulo: 'Contarte cómo va',
    detalle: 'Cuántas vendiste, cuánto recaudaste y el detalle de cada compra, sin que entres al panel.',
  },
  {
    icono: MessageSquare,
    titulo: 'Corregir lo que está mal',
    detalle: 'Cambiar una hora, mover una fecha, arreglar el nombre de la sala. Le decís qué está mal y lo toca.',
  },
  {
    icono: ShieldCheck,
    titulo: 'Nada que vos no puedas hacer',
    detalle: 'Trabaja sobre tus páginas y nada más. No ve las de otra gente ni puede cobrar a tu nombre.',
  },
];

/**
 * Los pasos de cada aplicación.
 *
 * `oauth: true` significa que la aplicación se autoriza sola: te abre Rezonar
 * en el navegador y vos das el permiso ahí, sin copiar ninguna clave. Es el
 * caso de casi todas, y es lo que hace que esto sea pegar una dirección.
 */
const ASISTENTES = [
  {
    id: 'claude',
    nombre: 'Claude',
    donde: 'Web, aplicación de escritorio y teléfono',
    plan: 'Anda en el plan gratuito, con un conector propio',
    oauth: true,
    pasos: [
      'Abrí los ajustes de Claude y entrá en Conectores.',
      <>Tocá <strong>+</strong> y elegí <strong>Agregar conector personalizado</strong>.</>,
      <>Pegá <Url /> en la dirección del servidor y confirmá. No toques las opciones avanzadas: son para quien tiene credenciales propias.</>,
      'Tocá Conectar. Se abre Rezonar, entrás con tu cuenta y le das permiso.',
      <>En el chat, abrí el <strong>+</strong> del mensaje y prendé Rezonar para esa conversación.</>,
    ],
    nota: 'Si tenés plan de equipo, el conector lo agrega primero la persona que administra la organización y después cada uno toca Conectar.',
  },
  {
    id: 'chatgpt',
    nombre: 'ChatGPT',
    donde: 'Web y aplicación',
    plan: 'Hace falta un plan pago: en el gratuito no aparece',
    oauth: true,
    pasos: [
      <>Entrá en <strong>Configuración → Aplicaciones y conectores</strong>.</>,
      <>Abajo, abrí <strong>Configuración avanzada</strong> y prendé el <strong>Modo desarrollador</strong>. Te va a avisar que un conector propio corre código de un tercero: es este aviso, aceptalo.</>,
      <>Volvé a Conectores y tocá <strong>Agregar conector personalizado</strong>.</>,
      <>Ponele el nombre Rezonar, una descripción corta y pegá <Url /> como dirección.</>,
      <>En el tipo de autorización elegí <strong>OAuth</strong>. Confirmá y dale el permiso en la pantalla de Rezonar.</>,
      <>Para usarlo: en un chat nuevo abrí el <strong>+</strong>, entrá en <strong>Más → Modo desarrollador</strong> y elegí Rezonar.</>,
    ],
  },
  {
    id: 'lechat',
    nombre: 'Le Chat, de Mistral',
    donde: 'Web y aplicación',
    plan: 'Conectores propios en los planes que los incluyen',
    oauth: true,
    pasos: [
      <>Entrá en <strong>Conectores</strong> y tocá <strong>Agregar conector</strong>.</>,
      <>Cambiá a la pestaña <strong>Custom MCP Connector</strong>.</>,
      <>En el nombre poné <code className="text-tinta font-semibold">rezonar</code>, sin espacios ni acentos, que es como lo pide.</>,
      <>Pegá <Url /> en la dirección del servidor y tocá <strong>Conectar</strong>. Detecta solo que hay que autorizarse y te manda a Rezonar.</>,
      'Después aparece en el desplegable de herramientas del chat. Prendelo en la conversación donde lo quieras usar.',
    ],
  },
  {
    id: 'gemini',
    nombre: 'Gemini',
    donde: 'En la terminal, con Gemini CLI',
    plan: 'Gratis, pero se instala y se escribe a mano',
    oauth: true,
    terminal: true,
    pasos: [
      <>Con Gemini CLI instalado, corré: <Comando>gemini mcp add -t http rezonar https://rezon.ar/mcp</Comando></>,
      <>Abrí Gemini y pedí la autorización: <Comando>/mcp auth rezonar</Comando></>,
      'Se te abre el navegador en Rezonar, le das permiso y ya queda guardado.',
      <>Para comprobar que quedó: <Comando>/mcp list</Comando></>,
    ],
    nota: 'La aplicación de Gemini para celular y web todavía no deja agregar conectores propios: los admite la versión de terminal y la edición para empresas. Si usás la app, por ahora te sirven los otros tres.',
  },
];

const PREGUNTAS = [
  {
    q: '¿Tengo que instalar algo?',
    a: 'En Claude, ChatGPT y Le Chat no: se pega una dirección en un menú y listo. Gemini es la excepción porque se usa desde la terminal.',
  },
  {
    q: '¿Le estoy dando mi contraseña?',
    a: 'No. Cuando conectás, se abre Rezonar y el permiso lo das vos ahí. El asistente recibe una autorización que podés cortar cuando quieras, y nunca ve tu contraseña.',
  },
  {
    q: '¿Puede borrar algo sin avisarme?',
    a: 'Puede borrar una fecha si se lo pedís, y eso no se deshace. Si la fecha tenía entradas vendidas no la borra: te avisa, porque antes hay que resolver las compras.',
  },
  {
    q: '¿Sirve para manejar la página de mi banda y la mía?',
    a: 'Sí. Ve todas las páginas de tu cuenta. Conviene nombrarle cuál querés tocar, porque lo primero que hace es pedir la lista.',
  },
  {
    q: '¿Y si cambia el menú de la aplicación?',
    a: 'Es lo más probable, se mueven seguido. Lo que no cambia es la dirección del servidor: buscá la parte de conectores y pegala ahí.',
  },
  {
    q: '¿Cómo corto el acceso?',
    a: 'Desde esta misma página, en la lista de aplicaciones conectadas, o borrando el conector en la aplicación. En cuanto lo desconectás deja de poder hacer nada.',
  },
];

const PEDIDOS = [
  'Cargá un show el viernes 20 a las 21 en Niceto Club, Humboldt 1574, y poné entradas a 12.000 con cupo de 200.',
  'Pasame las fechas que tengo cargadas este mes y decime cuáles todavía no tienen afiche.',
  'Al show del sábado corregile la hora: es a las 22, no a las 21.',
  '¿Cómo van las ventas del show de Niceto? Decime cuántas quedan.',
];

/** La dirección del servidor, siempre igual y siempre copiable de un toque. */
function Url() {
  return <CodigoCopiable texto={URL_MCP} />;
}

function Comando({ children }) {
  return (
    <span className="block mt-2">
      <CodigoCopiable texto={children} />
    </span>
  );
}

/**
 * Un pedazo de código con un botón para copiarlo.
 *
 * Copiar a mano una dirección desde el teléfono es justo donde se abandona un
 * instructivo, así que el botón no es un lujo.
 */
function CodigoCopiable({ texto }) {
  const [copiado, setCopiado] = useState(false);

  const copiar = () => {
    if (navigator.clipboard) navigator.clipboard.writeText(texto);
    setCopiado(true);
    setTimeout(() => setCopiado(false), 2000);
  };

  return (
    <span className="inline-flex max-w-full items-center gap-2 align-middle rounded-lg border border-borde bg-papel-hueso px-2.5 py-1">
      <code className="min-w-0 overflow-x-auto whitespace-nowrap text-sm font-semibold text-tinta">
        {texto}
      </code>
      <button
        type="button"
        onClick={copiar}
        className="flex-shrink-0 text-tinta-suave transition-colors hover:text-verde-oscuro"
        aria-label={copiado ? 'Copiado' : `Copiar ${texto}`}
      >
        {copiado
          ? <Check className="h-4 w-4 text-verde-oscuro" />
          : <Copy className="h-4 w-4" />}
      </button>
    </span>
  );
}

function Asistente({ asistente }) {
  const { nombre, donde, plan, pasos, nota, terminal, oauth } = asistente;

  return (
    <Tarjeta className="p-6 sm:p-8">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-2xl font-bold tracking-tight text-tinta">{nombre}</h3>
          <p className="mt-1 text-sm text-tinta-suave">{donde}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {terminal && <Chip><Terminal className="h-3 w-3" /> Terminal</Chip>}
          {oauth && <Chip tono="verde">Sin clave</Chip>}
        </div>
      </div>

      <p className="mt-4 text-sm text-tinta-media">{plan}</p>

      <ol className="mt-6 space-y-4">
        {pasos.map((paso, i) => (
          <li key={i} className="flex gap-4">
            <span className="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-verde-claro text-sm font-bold text-verde-oscuro">
              {i + 1}
            </span>
            <div className="min-w-0 flex-1 leading-relaxed text-tinta">{paso}</div>
          </li>
        ))}
      </ol>

      {nota && <Aviso className="mt-6">{nota}</Aviso>}
    </Tarjeta>
  );
}

function ParaAsistentes() {
  const { token } = useContext(AuthContext);
  const destinoCrear = token ? '/my-pages' : '/register';
  const titulo = 'Conectá tu asistente a Rezonar';
  const bajada = 'Cargá tus fechas, subí el afiche y abrí la venta de entradas hablándole a '
    + 'Claude, ChatGPT, Le Chat o Gemini. Se conecta una vez y no se instala nada.';

  return (
    <div className="flex min-h-screen flex-col bg-white text-tinta">
      <Helmet>
        <title>{titulo} — Rezonar</title>
        <meta name="description" content={bajada} />
        <meta property="og:title" content={`${titulo} — Rezonar`} />
        <meta property="og:description" content={bajada} />
        <meta property="og:type" content="website" />
        <link rel="canonical" href={window.location.href} />
      </Helmet>

      <Navigation />

      <main className="flex-1">

        {/* ------------------------------------------------------------ hero */}

        <section className="border-b border-borde">
          <div className="mx-auto max-w-7xl px-5 py-14 sm:px-6 sm:py-20">
            <div className="grid items-center gap-12 lg:grid-cols-[1.15fr_1fr] lg:gap-16">
              <div>
                <Rotulo>Para quien ya usa un asistente</Rotulo>
                <h1 className="mt-4 text-balance text-4xl font-bold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">
                  Decile la fecha.{' '}
                  <span className="text-verde-oscuro">Que la cargue él.</span>
                </h1>
                <p className="mt-5 max-w-xl text-lg leading-relaxed text-tinta-media">
                  Tu asistente puede trabajar directo sobre tus páginas de Rezonar: cargar
                  shows, subir afiches, abrir la venta de entradas y contarte cómo van. Le
                  pegás una dirección una vez y listo.
                </p>

                <div className="mt-8 flex flex-wrap gap-3">
                  <Boton href="#pasos" tamano="lg">
                    Ver los pasos <ArrowRight className="h-4 w-4" />
                  </Boton>
                  <Boton a="/artistas" variante="secundario" tamano="lg">
                    Qué es Rezonar
                  </Boton>
                </div>

                <ul className="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm text-tinta-media">
                  {['Sin instalar nada', 'Sin copiar claves', 'Dos minutos'].map((t) => (
                    <li key={t} className="inline-flex items-center gap-1.5">
                      <Check className="h-4 w-4 text-verde-oscuro" /> {t}
                    </li>
                  ))}
                </ul>
              </div>

              {/* La dirección, que es lo único que hay que copiar, y un ejemplo
                  de cómo se le habla. */}
              <div className="w-full lg:justify-self-end">
                <Tarjeta className="p-6">
                  <Rotulo>La dirección del servidor</Rotulo>
                  <div className="mt-3">
                    <CodigoCopiable texto={URL_MCP} />
                  </div>
                  <p className="mt-3 text-xs leading-relaxed text-tinta-suave">
                    Esto es todo lo que se pega. Después te pide el permiso en Rezonar,
                    como cuando entrás a un sitio con tu cuenta de Google.
                  </p>

                  <div className="mt-6 rounded-xl border border-verde-medio bg-verde-claro p-4">
                    <Rotulo className="!text-verde-oscuro">Y después le escribís</Rotulo>
                    <p className="mt-2 leading-snug text-verde-oscuro">
                      «Cargá un show el viernes 20 a las 21 en Niceto, entradas a 12.000
                      con cupo de 200.»
                    </p>
                  </div>
                </Tarjeta>
              </div>
            </div>
          </div>
        </section>

        {/* ------------------------------------------------------ qué hace */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="mx-auto max-w-7xl px-5 py-14 sm:px-6">
            <TituloSeccion
              titulo="Lo que puede hacer por vos"
              bajada="Lo mismo que hacés en el panel, sin entrar al panel."
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {LO_QUE_PUEDE.map(({ icono: Icono, titulo: t, detalle }) => (
                <Tarjeta key={t} className="flex flex-col gap-3 p-6">
                  <span className="flex h-10 w-10 items-center justify-center rounded-full border border-verde-medio bg-verde-claro text-verde-oscuro">
                    <Icono className="h-5 w-5" />
                  </span>
                  <h3 className="font-bold leading-snug text-tinta">{t}</h3>
                  <p className="text-sm leading-relaxed text-tinta-media">{detalle}</p>
                </Tarjeta>
              ))}
            </div>
          </div>
        </section>

        {/* ------------------------------------------------------- los pasos */}

        <section id="pasos" className="scroll-mt-8 border-b border-borde">
          <div className="mx-auto max-w-4xl px-5 py-14 sm:px-6">
            <TituloSeccion
              titulo="Paso por paso, según lo que uses"
              bajada="Antes de empezar necesitás tu cuenta de Rezonar con al menos una página armada."
            />

            {!token && (
              <Aviso tipo="ok" className="mb-8">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  Todavía no tenés página.
                  <Boton a={destinoCrear} tamano="sm">Armar la mía gratis</Boton>
                </span>
              </Aviso>
            )}

            <Aviso tipo="atencion" className="mb-8">
              <span className="flex items-start gap-2">
                <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>
                  Estos menús se mueven seguido. Si el tuyo no está donde dice acá, buscá la
                  parte de <strong className="font-semibold">conectores</strong> y pegá la
                  misma dirección ahí. Es lo único que no cambia.
                </span>
              </span>
            </Aviso>

            <div className="space-y-6">
              {ASISTENTES.map((a) => <Asistente key={a.id} asistente={a} />)}
            </div>
          </div>
        </section>

        {/* ------------------------------------------------- cómo se le pide */}

        <section className="border-b border-borde bg-verde">
          <div className="mx-auto max-w-4xl px-5 py-16 sm:px-6">
            <Rotulo className="!text-verde-tinta/70">Ya está conectado</Rotulo>
            <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-verde-tinta sm:text-4xl">
              Ahora hablale como le hablarías a alguien de tu equipo.
            </h2>
            <p className="mt-4 max-w-2xl leading-relaxed text-verde-tinta/85">
              No hay comandos que aprender. Decile qué querés y si le falta un dato te lo
              pregunta. Cuatro ejemplos que funcionan tal cual:
            </p>

            <ul className="mt-8 space-y-3">
              {PEDIDOS.map((p) => (
                <li key={p} className="rounded-xl border border-verde-tinta/10 bg-white p-4 leading-relaxed text-tinta">
                  «{p}»
                </li>
              ))}
            </ul>
          </div>
        </section>

        {/* ---------------------------------------------------- otros clientes */}

        <section className="border-b border-borde">
          <div className="mx-auto max-w-4xl px-5 py-14 sm:px-6">
            <TituloSeccion
              titulo="Otros programas, o algo propio"
              bajada="Si lo que usás no sabe autorizarse solo, hace falta una clave. Es el único caso."
            />

            <p className="leading-relaxed text-tinta-media">
              Pasa con los programas de escritorio que leen su configuración de un archivo,
              como un editor de código, y con cualquier script propio. Ahí la dirección va
              acompañada de una clave en la cabecera de autorización:
            </p>

            <pre className="mt-5 overflow-x-auto rounded-xl border border-borde bg-papel-hueso p-4 text-xs leading-relaxed text-tinta">
{`{
  "mcpServers": {
    "rezonar": {
      "type": "http",
      "url": "${URL_MCP}",
      "headers": {
        "Authorization": "Bearer rzn_tu_clave"
      }
    }
  }
}`}
            </pre>

            <p className="mt-5 leading-relaxed text-tinta-media">
              La clave da acceso a todo lo que vos podés hacer, así que se comparte como se
              comparte una contraseña: no va en un repositorio ni en un chat.
            </p>

            {token ? (
              <div className="mt-8 space-y-6">
                <ClavesApi />
                <Conexiones />
              </div>
            ) : (
              <Aviso className="mt-8">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  Las claves se crean con la sesión abierta.
                  <Boton a="/login" variante="secundario" tamano="sm">Entrar a mi cuenta</Boton>
                </span>
              </Aviso>
            )}
          </div>
        </section>

        {/* ------------------------------------------------------- preguntas */}

        <section className="border-b border-borde">
          <div className="mx-auto max-w-3xl px-5 py-14 sm:px-6">
            <TituloSeccion titulo="Preguntas" />

            <dl className="divide-y divide-borde border-y border-borde">
              {PREGUNTAS.map(({ q, a }) => (
                <div key={q} className="py-6">
                  <dt className="font-bold text-tinta">{q}</dt>
                  <dd className="mt-2 leading-relaxed text-tinta-media">{a}</dd>
                </div>
              ))}
            </dl>
          </div>
        </section>

        {/* ---------------------------------------------------------- cierre */}

        <section>
          <div className="mx-auto max-w-3xl px-5 py-20 text-center sm:px-6">
            <h2 className="text-balance text-3xl font-bold leading-tight tracking-tight sm:text-4xl">
              La fecha que ibas a cargar mañana, cargala hablando.
            </h2>
            <p className="mt-4 text-lg text-tinta-media">
              Conectás el asistente una vez y no lo tocás más.
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <Boton a={destinoCrear} tamano="lg">
                {token ? 'Ir a mis páginas' : 'Armar mi página gratis'}{' '}
                <ArrowRight className="h-4 w-4" />
              </Boton>
              <Boton href="#pasos" variante="secundario" tamano="lg">
                Volver a los pasos
              </Boton>
            </div>
            <p className="mt-6 text-sm text-tinta-suave">
              ¿Se te trabó en algún paso? Escribinos a{' '}
              <a href="mailto:hola@rezon.ar" className="font-semibold text-verde-oscuro underline-offset-4 hover:underline">
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

export default ParaAsistentes;
