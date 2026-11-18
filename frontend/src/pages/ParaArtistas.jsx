import React, { useContext } from 'react';
import { Helmet } from 'react-helmet-async';
import { Link } from 'react-router';
import {
  CalendarDays, Bell, Ticket, Palette, QrCode, Users, Link2, MapPin, Download,
  ArrowRight, Check, Instagram, Globe, Sparkles, Crop, Images, Search, Target,
  Package, Handshake, Tags, Armchair, ScanLine, Gift, RefreshCw, BarChart3,
  FileSpreadsheet, Mail,
} from 'lucide-react';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { PieDePagina } from '../components/Marco';
import { Boton, Rotulo, Tarjeta, TituloSeccion } from '../components/ui';
import { formatearPorcentaje } from '../utils/comisiones';
import { calcularCargos, CARGO_MERCADOPAGO, CARGO_PLATAFORMA } from '../utils/cargos';
import { useCostos, CuentaDeEjemplo, PRECIO_EJEMPLO, pesos } from '../components/CostosDeVenta';

/**
 * La página que le explica a un artista para qué le sirve Rezonar.
 *
 * Está escrita para alguien que ya tiene un Linktree o nada: el argumento no
 * es "juntá tus links" —eso lo hace cualquiera— sino que acá su público lo
 * puede seguir y se entera de cada fecha sin tener que entrar a mirar, y que
 * las entradas se venden y se controlan desde la misma página.
 *
 * Los números (comisiones, plazo de cobro) no se escriben acá: salen del
 * servidor, igual que en /precios, que es la referencia. Si las comisiones a
 * cargo de quien compra están apagadas en la plataforma (costos.cargos llega
 * en null), la página no las ofrece.
 */

const LO_QUE_TIENE = [
  {
    icono: CalendarDays,
    titulo: 'Todas tus fechas, ordenadas',
    detalle: 'Cargás un show con día, hora y dirección. Cuando pasa, se va solo: nunca queda una fecha vieja arriba.',
  },
  {
    icono: MapPin,
    titulo: 'Dónde es, con mapa',
    detalle: 'Cada fecha lleva su ubicación y cómo llegar. Nadie te tiene que preguntar la dirección por privado.',
  },
  {
    icono: Bell,
    titulo: 'Avisos a quien te sigue',
    detalle: 'Publicás una fecha y le llega una notificación al teléfono a cada seguidor. Esto un Linktree no lo hace.',
  },
  {
    icono: Search,
    titulo: 'En la agenda de Rezonar',
    detalle: 'Tus fechas aparecen en el mapa y la agenda, con su precio, para quien busca qué hacer.',
  },
  {
    icono: Palette,
    titulo: 'Con tu identidad',
    detalle: 'Elegís plantilla, colores, foto y fondo. La página es tuya, sin nuestro logo arriba.',
  },
  {
    icono: Crop,
    titulo: 'Fotos que se ven bien',
    detalle: 'Recortás cada imagen al subirla y queda prolija en la página, en la tarjeta y al compartirla.',
  },
  {
    icono: Images,
    titulo: 'Galería',
    detalle: 'Fotos y videos de tus shows en la misma página.',
  },
  {
    icono: Link2,
    titulo: 'Tus links y tus redes',
    detalle: 'Spotify, YouTube, Instagram, prensa, lo que sea. Agrupados y en el orden que quieras.',
  },
  {
    icono: QrCode,
    titulo: 'Un QR para el afiche',
    detalle: 'Te lo bajás listo para imprimir. Siempre lleva a tus fechas de ahora, no a las del mes pasado.',
  },
  {
    icono: Download,
    titulo: 'Productos digitales',
    detalle: 'Un libro en PDF, un disco o un curso. Cada comprador recibe su link privado para descargarlo.',
  },
  {
    icono: Package,
    titulo: 'Productos físicos',
    detalle: 'Remeras, vinilos, merch: con talles y colores, stock, y envío, retiro o a coordinar.',
  },
  {
    icono: Target,
    titulo: 'Tus métricas',
    detalle: 'Visitas, de dónde llegan, edad y género de tu público, y tu propio pixel de Meta.',
  },
];

const VENDER_ENTRADAS = [
  {
    icono: Tags,
    titulo: 'Tipos de entrada y promos',
    detalle: 'General, Jubilados, VIP, 2x1: cada uno con su precio y su cupo. O reservas sin costo.',
  },
  {
    icono: Armchair,
    titulo: 'Butacas y mesas',
    detalle: 'Dibujás el plano de tu sala y cada quien elige su lugar. Lo copiás de un evento al siguiente.',
  },
  {
    icono: Ticket,
    titulo: 'Entrada con QR por mail',
    detalle: 'Le llega a cada comprador apenas paga, y la puede volver a abrir desde su link.',
  },
  {
    icono: ScanLine,
    titulo: 'Control en la puerta',
    detalle: 'Le pasás el link de puerta a tu equipo: escanean el QR desde el celular o buscan por nombre.',
  },
  {
    icono: Gift,
    titulo: 'Invitados sin costo',
    detalle: 'Desde el evento sumás invitados: reciben su entrada con QR por mail o WhatsApp, sin pagar ni dejar comisión.',
  },
  {
    icono: RefreshCw,
    titulo: 'Cambios sin volver a cobrar',
    detalle: 'Modificás una compra y a quien compró le llega la entrada actualizada por mail.',
  },
  {
    icono: BarChart3,
    titulo: 'Cómo va la venta',
    detalle: 'Ventas por día y un link en vivo para que tu socio o la sala vean cómo viene, sin entrar a tu cuenta.',
  },
  {
    icono: FileSpreadsheet,
    titulo: 'Todo en Excel',
    detalle: 'Bajás las ventas de cada evento y tu lista de clientes, que queda después del show.',
  },
  {
    icono: Mail,
    titulo: 'Mails a tus clientes',
    detalle: 'Les escribís desde tu propia casilla, por tandas y con baja en un clic, o por WhatsApp.',
  },
];

const ENTRE_VARIOS = [
  {
    icono: Users,
    titulo: 'Tu equipo',
    detalle: 'Sumás administradores a tu página —quien maneja tus redes, tu productor— y cada uno entra con su cuenta.',
  },
  {
    icono: Handshake,
    titulo: 'Fechas en conjunto',
    detalle: 'Invitás a otra página a un show y aparece en las dos. Con las de confianza marcás “Aceptar siempre” y se publican solas.',
  },
  {
    icono: Sparkles,
    titulo: 'Cargala hablando',
    detalle: 'Conectás ChatGPT o Claude a tu página y cargás una fecha dictándola, con afiche y entradas.',
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

const enlace = 'text-verde-oscuro font-semibold hover:underline underline-offset-4';

/** Las preguntas; la de cuánto sale depende de si quien compra puede pagar las comisiones. */
function preguntas(eligeQuienPaga) {
  return [
    {
      q: '¿Cuánto sale?',
      a: (
        <>
          Armar tu página, cargar fechas, que te sigan y las herramientas para vender son gratis,
          sin abono mensual. Sólo hay una comisión cuando vendés una entrada o un producto pago, más
          la de Mercado Pago por procesar el cobro.
          {eligeQuienPaga && ' En las entradas, los eventos nuevos arrancan con esas comisiones a cargo de quien compra, y lo podés cambiar en cada evento.'}
          {' '}Las reservas sin costo, los invitados y los productos gratis no pagan nada. El detalle,
          con una cuenta de ejemplo, está en <Link to="/precios" className={enlace}>Precios</Link>.
        </>
      ),
    },
    {
      q: '¿Cuándo me llega la plata?',
      a: 'Cada venta entra directo a tu cuenta de Mercado Pago a los pocos días de la compra, no cuando termina el evento. Con la preventa ya podés pagar la sala o la difusión.',
    },
    {
      q: '¿Puedo regalar entradas?',
      a: 'Sí. En cualquier evento con entradas, en la solapa Invitados, cargás el nombre y, si querés, el mail. Cada invitado recibe una entrada con QR como la de cualquier comprador y entra por la misma puerta. No paga ni deja comisión, pero ocupa un lugar del cupo.',
    },
    {
      q: '¿Cómo controlo la entrada el día del show?',
      a: 'Creás el link de puerta del evento y se lo pasás a quien esté en la entrada. Lo abre en su celular, sin cuenta: escanea el QR o busca a la persona por nombre. Pueden ser varios a la vez.',
    },
    {
      q: '¿Puedo vender un libro, un disco o una remera?',
      a: 'Sí. Los digitales (hasta 50 MB; si son varios archivos, en un ZIP) le llegan a cada comprador como un link privado de descarga, y ves quién ya lo bajó. Los físicos llevan talles, colores y stock, y elegís envío, retiro o a coordinar. Pagan la misma comisión que una entrada, a cargo de quien vende.',
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
      q: '¿Puedo armar una fecha con otra banda o con la sala?',
      a: 'Sí. Invitás a su página a colaborar y, cuando acepta, el show aparece en las dos. Si trabajan seguido juntos, al aceptar marcan “Aceptar siempre” y las próximas se publican solas.',
    },
    {
      q: '¿Puedo cargar las fechas con ChatGPT o Claude?',
      a: (
        <>
          Sí. Conectás tu asistente a tus páginas una vez y después le dictás el show: día, hora,
          dirección, afiche y entradas. Está explicado paso por paso en{' '}
          <Link to="/asistentes" className={enlace}>la página de asistentes</Link>.
        </>
      ),
    },
    {
      q: '¿Hacés stand-up?',
      a: 'En Carcajada (carcajada.rezon.ar) los comediantes se anotan con su misma cuenta de Rezonar, y los shows se arman en un solo lugar.',
    },
    {
      q: '¿Y si tengo dominio propio?',
      a: (
        <>
          Se puede: tu página queda en tu dominio, con tu nombre. Escribinos antes a{' '}
          <a href="mailto:hola@rezon.ar" className={enlace}>hola@rezon.ar</a> y lo configuramos juntos.
        </>
      ),
    },
  ];
}

/** "a los 3 días de cada compra", o sin número si el servidor no lo informa. */
function plazoDeCobro(mercadopago) {
  const dias = mercadopago ? mercadopago.dias : null;
  if (dias === null || dias === undefined) return 'a los pocos días de cada compra';
  if (dias === 0) return 'en el momento de cada compra';
  return `a los ${dias} ${dias === 1 ? 'día' : 'días'} de cada compra`;
}

/** $566,60 y no $566,6: con centavos, los dos. */
function plata(n) {
  return Number.isInteger(n)
    ? pesos(n)
    : '$' + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Una entrada de ejemplo con las comisiones a cargo de quien compra: la misma
 * cuenta del checkout (utils/cargos.js), como en /precios.
 */
function CuentaQuienCompra({ tasas, className = '' }) {
  const { precio, plataforma, mercadopago, total } = calcularCargos(PRECIO_EJEMPLO, tasas);

  return (
    <Tarjeta destacada className={`p-6 ${className}`}>
      <Rotulo>Una entrada de {pesos(PRECIO_EJEMPLO)}</Rotulo>
      <dl className="mt-4 space-y-2 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-tinta-media">Entrada</dt>
          <dd className="text-tinta tabular-nums">{plata(precio)}</dd>
        </div>
        {plataforma > 0 && (
          <div className="flex justify-between gap-4">
            <dt className="text-tinta-media">{CARGO_PLATAFORMA}</dt>
            <dd className="text-tinta tabular-nums">+{plata(plataforma)}</dd>
          </div>
        )}
        <div className="flex justify-between gap-4">
          <dt className="text-tinta-media">{CARGO_MERCADOPAGO}</dt>
          <dd className="text-tinta tabular-nums">+≈ {plata(mercadopago)}</dd>
        </div>
        <div className="flex justify-between gap-4 pt-3 mt-1 border-t border-borde font-bold">
          <dt className="text-tinta">Quien compra paga</dt>
          <dd className="text-tinta tabular-nums">≈ {plata(total)}</dd>
        </div>
      </dl>
      <p className="mt-4 flex items-start gap-2 text-sm font-semibold text-verde-oscuro">
        <Check className="w-4 h-4 flex-shrink-0 mt-0.5" />
        <span>Vos recibís el precio completo: {plata(precio)}.</span>
      </p>
    </Tarjeta>
  );
}

function CostosDeVenta({ costos }) {
  const { comision, mercadopago, cargos } = costos;
  const conIva = cargos && Number(cargos.mp_iva) > 0;

  return (
    <section className="border-b border-borde bg-papel-hueso">
      <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
        <TituloSeccion
          titulo="Cuánto cuesta vender entradas y productos"
          bajada="Armar la página y publicar fechas es gratis. Sólo hay costo cuando vendés algo pago."
        />

        <div className="grid md:grid-cols-[1fr_auto] gap-8 items-start">
          <ul className="space-y-4 text-tinta-media leading-relaxed">
            <li>
              <strong className="text-tinta">Comisión de Rezonar: {formatearPorcentaje(comision)}%</strong>{' '}
              por cada entrada o producto vendido.
            </li>
            {mercadopago && (
              <li>
                <strong className="text-tinta">
                  Mercado Pago: {formatearPorcentaje(mercadopago.porcentaje)}%{conIva && ' + IVA'}
                </strong>{' '}
                por procesar el pago. Eso lo cobra Mercado Pago, no nosotros; está en{' '}
                <a
                  href="https://www.mercadopago.com.ar/costs-section/release-options"
                  target="_blank"
                  rel="noopener noreferrer"
                  className={enlace}
                >
                  sus costos
                </a>
                .
              </li>
            )}
            {cargos ? (
              <li>
                <strong className="text-tinta">En las entradas, las comisiones las puede pagar quien compra.</strong>{' '}
                Los eventos nuevos arrancan así: se suman al precio, a la vista antes de pagar, y
                vos recibís el precio completo. Si preferís absorberlas, lo cambiás en cada evento.
                En los productos, la comisión la paga quien vende.
              </li>
            ) : (
              <li>
                <strong className="text-tinta">Quien compra paga el precio que pusiste,</strong>{' '}
                una sola vez, y las comisiones se descuentan de lo que cobrás.
              </li>
            )}
            <li>
              <strong className="text-tinta">Cobrás {plazoDeCobro(mercadopago)},</strong>{' '}
              directo en tu cuenta de Mercado Pago. No después del evento.
            </li>
            <li>
              <strong className="text-tinta">Las reservas sin costo, los invitados y los productos gratis no pagan nada.</strong>{' '}
              Tampoco hay abono mensual ni costo por publicar fechas.
            </li>
          </ul>

          {cargos
            ? <CuentaQuienCompra tasas={cargos} className="md:w-80" />
            : <CuentaDeEjemplo costos={costos} className="md:w-72" />}
        </div>

        <div className="mt-8">
          <Boton a="/precios" variante="secundario">
            Ver precios y cuándo cobrás <ArrowRight className="w-4 h-4" />
          </Boton>
        </div>
      </div>
    </section>
  );
}

function GrillaDeTarjetas({ items, columnas = 'sm:grid-cols-2 lg:grid-cols-4' }) {
  return (
    <div className={`grid ${columnas} gap-4`}>
      {items.map(({ icono: Icono, titulo, detalle }) => (
        <Tarjeta key={titulo} className="p-6 flex flex-col gap-3">
          <span className="w-10 h-10 rounded-full bg-verde-claro text-verde-oscuro border border-verde-medio flex items-center justify-center">
            <Icono className="w-5 h-5" />
          </span>
          <h3 className="font-bold text-tinta leading-snug">{titulo}</h3>
          <p className="text-sm text-tinta-media leading-relaxed">{detalle}</p>
        </Tarjeta>
      ))}
    </div>
  );
}

function ParaArtistas() {
  const { token, apiUrl } = useContext(AuthContext);
  const destinoCrear = token ? '/my-pages' : '/register';
  const costos = useCostos(apiUrl);
  const PREGUNTAS = preguntas(Boolean(costos && costos.cargos));

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet>
        <title>Rezonar para artistas — Tus fechas, tus entradas y tu público en un solo link</title>
        <meta name="description" content="Armá tu página gratis, cargá tus fechas y ponela en tu Instagram. Tu público te sigue y recibe un aviso con cada show nuevo, y vendés las entradas con QR y control en la puerta desde la misma página." />
        <meta property="og:title" content="Rezonar para artistas — Tus fechas, tus entradas y tu público en un solo link" />
        <meta property="og:description" content="Armá tu página gratis, cargá tus fechas y ponela en tu Instagram. Tu público te sigue y recibe un aviso con cada show nuevo, y vendés las entradas con QR y control en la puerta desde la misma página." />
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
                <Rotulo>Para artistas, bandas, ciclos, productoras y salas</Rotulo>
                <h1 className="mt-4 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.05] text-balance">
                  Instagram te da un link.{' '}
                  <span className="text-verde-oscuro">Que ese link sea todo.</span>
                </h1>
                <p className="mt-5 text-lg text-tinta-media max-w-xl leading-relaxed">
                  Tu página de Rezonar tiene tus fechas, tus redes y la venta de tus entradas,
                  con QR y control en la puerta. Y tu público te puede seguir: cada vez que
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
                  {['Gratis', 'Sin abono mensual', 'Sin instalar nada'].map((t) => (
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
                ['Cada show en otra ticketera', 'Tu público tiene que adivinar dónde se compra, y vos esperás a que termine el evento para cobrar.'],
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
              bajada="Todo esto entra en el mismo link, y es gratis."
            />

            <GrillaDeTarjetas items={LO_QUE_TIENE} />
          </div>
        </section>

        {/* ------------------------------------------------- vender entradas */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="max-w-7xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Vendé tus entradas, de la preventa a la puerta"
              bajada="Desde tu propia página, sin mandar a tu público a otro sitio. Y la plata te llega a los pocos días de cada compra."
            />

            <GrillaDeTarjetas items={VENDER_ENTRADAS} columnas="sm:grid-cols-2 lg:grid-cols-3" />
          </div>
        </section>

        {/* -------------------------------------------------------- en equipo */}

        <section className="border-b border-borde">
          <div className="max-w-7xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Entre varios"
              bajada="Para que no tengas que hacer todo vos."
            />

            <GrillaDeTarjetas items={ENTRE_VARIOS} columnas="sm:grid-cols-2 lg:grid-cols-3" />
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

        {/* ------------------------------------------------------- costos */}

        {costos ? (
          <CostosDeVenta costos={costos} />
        ) : (
          <section className="border-b border-borde bg-papel-hueso">
            <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
              <TituloSeccion titulo="Cuánto cuesta" />
              <p className="text-tinta-media leading-relaxed max-w-2xl">
                Tu página y tus fechas son gratis. Hay una comisión por cada entrada o producto
                pago, y Mercado Pago cobra la suya por procesar el pago. Las reservas sin costo,
                los invitados y los productos gratis no pagan nada.
              </p>
              <div className="mt-8">
                <Boton a="/precios" variante="secundario">
                  Ver precios <ArrowRight className="w-4 h-4" />
                </Boton>
              </div>
            </div>
          </section>
        )}

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
              Armás la página, la pegás en tu bio y, cuando quieras, abrís la venta de entradas.
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
              <a href="mailto:hola@rezon.ar" className={enlace}>
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
