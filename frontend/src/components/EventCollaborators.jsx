import React from 'react';

/**
 * Shows the organizer and collaborators of an event as page links.
 * - For collaborated events (is_collaborated=true): shows "Organiza:" with the source page
 * - For own or collaborated events with accepted collaborators: shows "Colabora con:"
 *
 * @param {object} event   - The event link object
 * @param {number} currentPageId - ID of the page being viewed (to exclude from collaborators list)
 * @param {string} color   - Optional color for links (default blue)
 */
function EventCollaborators({ event, currentPageId, color = '#3b82f6' }) {
  const isCollaborated = !!event.is_collaborated;

  // Other collaborators = accepted collaborators excluding the current page
  const otherCollaborators = (event.collaborators || []).filter(
    c => c.page_id != currentPageId
  );

  if (!isCollaborated && otherCollaborators.length === 0) return null;

  return (
    <div className="mt-3 pt-3 border-t border-gray-200 space-y-1.5">
      {isCollaborated && event.source_page_slug && (
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-xs font-semibold uppercase tracking-wide opacity-60">Organiza:</span>
          <a
            href={`/${event.source_page_slug}`}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 text-sm font-medium hover:underline"
            style={{ color }}
            onClick={(e) => e.stopPropagation()}
          >
            {event.source_page_image && (
              <img src={event.source_page_image} alt={event.source_page_title} className="w-5 h-5 rounded-full object-cover" />
            )}
            {event.source_page_title}
          </a>
        </div>
      )}

      {otherCollaborators.length > 0 && (
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-xs font-semibold uppercase tracking-wide opacity-60">Colabora:</span>
          {otherCollaborators.map(c => (
            <a
              key={c.page_id}
              href={`/${c.page_slug}`}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 text-sm font-medium hover:underline"
              style={{ color }}
              onClick={(e) => e.stopPropagation()}
            >
              {c.page_image && (
                <img src={c.page_image} alt={c.page_title} className="w-5 h-5 rounded-full object-cover" />
              )}
              {c.page_title}
            </a>
          ))}
        </div>
      )}
    </div>
  );
}

export default EventCollaborators;
