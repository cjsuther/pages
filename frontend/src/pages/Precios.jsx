import React, { useContext } from 'react';
import { Helmet } from 'react-helmet-async';
import {
  CalendarDays, Bell, Palette, QrCode, Users, Link2, MapPin, Globe, Sparkles,
  ScanLine, BarChart3, Mail, Armchair, FileSpreadsheet, Gift, Download,
  ArrowRight, Check, Clock, Crop, Images, Share2, Search, MessageCircle,
  Ticket, Tags, RefreshCw, Package, Handshake, LogIn, Mic, Target,
} from 'lucide-react';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { PieDePagina } from '../components/Marco';
import { Boton, Rotulo, Tarjeta, TituloSeccion } from '../components/ui';
import { formatearPorcentaje } from '../utils/comisiones';
import { calcularCargos, CARGO_MERCADOPAGO, CARGO_PLATAFORMA } from '../utils/cargos';
import { useCostos, CuentaDeEjemplo, PRECIO_EJEMPLO, pesos } from '../components/CostosDeVenta';

/**
 * Lo que cuesta Rezonar, en una sola página.
 *
 * El argumento tiene tres partes: casi todo es gratis (y es mucho: por eso
 * "Qué incluye" va agrupado por lo que le resuelve a quien organiza), cuando
 * vendés elegís quién paga la comisión, y la plata te llega a los pocos días
 * de cada compra, no cuando termina el evento.
 *
 * Los números (nuestra comisión, la de Mercado Pago, su IVA y los días) salen
 * del servidor, y el ejemplo de "la paga quien compra" se calcula con
 * utils/cargos.js, la misma cuenta del checkout: así la página no puede
 * prometer un total distinto del que se cobra. Si no llegan, la página habla
 * sin números en vez de inventarlos; si la opción de que pague quien compra
 * está apagada en la plataforma, no se ofrece.
 */

/** "Qué incluye", agrupado por lo que le resuelve a quien organiza. */
const INCLUYE = [
  {
    tema: 'Tu página y tus fechas',
    items: [
      { icono: Globe, titulo: 'Tu página', detalle: 'rezon.ar/tunombre, o tu propio dominio.' },
      { icono: CalendarDays, titulo: 'Fechas sin límite', detalle: 'Las que quieras, y las viejas se van solas.' },
      { icono: MapPin, titulo: 'Ubicación con mapa', detalle: 'Dirección y cómo llegar en cada fecha.' },
      { icono: Palette, titulo: 'Plantillas y colores', detalle: 'Tu identidad, sin nuestro logo arriba.' },
      { icono: Crop, titulo: 'Fotos que se ven bien', detalle: 'Recortás cada imagen al subirla y queda prolija en la tarjeta y al compartirla.' },
      { icono: Images, titulo: 'Galería', detalle: 'Fotos y videos de tus shows en la misma página.' },
      { icono: Link2, titulo: 'Links y redes', detalle: 'Agrupados y en el orden que quieras.' },
      { icono: QrCode, titulo: 'QR para el afiche', detalle: 'Siempre lleva a tus fechas de ahora.' },
    ],
  },
  {
    tema: 'Llegar a tu público',
    items: [
      { icono: Bell, titulo: 'Avisos a tus seguidores', detalle: 'Cada fecha nueva les llega al teléfono.' },
      { icono: Search, titulo: 'En la agenda de Rezonar', detalle: 'Tus fechas aparecen en el mapa y con su precio, para quien busca qué hacer.' },
      { icono: Share2, titulo: 'Para compartir', detalle: 'Cada evento y producto tiene su link, con vista previa en WhatsApp.' },
      { icono: Mail, titulo: 'Mails a tus clientes', detalle: 'Desde tu propia casilla, por tandas y con baja en un clic.' },
      { icono: MessageCircle, titulo: 'WhatsApp a un toque', detalle: 'Le escribís a un cliente con un mensaje que ya dejaste armado.' },
      { icono: Target, titulo: 'Tus métricas', detalle: 'Visitas, de dónde llegan, edad y género, y tu propio pixel de Meta.' },
    ],
  },
  {
    tema: 'Vender entradas',
    items: [
      { icono: Gift, titulo: 'Reservas sin costo', detalle: 'Para eventos gratuitos o con lista: no pagan nada.' },
      { icono: Tags, titulo: 'Tipos de entrada y promos', detalle: 'General, Jubilados, VIP, 2x1: cada uno con su precio y su cupo.' },
      { icono: Armchair, titulo: 'Plano de ubicaciones', detalle: 'Butacas y mesas numeradas, si tu sala las tiene.' },
      { icono: Ticket, titulo: 'Entrada por mail con QR', detalle: 'Le llega a cada comprador apenas paga.' },
      { icono: ScanLine, titulo: 'Control en la puerta', detalle: 'Escaneás desde el celular, con varias puertas a la vez.' },
      { icono: RefreshCw, titulo: 'Cambios sin volver a cobrar', detalle: 'Modificás una compra y a quien compró le llega el aviso por mail.' },
      { icono: BarChart3, titulo: 'Venta en vivo para compartir', detalle: 'Un link para que tu socio vea cómo va la venta.' },
      { icono: FileSpreadsheet, titulo: 'Tus clientes en Excel', detalle: 'Quedan después del evento y los podés exportar.' },
    ],
  },
  {
    tema: 'Vender productos',
    items: [
      { icono: Download, titulo: 'Productos digitales', detalle: 'Un libro, un disco o un curso: cada comprador recibe su link de descarga privado.' },
      { icono: Package, titulo: 'Productos físicos', detalle: 'Remeras, vinilos, merch: con talles y colores, stock, y envío, retiro o a coordinar.' },
      { icono: Mail, titulo: 'Pedidos al día', detalle: 'Cada cambio de estado le llega por mail a quien compró.' },
    ],
  },
  {
    tema: 'Trabajar en equipo',
    items: [
      { icono: Users, titulo: 'Tu equipo', detalle: 'Sumás administradores y cada uno entra con su cuenta.' },
      { icono: Handshake, titulo: 'Fechas en conjunto', detalle: 'Compartís shows con otras páginas, y las de confianza se aceptan solas.' },
      { icono: Sparkles, titulo: 'Carga con ChatGPT o Claude', detalle: 'Dictás la fecha y queda publicada.' },
      { icono: LogIn, titulo: 'Entrás con Google o Apple', detalle: 'Sin otra contraseña para recordar.' },
      { icono: Mic, titulo: 'Carcajada, para el stand-up', detalle: 'Los comediantes se anotan y los shows se arman en un solo lugar.' },
    ],
  },
];

function GrillaGratis({ items }) {
  return (
    <ul className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
      {items.map(({ icono: Icono, titulo, detalle }) => (
        <li key={titulo} className="flex gap-3 p-4 rounded-2xl border border-borde bg-white">
          <span className="w-9 h-9 rounded-full bg-verde-claro text-verde-oscuro border border-verde-medio flex items-center justify-center flex-shrink-0">
            <Icono className="w-4 h-4" />
          </span>
          <div className="min-w-0">
            <p className="font-bold text-tinta leading-snug">{titulo}</p>
            <p className="text-sm text-tinta-media leading-snug mt-0.5">{detalle}</p>
          </div>
        </li>
      ))}
    </ul>
  );
}

/** "a los 3 días de cada compra", o sin número si el servidor no lo informa. */
function plazoDeCobro(costos) {
  const dias = costos && costos.mercadopago ? costos.mercadopago.dias : null;
  if (dias === null) return 'a los pocos días de cada compra';
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
 * Las dos cuentas de una entrada de ejemplo, con las tasas del servidor.
 *
 * "La paga quien compra" es calcularCargos tal cual: lo que va a ver el
 * comprador en el checkout. "La pago yo" descuenta las mismas comisiones del
 * precio: la nuestra redondeada hacia abajo, como Comision::sobre, y la de
 * Mercado Pago con su IVA (aproximada: depende del plazo de cada cuenta).
 */
export function cuentasDeEjemplo(tasas, precio = PRECIO_EJEMPLO) {
  const comprador = calcularCargos(precio, tasas);
  const factorIva = (100 + Number(tasas.mp_iva)) / 100;
  const deRezonar = Math.floor(precio * tasas.plataforma_porcentaje) / 100;
  const deMercadoPago = Math.round(
    (precio * tasas.mp_porcentaje + Number(tasas.mp_fijo) * 100) * factorIva
  ) / 100;

  return {
    comprador,
    organizador: {
      precio,
      plataforma: deRezonar,
      mercadopago: deMercadoPago,
      teQueda: Math.round((precio - deRezonar - deMercadoPago) * 100) / 100,
    },
  };
}

function Renglon({ concepto, monto, signo = '', fuerte = false, aproximado = false }) {
  return (
    <div className={`flex justify-between gap-4 ${fuerte ? 'pt-3 mt-1 border-t border-borde font-bold' : ''}`}>
      <dt className={fuerte ? 'text-tinta' : 'text-tinta-media'}>{concepto}</dt>
      <dd className={`tabular-nums whitespace-nowrap ${fuerte ? 'text-verde-oscuro' : 'text-tinta'}`}>
        {signo}{aproximado ? '≈ ' : ''}{plata(monto)}
      </dd>
    </div>
  );
}

/** Las dos respuestas a "¿quién paga la comisión?", lado a lado. */
function QuienPagaLaComision({ costos }) {
  const tasas = costos.cargos;
  const { comprador, organizador } = cuentasDeEjemplo(tasas);
  const mp = `${formatearPorcentaje(tasas.mp_porcentaje)}%${Number(tasas.mp_iva) > 0 ? ' + IVA' : ''}`;
  const rezonar = `${formatearPorcentaje(tasas.plataforma_porcentaje)}%`;
  const dias = costos.mercadopago ? costos.mercadopago.dias : null;

  return (
    <div className="mt-12">
      <h3 className="text-xl sm:text-2xl font-bold tracking-tight text-tinta">
        Vos elegís quién paga la comisión
      </h3>
      <p className="mt-1.5 text-tinta-media max-w-2xl leading-relaxed">
        Se elige en cada evento, cuando configurás las entradas, y lo podés cambiar cuando
        quieras. Así queda una entrada de {pesos(PRECIO_EJEMPLO)}:
      </p>

      <div className="mt-6 grid md:grid-cols-2 gap-4">
        <Tarjeta className="p-5 sm:p-6">
          <Rotulo>Opción 1</Rotulo>
          <p className="mt-2 text-lg font-bold text-tinta leading-snug">La comisión la pago yo (organizador)</p>
          <p className="mt-1 text-sm text-tinta-media leading-relaxed">
            Quien compra paga el precio que pusiste, y las comisiones se descuentan de lo que cobrás.
          </p>
          <dl className="mt-4 space-y-2 text-sm">
            <Renglon concepto="Quien compra paga" monto={organizador.precio} />
            <Renglon concepto={`${CARGO_PLATAFORMA} (${rezonar})`} monto={organizador.plataforma} signo="−" />
            <Renglon concepto={`${CARGO_MERCADOPAGO} (${mp})`} monto={organizador.mercadopago} signo="−" aproximado />
            <Renglon concepto="Te queda" monto={organizador.teQueda} fuerte aproximado />
          </dl>
        </Tarjeta>

        <Tarjeta destacada className="p-5 sm:p-6">
          <Rotulo className="!text-verde-oscuro">Opción 2</Rotulo>
          <p className="mt-2 text-lg font-bold text-tinta leading-snug">La comisión la paga quien compra</p>
          <p className="mt-1 text-sm text-tinta-media leading-relaxed">
            Se suma al precio, a la vista y con su desglose antes de pagar.
          </p>
          <dl className="mt-4 space-y-2 text-sm">
            <Renglon concepto="Entrada" monto={comprador.precio} />
            {comprador.plataforma > 0 && (
              <Renglon concepto={`${CARGO_PLATAFORMA} (${rezonar})`} monto={comprador.plataforma} signo="+" />
            )}
            <Renglon concepto={`${CARGO_MERCADOPAGO} (${mp})`} monto={comprador.mercadopago} signo="+" aproximado />
            <Renglon concepto="Quien compra paga" monto={comprador.total} fuerte aproximado />
          </dl>
          <p className="mt-4 flex items-start gap-2 text-sm font-semibold text-verde-oscuro">
            <Check className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span>Vos recibís el precio completo: {plata(comprador.precio)}.</span>
          </p>
        </Tarjeta>
      </div>

      <p className="mt-4 text-sm text-tinta-suave leading-relaxed max-w-3xl">
        La comisión de Mercado Pago depende del plazo de acreditación que tengas elegido en tu
        cuenta{dias !== null && dias > 0 && <> (el ejemplo usa la de {dias} {dias === 1 ? 'día' : 'días'})</>},
        por eso esos montos son aproximados. Podés verla en{' '}
        <a
          href="https://www.mercadopago.com.ar/costs-section/release-options"
          target="_blank"
          rel="noopener noreferrer"
          className="text-verde-oscuro font-semibold hover:underline underline-offset-4"
        >
          los costos de Mercado Pago
        </a>
        . La elección es para las entradas; en los productos, la comisión la paga quien vende.
      </p>
    </div>
  );
}

function Precios() {
  const { token, apiUrl } = useContext(AuthContext);
  const destinoCrear = token ? '/my-pages' : '/register';
  const costos = useCostos(apiUrl);
  const plazo = plazoDeCobro(costos);
  const eligeQuienPaga = Boolean(costos && costos.cargos);

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet>
        <title>Precios — Rezonar</title>
        <meta name="description" content="Tu página, tus fechas, los avisos a tus seguidores y las herramientas para vender son gratis. Si vendés entradas o productos hay una comisión por venta —en las entradas elegís si la pagás vos o quien compra— y la plata te llega a los pocos días de cada compra." />
        <meta property="og:title" content="Precios — Rezonar" />
        <meta property="og:description" content="Todo gratis salvo la comisión por venta, que en las entradas puede pagar quien compra. Y la plata te llega a los pocos días de cada compra, no después del evento." />
        <meta property="og:type" content="website" />
        <link rel="canonical" href={window.location.href} />
      </Helmet>

      <Navigation />

      <main className="flex-1">

        {/* ------------------------------------------------------------ hero */}

        <section className="border-b border-borde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14 sm:py-20 text-center">
            <Rotulo>Precios</Rotulo>
            <h1 className="mt-4 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.05] text-balance">
              Todo lo que necesitás, <span className="text-verde-oscuro">$0</span>.
            </h1>
            <p className="mt-5 text-lg text-tinta-media max-w-2xl mx-auto leading-relaxed">
              Sin abono mensual y sin costo por publicar. Sólo hay una comisión cuando vendés
              una entrada o un producto pago{eligeQuienPaga && ' (en las entradas, si querés, la paga quien compra)'},
              y esa plata te llega {plazo}.
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <Boton a={destinoCrear} tamano="lg">
                Armar mi página gratis <ArrowRight className="w-4 h-4" />
              </Boton>
            </div>
          </div>
        </section>

        {/* ---------------------------------------------------- qué incluye */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="max-w-6xl mx-auto px-5 sm:px-6 py-14">
            <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
              <TituloSeccion
                titulo="Qué incluye"
                bajada="Todo esto es gratis, para siempre: tu página, tus fechas y las herramientas para vender."
                className="!mb-0"
              />
              <p className="text-4xl font-bold text-verde-oscuro tabular-nums">$0</p>
            </div>

            <div className="space-y-10">
              {INCLUYE.map(({ tema, items }) => (
                <div key={tema}>
                  <h3 className="mb-4 text-xl font-bold text-tinta">{tema}</h3>
                  <GrillaGratis items={items} />
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* ------------------------------------------------ venta de entradas */}

        <section className="border-b border-borde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Venta de entradas y productos"
              bajada="Lo único que tiene costo, y sólo cuando vendés."
            />

            {costos ? (
              <>
                <div className={eligeQuienPaga ? '' : 'grid md:grid-cols-[1fr_auto] gap-8 items-start'}>
                  <div className="space-y-6">
                    <div>
                      <p className="text-5xl font-bold tracking-tight text-tinta tabular-nums">
                        {formatearPorcentaje(costos.comision)}%
                      </p>
                      <p className="mt-1 text-tinta-media">por entrada o producto vendido, como comisión de Rezonar.</p>
                    </div>

                    <ul className="space-y-3 text-tinta-media leading-relaxed">
                      {!eligeQuienPaga && (
                        <li className="flex gap-2">
                          <Check className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                          <span>El comprador paga el precio que pusiste, una sola vez, y la comisión se descuenta de lo que cobrás.</span>
                        </li>
                      )}
                      {costos.mercadopago && (
                        <li className="flex gap-2">
                          <Check className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                          <span>
                            Aparte, Mercado Pago cobra {formatearPorcentaje(costos.mercadopago.porcentaje)}%
                            {eligeQuienPaga && Number(costos.cargos.mp_iva) > 0 && ' + IVA'} por
                            procesar el pago. Eso es de Mercado Pago, no nuestro; está en{' '}
                            <a
                              href="https://www.mercadopago.com.ar/costs-section/release-options"
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-verde-oscuro font-semibold hover:underline underline-offset-4"
                            >
                              sus costos
                            </a>
                            .
                          </span>
                        </li>
                      )}
                      <li className="flex gap-2">
                        <Check className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                        <span>
                          Los productos digitales (un libro, un disco, un curso) y los físicos (una
                          remera, un vinilo) pagan la misma comisión que una entrada. El archivo de un
                          digital puede pesar hasta 50 MB y se descarga desde un link privado que le
                          llega a cada comprador.
                        </span>
                      </li>
                      <li className="flex gap-2">
                        <Check className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                        <span>Las reservas sin costo y los productos que publicás gratis no pagan comisión.</span>
                      </li>
                    </ul>
                  </div>

                  {!eligeQuienPaga && <CuentaDeEjemplo costos={costos} className="md:w-72" />}
                </div>

                {eligeQuienPaga && <QuienPagaLaComision costos={costos} />}
              </>
            ) : (
              <p className="text-tinta-media leading-relaxed max-w-2xl">
                Hay una comisión por cada entrada o producto pago, y Mercado Pago cobra la suya
                por procesar el pago. Ves el porcentaje exacto antes de activar la venta. Las
                reservas sin costo y los productos gratis no pagan nada.
              </p>
            )}
          </div>
        </section>

        {/* ------------------------------------------------ cuándo cobrás */}

        <section className="border-b border-borde bg-verde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-16">
            <Rotulo className="!text-verde-tinta/70">Cuándo cobrás</Rotulo>
            <h2 className="mt-4 text-3xl sm:text-4xl font-bold tracking-tight text-verde-tinta text-balance leading-tight">
              La plata te llega {plazo}. No después del evento.
            </h2>
            <p className="mt-4 text-verde-tinta/85 leading-relaxed max-w-2xl">
              Cada venta entra directo a tu cuenta de Mercado Pago: no pasa por nosotros
              ni espera a que termine el show. Con lo que vendés en la preventa ya podés
              pagar la sala, el sonido o la difusión.
            </p>

            <div className="mt-10 grid sm:grid-cols-2 gap-4">
              <div className="bg-white rounded-2xl p-6 border border-verde-tinta/10">
                <div className="flex items-center gap-2">
                  <Check className="w-5 h-5 text-verde-oscuro" />
                  <p className="font-bold text-tinta">Con Rezonar</p>
                </div>
                <p className="mt-2 text-tinta-media leading-relaxed">
                  Cobrás {plazo}, directo en tu cuenta.
                </p>
              </div>
              <div className="bg-white/60 rounded-2xl p-6 border border-verde-tinta/10">
                <div className="flex items-center gap-2">
                  <Clock className="w-5 h-5 text-tinta-suave" />
                  <p className="font-bold text-tinta-media">En otras ticketeras</p>
                </div>
                <p className="mt-2 text-tinta-media leading-relaxed">
                  La plata queda retenida y te la liquidan recién después de que termina el evento.
                </p>
              </div>
            </div>
          </div>
        </section>

        {/* ------------------------------------------------------ cierre */}

        <section>
          <div className="max-w-3xl mx-auto px-5 sm:px-6 py-20 text-center">
            <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-balance leading-tight">
              Armá tu página. Cuesta $0.
            </h2>
            <p className="mt-4 text-tinta-media text-lg">
              Y cuando quieras vender entradas o productos, se activa desde la misma página.
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <Boton a={destinoCrear} tamano="lg">
                Armar mi página gratis <ArrowRight className="w-4 h-4" />
              </Boton>
              <Boton a="/artistas" variante="secundario" tamano="lg">
                Qué más tiene
              </Boton>
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

export default Precios;
