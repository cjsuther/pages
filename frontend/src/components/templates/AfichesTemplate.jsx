import React, { useState } from 'react';
import FollowButton from '../FollowButton';
import EventCollaborators from '../EventCollaborators';
import FollowersPopup from '../FollowersPopup';
import RedesSociales from '../RedesSociales';
import BotonEntradas, { vendeEntradas } from '../BotonEntradas';
import PrecioEvento from '../PrecioEvento';
import RezonarBadge from '../RezonarBadge';
import { MiniaturaGaleria, VisorGaleria } from '../MediaGaleria';
import { superficie, borde, textoSobre } from '../../utils/colores';
import { CLASES_ALREDEDOR, CLASES_CAJA, estiloDeAlrededor, estiloDeCaja } from '../../utils/plantillas';
import { ExternalLink } from 'lucide-react';

/**
 * Cada link es un afiche: la imagen ocupa la ficha entera, enmarcada en el
 * color de acento, con el título abajo.
 *
 * Está pensada para lo que esta plataforma publica más —eventos con su placa
 * de difusión—, donde el afiche ya dice todo y el texto sobra. Las otras
 * plantillas tratan a la imagen como un adorno del texto; acá es al revés.
 */
function AfichesTemplate({ page }) {
  const [modalImage, setModalImage] = useState(null);
  const [modalEvent, setModalEvent] = useState(null);
  const backgroundColor = page.background_color || '#f3f4f6';
  const textColor = page.text_color || '#111827';
  const primaryColor = page.primary_color || '#3b82f6';
  const backgroundImage = page.background_image;

  // La tarjeta acompaña al fondo elegido en vez de imponer un blanco fijo, y
  // el texto es siempre el color elegido: como la tarjeta ya no trae color
  // propio, no hay forma de que la tipografía quede invisible encima.
  const fondoTarjeta = superficie(backgroundColor, textColor);
  const bordeTarjeta = borde(textColor);

  const tarjetaStyle = {
    color: textColor,
    ...(fondoTarjeta && { backgroundColor: fondoTarjeta }),
    ...(bordeTarjeta && { border: `1px solid ${bordeTarjeta}` }),
  };

  // El detalle del evento sale de la misma paleta que la página. Estaba fijo
  // en blanco con texto negro: sobre una página oscura era un recuadro ajeno,
  // y el botón de compra —que se pinta con un color de la paleta— podía
  // quedar del mismo color que ese blanco y desaparecer.
  const fondoModal = superficie(backgroundColor, textColor);
  const bordeModal = borde(textColor);

  const modalStyle = {
    color: textColor,
    backgroundColor: fondoModal || '#ffffff',
    ...(fondoModal ? {} : { color: '#000' }),
    ...(bordeModal && { border: `1px solid ${bordeModal}` }),
  };

  // El marco del afiche es el color de acento, y el título va encima: se elige
  // el que se lea sobre ese color en vez de asumir que la tipografía de la
  // página contrasta con él.
  const estiloMarco = { backgroundColor: primaryColor, color: textoSobre(primaryColor, textColor) };

  const estiloAlrededor = estiloDeAlrededor({ backgroundColor, textColor });
  const estiloCaja = estiloDeCaja({ backgroundColor, backgroundImage, textColor });

  return (
    <div className={CLASES_ALREDEDOR} style={estiloAlrededor}>
      <RezonarBadge />
      <div className={`${CLASES_CAJA} px-6 py-16`} style={estiloCaja}>
        <div className="text-center mb-16">
          {page.profile_image && (
            <img
              src={page.profile_image}
              alt={page.title}
              className="w-40 h-40 rounded-full object-cover mx-auto mb-6 shadow-lg"
            />
          )}
          <h1 className="text-5xl font-bold mb-4">{page.title}</h1>
          {page.description && (
            <p className="text-xl opacity-70 max-w-2xl mx-auto">{page.description}</p>
          )}
          <div className="flex flex-col items-center gap-3 mt-6">
            <RedesSociales socials={page.socials} className="mb-1" />
            <FollowersPopup pageId={page.id} followerCount={page.follower_count || 0} />
            <FollowButton pageId={page.id} />
          </div>
        </div>

        <div className="space-y-12">
          {page.groups?.map((group) => (
            <div key={group.id}>
              <h2 className="text-3xl font-bold mb-8 text-center">{group.title}</h2>

              {group.type === 'galeria' ? (
                <div className="grid grid-cols-2 gap-4">
                  {group.links?.map((link) => (
                    <div
                      key={link.id}
                      onClick={() => setModalImage(link)}
                      className="rounded-2xl overflow-hidden shadow-lg cursor-pointer transition hover:-translate-y-0.5 p-2"
                      style={estiloMarco}
                    >
                      <MiniaturaGaleria
                        link={link}
                        className="w-full aspect-[1080/1350] object-cover rounded-xl"
                      />
                      {link.text && (
                        <p className="font-black uppercase tracking-wide text-center text-xs px-1 pt-2 pb-1">
                          {link.text}
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              ) : group.type === 'eventos' ? (
                // Una sola columna: el afiche grande es el punto de esta
                // plantilla. De a dos se leería como la de tarjetas.
                <div className="grid grid-cols-1 gap-4">
                  {[...(group.links || []), ...(group.collaborated_events || [])].sort((a, b) => {
                    const dateA = new Date(a.event_date + ' ' + (a.event_time || '00:00'));
                    const dateB = new Date(b.event_date + ' ' + (b.event_time || '00:00'));
                    return dateA - dateB;
                  }).map((link) => (
                    // En un teléfono la tarjeta mide unos 156px: con el relleno
                    // y el cuerpo de texto de siempre, el título se partía en
                    // cuatro líneas.
                    <div
                      key={(link.is_collaborated ? 'c-' : '') + link.id}
                      onClick={() => setModalEvent(link)}
                      className="rounded-2xl p-2 shadow-lg transition hover:-translate-y-0.5 cursor-pointer relative"
                      style={estiloMarco}
                    >
                      <a
                        href={`/evento/${link.id}`}
                        onClick={e => e.stopPropagation()}
                        className="absolute top-3 right-3 p-1.5 rounded-full bg-black bg-opacity-30 text-white hover:bg-opacity-50 transition z-10"
                        title="Ver enlace directo"
                      >
                        <ExternalLink className="w-3.5 h-3.5" />
                      </a>
                      <img
                        src={link.image_url ?? 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
                        alt={link.text}
                        className="w-full aspect-[1080/1350] object-cover rounded-xl"
                      />
                      <h3 className="font-black uppercase tracking-wide text-center text-sm px-1 pt-2">{link.text}</h3>
                      {(link.event_date || link.event_time) && (
                        <p className="opacity-80 text-center text-xs px-1">
                          🗓️ {link.event_date && new Date(link.event_date + ' ' + link.event_time).toLocaleDateString('es-AR', {
                              timeZone: 'America/Argentina/Buenos_Aires',
                              day: '2-digit',
                              month: '2-digit',
                              year: 'numeric',
                              hour: '2-digit',
                              minute: '2-digit',
                              hour12: false,
                            })}
                        </p>
                      )}
                      <EventCollaborators event={link} currentPageId={page.id} color={primaryColor} />
                    </div>
                  ))}
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-4">
                  {group.links?.map((link) => (
                    <a
                      key={link.id}
                      href={link.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="block rounded-2xl p-2 shadow-lg transition hover:-translate-y-0.5"
                      style={estiloMarco}
                    >
                      {/* Sin imagen la ficha es sólo el título: sigue leyéndose
                          como un botón y no queda un hueco. */}
                      {link.image_url && (
                        <img
                          src={link.image_url}
                          alt={link.text}
                          className="w-full aspect-[1080/1350] object-cover rounded-xl"
                        />
                      )}
                      <div className={`text-center px-2 ${link.image_url ? 'pt-3 pb-1' : 'py-3'}`}>
                        <div className="font-black uppercase tracking-wide">{link.text}</div>
                        {link.description && (
                          <div className="text-sm opacity-80 mt-1 normal-case font-normal">{link.description}</div>
                        )}
                      </div>
                    </a>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      </div>

      <footer className="mt-24 pt-12 border-t border-gray-300 text-center">
        <div className="space-y-4">
          <a href="/" className="inline-block opacity-50 hover:opacity-100 transition">
            <img src="/logo.png" alt="Rezonar" className="h-8 mx-auto" />
          </a>
          <p className="text-sm opacity-50" style={{ color: textColor }}>Creado con Rezonar</p>
        </div>
      </footer>

      {modalImage && (
        <div
          className="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4"
          onClick={() => setModalImage(null)}
        >
          <div className="max-w-4xl w-full" onClick={(e) => e.stopPropagation()}>
            <div className="relative">
              <button
                onClick={() => setModalImage(null)}
                className="absolute -top-12 right-0 text-white text-4xl font-bold hover:opacity-70"
              >
                ×
              </button>
              <VisorGaleria link={modalImage} className="w-full max-h-[80vh] object-contain rounded-lg" />
              {(modalImage.text || modalImage.url) && (
                <div className="mt-4 text-center space-y-3">
                  {modalImage.text && (
                    <h3 className="text-white text-2xl font-bold">{modalImage.text}</h3>
                  )}
                  {modalImage.url && (
                    <a
                      href={modalImage.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-block px-6 py-3 rounded-lg font-bold transition text-white hover:opacity-90"
                      style={{ backgroundColor: primaryColor }}
                      onClick={(e) => e.stopPropagation()}
                    >
                      Ver más →
                    </a>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {modalEvent && (
        <div
          className="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4"
          onClick={() => setModalEvent(null)}
        >
          <div className="max-w-2xl w-full max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
            <div className="rounded-lg p-8" style={modalStyle}>
              <button
                onClick={() => setModalEvent(null)}
                className="float-right text-gray-600 text-3xl font-bold hover:text-gray-900"
              >
                ×
              </button>

              {modalEvent.image_url && (
                <img
                  src={modalEvent.image_url}
                  alt={modalEvent.text}
                  className="w-full aspect-[1080/1350] object-cover rounded-lg mb-6"
                />
              )}

              <h2 className="text-3xl font-bold mb-4">{modalEvent.text}</h2>

              {(modalEvent.event_date || modalEvent.event_time) && (
                <div className="mb-4 text-lg">
                  <span className="font-bold">🗓️ Fecha y hora:</span>
                  <p className="mt-1">
                    {modalEvent.event_date && new Date(modalEvent.event_date + ' ' + modalEvent.event_time).toLocaleDateString('es-AR', {
                      timeZone: 'America/Argentina/Buenos_Aires',
                      day: '2-digit',
                      month: '2-digit',
                      year: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                      hour12: false,
                    })}
                  </p>
                </div>
              )}

              {modalEvent.description && (
                <div className="mb-4">
                  <p className="text-lg leading-relaxed">{modalEvent.description}</p>
                </div>
              )}

              {modalEvent.event_address && (
                <div className="mb-4">
                  <span className="font-bold">📍 Ubicación:</span>
                  <p className="mt-1">
                    {modalEvent.event_maps_url ? (
                      <a
                        href={modalEvent.event_maps_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="hover:underline"
                        style={{ color: primaryColor }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        {modalEvent.event_address}
                      </a>
                    ) : (
                      modalEvent.event_address
                    )}
                  </p>
                </div>
              )}

              <PrecioEvento evento={modalEvent} className="mt-4" />

              <BotonEntradas evento={modalEvent} color={primaryColor} />

              {modalEvent.url && !vendeEntradas(modalEvent) && (
                <a
                  href={modalEvent.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-block mt-4 px-6 py-3 rounded-lg font-bold hover:opacity-90 transition text-white"
                  style={{ backgroundColor: primaryColor }}
                  onClick={(e) => e.stopPropagation()}
                >
                  {modalEvent.url_text || 'Más información →'}
                </a>
              )}
              <EventCollaborators event={modalEvent} currentPageId={page.id} color={primaryColor} />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default AfichesTemplate;
