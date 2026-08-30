import React, { useState } from 'react';
import { trackEvent } from '../../utils/analytics';
import FollowButton from '../FollowButton';
import EventCollaborators from '../EventCollaborators';
import FollowersPopup from '../FollowersPopup';
import RedesSociales from '../RedesSociales';
import BotonEntradas, { vendeEntradas } from '../BotonEntradas';
import PrecioEvento from '../PrecioEvento';
import RezonarBadge from '../RezonarBadge';
import { MiniaturaGaleria, VisorGaleria } from '../MediaGaleria';
import { superficie, borde } from '../../utils/colores';
import { CLASES_ALREDEDOR, CLASES_CAJA, estiloDeAlrededor, estiloDeCaja } from '../../utils/plantillas';
import { ExternalLink } from 'lucide-react';

function MinimalTemplate({ page }) {
  const [modalImage, setModalImage] = useState(null);
  const [modalEvent, setModalEvent] = useState(null);
  const backgroundColor = page.background_color || '#ffffff';
  const textColor = page.text_color || '#000000';
  const primaryColor = page.primary_color || '#3b82f6';
  const backgroundImage = page.background_image;

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

  const estiloAlrededor = estiloDeAlrededor({ backgroundColor, textColor });
  const estiloCaja = estiloDeCaja({ backgroundColor, backgroundImage, textColor });

  return (
    <div className={CLASES_ALREDEDOR} style={estiloAlrededor}>
      <RezonarBadge />
      <div className={`${CLASES_CAJA} px-6 py-16 space-y-16`} style={estiloCaja}>
        {page.profile_image && (
          <div className="flex justify-center">
            <img
              src={page.profile_image}
              alt={page.title}
              className="w-32 h-32 rounded-full object-cover"
            />
          </div>
        )}

        <div className="text-center space-y-4">
          <h1 className="text-4xl font-bold">{page.title}</h1>
          {page.description && (
            <p className="text-lg opacity-70">{page.description}</p>
          )}
          <div className="flex flex-col items-center gap-3 mt-4">
            <RedesSociales socials={page.socials} className="mb-1" />
            <FollowersPopup pageId={page.id} followerCount={page.follower_count || 0} />
            <FollowButton pageId={page.id} />
          </div>
        </div>

        {page.groups?.map((group) => (
          <div key={group.id} className="space-y-6">
            <h2 className="text-2xl font-bold">{group.title}</h2>

            {group.type === 'galeria' ? (
              <div className="grid grid-cols-2 gap-4">
                {group.links?.map((link) => (
                  <div
                    key={link.id}
                    onClick={() => {
                      setModalImage(link);
                      trackEvent.event('view_image_modal', { image_title: link.text });
                    }}
                    className="cursor-pointer hover:opacity-80 transition"
                  >
                    <MiniaturaGaleria link={link} className="w-full h-48 object-cover" />
                  </div>
                ))}
              </div>
            ) : group.type === 'eventos' ? (
              <div className="grid grid-cols-1 gap-6">
                {[...(group.links || []), ...(group.collaborated_events || [])].sort((a, b) => {
                  const dateA = new Date(a.event_date + ' ' + (a.event_time || '00:00'));
                  const dateB = new Date(b.event_date + ' ' + (b.event_time || '00:00'));
                  return dateA - dateB;
                }).map((link) => (
                  <div
                    key={(link.is_collaborated ? 'c-' : '') + link.id}
                    onClick={() => {
                      setModalEvent(link);
                      trackEvent.event('view_event_modal', { event_title: link.text });
                    }}
                    className="border pb-6 p-4 rounded cursor-pointer hover:shadow-lg transition relative"
                    style={{ borderColor: textColor + '20' }}
                  >
                    <a
                      href={`/evento/${link.id}`}
                      onClick={e => e.stopPropagation()}
                      className="absolute top-2 right-2 p-1.5 rounded-full bg-black bg-opacity-40 text-white hover:bg-opacity-70 transition z-10"
                      title="Ver enlace directo"
                    >
                      <ExternalLink className="w-3.5 h-3.5" />
                    </a>
                    <img
                      src={link.image_url ?? 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
                      alt={link.text}
                      className="w-full h-48 object-cover mb-4 rounded"
                    />
                    <h3 className="font-bold text-xl">{link.text}</h3>
                    {(link.event_date || link.event_time) && (
                      <p className="opacity-60 mt-1">
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
                    <EventCollaborators event={link} currentPageId={page.id} color={page.primary_color || '#3b82f6'} />
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
                    onClick={() => trackEvent.clickLink(link.url, link.text)}
                    className="block border hover:opacity-70 transition overflow-hidden"
                    style={{ borderColor: textColor }}
                  >
                    {link.image_url && (
                      <img
                        src={link.image_url}
                        alt={link.text}
                        className="w-full h-48 object-cover"
                      />
                    )}
                    <div className="px-6 py-4">
                      <div className="font-medium">{link.text}</div>
                      {link.description && (
                        <div className="text-sm opacity-60 mt-1">{link.description}</div>
                      )}
                    </div>
                  </a>
                ))}
              </div>
            )}
          </div>
        ))}
      </div>

      <footer className="mt-24 pt-12 border-t" style={{ borderColor: textColor + '20' }}>
        <div className="text-center space-y-4">
          <a href="/" className="inline-block opacity-50 hover:opacity-100 transition">
            <img src="/logo.png" alt="Rezonar" className="h-8 mx-auto" />
          </a>
          <p className="text-sm opacity-50">Creado con Rezonar</p>
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
              <VisorGaleria link={modalImage} />
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
                      className="inline-block px-6 py-3 bg-white text-black font-bold rounded transition hover:opacity-90"
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
                  className="w-full h-64 object-cover rounded-lg mb-6"
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
                        className="hover:underline text-blue-600"
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

              <EventCollaborators event={modalEvent} currentPageId={page.id} color="#000000" />

              <PrecioEvento evento={modalEvent} className="mt-4" />

              <BotonEntradas evento={modalEvent} color={primaryColor} />

              {modalEvent.url && !vendeEntradas(modalEvent) && (
                <a
                  href={modalEvent.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-block mt-4 px-6 py-3 border-2 border-black font-bold hover:bg-black hover:text-white transition"
                  onClick={(e) => e.stopPropagation()}
                >
                  {modalEvent.url_text || 'Más información →'}
                </a>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default MinimalTemplate;
