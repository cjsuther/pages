import React, { useContext } from 'react';
import { Helmet } from 'react-helmet-async';
import {
  CalendarDays, Bell, Palette, QrCode, Users, Link2, MapPin, Globe, Sparkles,
  ScanLine, BarChart3, Mail, Armchair, FileSpreadsheet, Gift,
  ArrowRight, Check, Clock,
} from 'lucide-react';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { PieDePagina } from '../components/Marco';
import { Boton, Rotulo, Tarjeta, TituloSeccion } from '../components/ui';
import { formatearPorcentaje } from '../utils/comisiones';
import { useCostos, CuentaDeEjemplo } from '../components/CostosDeVenta';

/**
 * Lo que cuesta Rezonar, en una sola página.
 *
 * El argumento tiene dos partes: casi todo es gratis, y cuando vendés
 * entradas la plata te llega a los pocos días de cada compra, no cuando
 * termina el evento. Los números (nuestra comisión, la de Mercado Pago y los
 * días) salen del servidor; si no llegan, la página habla sin números en vez
 * de inventarlos.
 */

const GRATIS_PAGINA = [
  { icono: Globe, titulo: 'Tu página', detalle: 'rezon.ar/tunombre, o tu propio dominio.' },
  { icono: CalendarDays, titulo: 'Fechas sin límite', detalle: 'Las que quieras, y las viejas se van solas.' },
  { icono: Bell, titulo: 'Avisos a tus seguidores', detalle: 'Cada fecha nueva les llega al teléfono.' },
  { icono: MapPin, titulo: 'Ubicación con mapa', detalle: 'Dirección y cómo llegar en cada fecha.' },
  { icono: Link2, titulo: 'Links y redes', detalle: 'Agrupados y en el orden que quieras.' },
  { icono: Palette, titulo: 'Plantillas y colores', detalle: 'Tu identidad, sin nuestro logo arriba.' },
  { icono: QrCode, titulo: 'QR para el afiche', detalle: 'Siempre lleva a tus fechas de ahora.' },
  { icono: Sparkles, titulo: 'Carga con ChatGPT o Claude', detalle: 'Dictás la fecha y queda publicada.' },
  { icono: Users, titulo: 'Equipo y fechas en conjunto', detalle: 'Sumás gente y compartís shows con otras páginas.' },
];

const GRATIS_ENTRADAS = [
  { icono: Gift, titulo: 'Reservas sin costo', detalle: 'Para eventos gratuitos o con lista: no pagan nada.' },
  { icono: Mail, titulo: 'Entrada por mail con QR', detalle: 'Le llega a cada comprador apenas paga.' },
  { icono: ScanLine, titulo: 'Control en la puerta', detalle: 'Escaneás desde el celular, con varias puertas a la vez.' },
  { icono: Armchair, titulo: 'Plano de ubicaciones', detalle: 'Butacas y mesas numeradas, si tu sala las tiene.' },
  { icono: BarChart3, titulo: 'Venta en vivo para compartir', detalle: 'Un link para que tu socio vea cómo va la venta.' },
  { icono: FileSpreadsheet, titulo: 'Tus clientes en Excel', detalle: 'Quedan después del evento y los podés exportar.' },
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

function Precios() {
  const { token, apiUrl } = useContext(AuthContext);
  const destinoCrear = token ? '/my-pages' : '/register';
  const costos = useCostos(apiUrl);
  const plazo = plazoDeCobro(costos);

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet>
        <title>Precios — Rezonar</title>
        <meta name="description" content="Tu página, tus fechas y los avisos a tus seguidores son gratis. Si vendés entradas pagás una comisión por entrada y la plata te llega a los pocos días de cada compra, no después del evento." />
        <meta property="og:title" content="Precios — Rezonar" />
        <meta property="og:description" content="Todo gratis salvo la venta de entradas. Y la plata te llega a los pocos días de cada compra, no después del evento." />
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
              Sin abono mensual y sin costo por publicar. Sólo se cobra una comisión
              cuando vendés una entrada paga, y esa plata te llega {plazo}.
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <Boton a={destinoCrear} tamano="lg">
                Armar mi página gratis <ArrowRight className="w-4 h-4" />
              </Boton>
            </div>
          </div>
        </section>

        {/* ---------------------------------------------------------- gratis */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="max-w-6xl mx-auto px-5 sm:px-6 py-14">
            <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
              <TituloSeccion
                titulo="Gratis, para siempre"
                bajada="Tu página y todo lo que la rodea."
              />
              <p className="text-4xl font-bold text-verde-oscuro tabular-nums">$0</p>
            </div>
            <GrillaGratis items={GRATIS_PAGINA} />

            <h3 className="mt-12 mb-4 text-xl font-bold text-tinta">
              Y para vender entradas, también sin costo:
            </h3>
            <GrillaGratis items={GRATIS_ENTRADAS} />
          </div>
        </section>

        {/* ------------------------------------------------ venta de entradas */}

        <section className="border-b border-borde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Venta de entradas"
              bajada="Lo único que tiene costo, y sólo cuando vendés."
            />

            {costos ? (
              <div className="grid md:grid-cols-[1fr_auto] gap-8 items-start">
                <div className="space-y-6">
                  <div>
                    <p className="text-5xl font-bold tracking-tight text-tinta tabular-nums">
                      {formatearPorcentaje(costos.comision)}%
                    </p>
                    <p className="mt-1 text-tinta-media">por entrada vendida, como comisión de Rezonar.</p>
                  </div>

                  <ul className="space-y-3 text-tinta-media leading-relaxed">
                    <li className="flex gap-2">
                      <Check className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                      <span>El comprador paga el precio que pusiste, una sola vez. No le sumamos cargo por servicio.</span>
                    </li>
                    {costos.mercadopago && (
                      <li className="flex gap-2">
                        <Check className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                        <span>
                          Aparte, Mercado Pago cobra {formatearPorcentaje(costos.mercadopago.porcentaje)}% por
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
                      <span>Las reservas sin costo no pagan comisión.</span>
                    </li>
                  </ul>
                </div>

                <CuentaDeEjemplo costos={costos} className="md:w-72" />
              </div>
            ) : (
              <p className="text-tinta-media leading-relaxed max-w-2xl">
                Se descuenta una comisión de cada entrada paga, y Mercado Pago cobra la
                suya por procesar el pago. Ves el porcentaje exacto antes de activar la
                venta. Las reservas sin costo no pagan nada.
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
              Y cuando quieras vender entradas, se activa desde la misma página.
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
