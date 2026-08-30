import React, { useState } from 'react';
import FollowButton from '../FollowButton';
import EventCollaborators from '../EventCollaborators';
import FollowersPopup from '../FollowersPopup';
import RedesSociales from '../RedesSociales';
import BotonEntradas, { vendeEntradas } from '../BotonEntradas';
import PrecioEvento from '../PrecioEvento';
import RezonarBadge from '../RezonarBadge';
import { MiniaturaGaleria, VisorGaleria } from '../MediaGaleria';
import { paleta } from '../../utils/colores';
import { CLASES_ALREDEDOR, CLASES_CAJA, estiloDeAlrededor, estiloDeCaja } from '../../utils/plantillas';
import { ExternalLink } from 'lucide-react';

function CondensedTemplate({ page }) {
  const [modalImage, setModalImage] = useState(null);
  const [modalEvent, setModalEvent] = useState(null);
  const backgroundColor = page.background_color || '#ffffff';
  const textColor = page.text_color || '#000000';
  const primaryColor = page.primary_color || '#3b82f6';
  const backgroundImage = page.background_image;

  // Los colores por rol. Las cinco plantillas leen de acá para que el mismo
  // control pinte lo mismo en todas.
  const colores = paleta(page);

  // La imagen se muestra tal cual, igual que en las otras tres. Acá iba detrás
  // de un velo del 95% que la tapaba: clavado en blanco volvía ilegible
  // cualquier página de tipografía clara, y atado a la paleta la teñía del
  // color de fondo.
  // Cada item es una píldora de ancho completo: miniatura redonda a la
  // izquierda y el título centrado respecto de toda la píldora, no del espacio
  // que sobra al lado de la miniatura. Por eso la miniatura va posicionada y
  // no en un flex: si empujara al título, cada fila quedaría centrada en un
  // lugar distinto según tenga imagen o no.
  const estiloPildora = {
    backgroundColor: colores.tarjeta || 'transparent',
    ...(colores.bordeTarjeta && { border: `1px solid ${colores.bordeTarjeta}` }),
  };

  const CLASES_PILDORA =
    'relative flex items-center min-h-[64px] rounded-full px-16 py-3 shadow-md transition hover:shadow-lg hover:-translate-y-px';

  const estiloAlrededor = estiloDeAlrededor({ backgroundColor, textColor });
  const estiloCaja = estiloDeCaja({ backgroundColor, backgroundImage, textColor });

  // El detalle del evento sale de la misma paleta que la página. Estaba fijo
  // en blanco con texto negro: sobre una página oscura era un recuadro ajeno,
  // y el botón de compra —que se pinta con un color de la paleta— podía
  // quedar del mismo color que ese blanco y desaparecer.
  const fondoModal = colores.tarjeta;
  const bordeModal = colores.bordeTarjeta;

  const modalStyle = {
    color: textColor,
    backgroundColor: fondoModal || '#ffffff',
    ...(fondoModal ? {} : { color: '#000' }),
    ...(bordeModal && { border: `1px solid ${bordeModal}` }),
  };

  return (
    <div className={CLASES_ALREDEDOR} style={estiloAlrededor}>
      <RezonarBadge />
      <div className={`${CLASES_CAJA} px-4 py-8 md:py-12`} style={estiloCaja}>
        <header className="text-center mb-8 md:mb-12">
          {page.profile_image && (
            <img
              src={page.profile_image}
              alt={page.title}
              className="w-20 h-20 md:w-24 md:h-24 rounded-full mx-auto mb-4 md:mb-6 object-cover border-4"
              style={{ borderColor: primaryColor }}
            />
          )}
          <h1 className="text-3xl md:text-4xl font-bold mb-3 md:mb-4" style={{ color: colores.titulo }}>{page.title}</h1>
          {page.description && (
            <p className="text-sm md:text-base opacity-70 max-w-xl mx-auto px-4">{page.description}</p>
          )}
          <div className="flex flex-col items-center gap-3 mt-4 md:mt-6">
            <RedesSociales socials={page.socials} className="mb-1" />
            <FollowersPopup pageId={page.id} followerCount={page.follower_count || 0} />
            <FollowButton pageId={page.id} colores={colores} />
          </div>
        </header>

        <div className="space-y-6 md:space-y-8">
          {page.groups?.map((group) => (
            <section key={group.id}>
              {group.title && (
                <div className="mb-3 md:mb-4">
                  <h2 className="text-sm md:text-base font-black uppercase tracking-widest text-center" style={{ color: colores.titulo }}>
                    {group.title}
                  </h2>
                  <span className="block h-1 w-12 mt-3 mx-auto rounded-full" style={{ backgroundColor: colores.acento }} />
                </div>
              )}

              {group.type === 'galeria' ? (
                <div className="space-y-3">
                  {group.links?.map((link) => (
                    <div
                      key={link.id}
                      onClick={() => setModalImage(link)}
                      className={`${CLASES_PILDORA} cursor-pointer`}
                      style={estiloPildora}
                    >
                      <MiniaturaGaleria
                        link={link}
                        className="absolute left-2 w-12 h-12 object-cover rounded-full"
                      />
                      <div className="flex-1 min-w-0 text-center">
                        <div className="font-semibold">{link.text || 'Imagen'}</div>
                        {link.description && (
                          <div className="text-sm opacity-70 truncate">{link.description}</div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : group.type === 'eventos' ? (
                <div className="space-y-2">
                  {[...(group.links || []), ...(group.collaborated_events || [])].sort((a, b) => {
                    const dateA = new Date(a.event_date + ' ' + (a.event_time || '00:00'));
                    const dateB = new Date(b.event_date + ' ' + (b.event_time || '00:00'));
                    return dateA - dateB;
                  }).map((link) => (
                    <div
                      key={(link.is_collaborated ? 'c-' : '') + link.id}
                      onClick={() => setModalEvent(link)}
                      className={`${CLASES_PILDORA} cursor-pointer`}
                      style={estiloPildora}
                    >
                      {link.image_url && (
                        <img
                          src={link.image_url}
                          alt={link.text}
                          className="absolute left-2 w-12 h-12 object-cover rounded-full"
                        />
                      )}
                      <div className="flex-1 min-w-0 text-center">
                        <div className="font-semibold">{link.text}</div>
                        {(link.event_date || link.event_time) && (
                          <div className="text-sm opacity-70">
                            {link.event_date && new Date(link.event_date + ' ' + link.event_time).toLocaleDateString('es-AR', {
                              timeZone: 'America/Argentina/Buenos_Aires',
                              day: '2-digit',
                              month: '2-digit',
                              year: 'numeric',
                              hour: '2-digit',
                              minute: '2-digit',
                              hour12: false,
                            })}
                          </div>
                        )}
                        <EventCollaborators event={link} currentPageId={page.id} color={primaryColor} />
                      </div>
                      <a
                        href={`/evento/${link.id}`}
                        onClick={e => e.stopPropagation()}
                        className="absolute right-4 p-1.5 opacity-40 hover:opacity-80 transition"
                        title="Ver enlace directo"
                      >
                        <ExternalLink className="w-4 h-4" />
                      </a>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="space-y-3">
                  {group.links?.map((link) => (
                    <a
                      key={link.id}
                      href={link.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className={CLASES_PILDORA}
                      style={estiloPildora}
                    >
                      {link.image_url && (
                        <img
                          src={link.image_url}
                          alt={link.text}
                          className="absolute left-2 w-12 h-12 object-cover rounded-full"
                        />
                      )}
                      <div className="flex-1 min-w-0 text-center">
                        <div className="font-semibold">{link.text}</div>
                        {link.description && (
                          <div className="text-sm opacity-70 truncate">{link.description}</div>
                        )}
                      </div>
                    </a>
                  ))}
                </div>
              )}
            </section>
          ))}
        </div>

        <footer className="mt-12 md:mt-16 pt-6 md:pt-8 border-t text-center" style={{ borderColor: textColor + '20' }}>
          <a href="/" className="inline-block opacity-50 hover:opacity-100 transition">
            <img src="/logo.png" alt="Rezonar" className="h-8 mx-auto" />
          </a>
          <p className="text-xs md:text-sm opacity-50">Creado con Rezonar</p>
        </footer>
      </div>

      {modalImage && (
        <div
          className="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4"
          onClick={() => setModalImage(null)}
        >
          <div className="max-w-4xl w-full" onClick={(e) => e.stopPropagation()}>
            <div className="relative">
              <button
                onClick={() => setModalImage(null)}
                className="absolute -top-10 md:-top-12 right-0 text-white text-3xl md:text-4xl font-bold hover:opacity-70"
              >
                ×
              </button>
              <VisorGaleria link={modalImage} className="w-full max-h-[70vh] md:max-h-[80vh] object-contain" />
              {(modalImage.text || modalImage.url) && (
                <div className="mt-4 text-center space-y-3">
                  {modalImage.text && (
                    <h3 className="text-white text-xl md:text-2xl font-bold px-4">{modalImage.text}</h3>
                  )}
                  {modalImage.url && (
                    <a
                      href={modalImage.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-block px-6 py-3 rounded-lg font-bold transition text-white"
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
          className="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4 overflow-y-auto"
          onClick={() => setModalEvent(null)}
        >
          <div className="max-w-2xl w-full my-8" onClick={(e) => e.stopPropagation()}>
            <div className="rounded-lg p-6 md:p-8" style={modalStyle}>
              <button
                onClick={() => setModalEvent(null)}
                className="float-right text-3xl font-bold opacity-60 hover:opacity-100 -mt-2 -mr-2"
              >
                ×
              </button>

              {modalEvent.image_url && (
                <img
                  src={modalEvent.image_url}
                  alt={modalEvent.text}
                  className="w-full h-48 md:h-64 object-cover rounded-lg mb-4 md:mb-6"
                />
              )}

              <h2 className="text-2xl md:text-3xl font-bold mb-4">{modalEvent.text}</h2>

              {(modalEvent.event_date || modalEvent.event_time) && (
                <div className="mb-4 text-base md:text-lg">
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
                  <p className="text-base md:text-lg leading-relaxed">{modalEvent.description}</p>
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
                        className="hover:underline break-words"
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

              <BotonEntradas evento={modalEvent} color={colores.boton} />

              {modalEvent.url && !vendeEntradas(modalEvent) && (
                <a
                  href={modalEvent.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-block mt-4 px-6 py-3 rounded-lg font-bold hover:opacity-90 transition text-white w-full md:w-auto text-center"
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

export default CondensedTemplate;
