import React, { useState } from 'react';
import FollowButton from '../FollowButton';
import EventCollaborators from '../EventCollaborators';
import FollowersPopup from '../FollowersPopup';
import RedesSociales from '../RedesSociales';
import BotonEntradas, { vendeEntradas } from '../BotonEntradas';
import PrecioEvento from '../PrecioEvento';
import RezonarBadge from '../RezonarBadge';
import { MiniaturaGaleria, VisorGaleria } from '../MediaGaleria';
import { textoSobre, paleta } from '../../utils/colores';
import { CLASES_ALREDEDOR, CLASES_CAJA, estiloDeAlrededor, estiloDeCaja } from '../../utils/plantillas';
import { ExternalLink } from 'lucide-react';

function ModernTemplate({ page }) {
  const [modalImage, setModalImage] = useState(null);
  const [modalEvent, setModalEvent] = useState(null);
  const backgroundColor = page.background_color || '#0f172a';
  const textColor = page.text_color || '#f1f5f9';
  const primaryColor = page.primary_color || '#06b6d4';
  const backgroundImage = page.background_image;

  // Los colores por rol. Las cinco plantillas leen de acá para que el mismo
  // control pinte lo mismo en todas.
  const colores = paleta(page);

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

  const estiloAlrededor = estiloDeAlrededor({ backgroundColor, textColor });
  const estiloCaja = estiloDeCaja({ backgroundColor, backgroundImage, textColor });

  return (
    <div className={CLASES_ALREDEDOR} style={estiloAlrededor}>
      <RezonarBadge />
      <div className={`${CLASES_CAJA} px-6 py-20`} style={estiloCaja}>
        <div className="space-y-16">
          {/* Antes esto era una barra lateral de 4 columnas con el contenido
              al lado. En una columna angosta no hay costado: la portada pasa a
              ser una banda ancha arriba de todo, que es lo que le da su
              carácter a esta plantilla. */}
          <div className="space-y-8">
            {page.profile_image && (
              <img
                src={page.profile_image}
                alt={page.title}
                className="w-full h-64 object-cover rounded-2xl"
              />
            )}
            <div>
              <h1 className="text-4xl font-black tracking-tight mb-4" style={{ color: colores.titulo }}>{page.title}</h1>
              {page.description && (
                <p className="text-lg opacity-70 leading-relaxed">{page.description}</p>
              )}
              <div className="flex flex-col gap-3 mt-6">
                <RedesSociales socials={page.socials} className="mb-1" />
                <FollowersPopup pageId={page.id} followerCount={page.follower_count || 0} />
                <FollowButton pageId={page.id} colores={colores} />
              </div>
            </div>
          </div>

          <div className="space-y-16">
            {page.groups?.map((group) => (
              <div key={group.id} className="space-y-8">
                <div>
                  <h2 className="text-3xl font-black tracking-tight" style={{ color: colores.titulo }}>
                    {group.title}
                  </h2>
                  <span className="block h-1 w-12 mt-3 rounded-full" style={{ backgroundColor: colores.acento }} />
                </div>

                {group.type === 'galeria' ? (
                  <div className="grid grid-cols-2 gap-4">
                    {group.links?.map((link) => (
                      <div
                        key={link.id}
                        onClick={() => setModalImage(link)}
                        className="group relative overflow-hidden rounded-xl cursor-pointer"
                      >
                        <MiniaturaGaleria
                          link={link}
                          className="w-full h-64 object-cover group-hover:scale-110 transition-transform duration-300"
                        />
                        {link.text && (
                          <div className="absolute inset-0 bg-black bg-opacity-50 flex items-end p-4 opacity-0 group-hover:opacity-100 transition">
                            <p className="text-white font-bold">{link.text}</p>
                          </div>
                        )}
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
                        onClick={() => setModalEvent(link)}
                        className="border-l-4 pl-6 py-4 cursor-pointer hover:opacity-90 transition-all rounded-r-lg relative"
                        style={{ backgroundColor: colores.tarjeta, borderColor: colores.acento }}
                      >
                        <a
                          href={`/evento/${link.id}`}
                          onClick={e => e.stopPropagation()}
                          className="absolute top-3 right-3 p-2 rounded-full shadow-md transition hover:scale-110 z-10"
                        style={{ backgroundColor: colores.acento, color: textoSobre(colores.acento, colores.texto) }}
                          title="Ver enlace directo"
                        >
                          <ExternalLink className="w-4 h-4" />
                        </a>
                        <img
                          src={link.image_url ?? 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
                          alt={link.text}
                          className="w-full h-48 object-cover rounded-lg mb-4"
                        />
                        <h3 className="font-black text-xl tracking-tight">{link.text}</h3>
                        {(link.event_date || link.event_time) && (
                          <p className="opacity-60 mt-2 font-medium">
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
                  <div className="space-y-4">
                    {group.links?.map((link) => (
                      <a
                        key={link.id}
                        href={link.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="block border px-8 py-6 rounded-lg hover:opacity-90 transition-all group"
                        style={{ backgroundColor: colores.tarjeta, borderColor: colores.bordeTarjeta }}
                      >
                        <div className="flex items-center gap-4">
                          {link.image_url && (
                            <img
                              src={link.image_url}
                              alt={link.text}
                              className="w-12 h-12 rounded object-cover"
                            />
                          )}
                          <div className="flex-1">
                            <div className="font-bold text-xl group-hover:translate-x-2 transition-transform">
                              {link.text} →
                            </div>
                            {link.description && (
                              <div className="opacity-70 mt-1">{link.description}</div>
                            )}
                          </div>
                        </div>
                      </a>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        <footer className="mt-24 pt-12 border-t border-current border-opacity-20 text-center">
          <div className="space-y-4">
            <a href="/" className="inline-block opacity-50 hover:opacity-100 transition">
              <img src="/logo.png" alt="Rezonar" className="h-8 mx-auto" />
            </a>
            <p className="text-sm opacity-50">Creado con Rezonar</p>
          </div>
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
                      className="inline-block px-6 py-3 rounded-lg font-bold transition"
                      style={{ backgroundColor: primaryColor, color: backgroundColor }}
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
                className="float-right text-3xl font-bold opacity-60 hover:opacity-100"
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

              <h2 className="text-3xl font-black mb-4">{modalEvent.text}</h2>

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

              <BotonEntradas evento={modalEvent} color={colores.boton} />

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

export default ModernTemplate;
