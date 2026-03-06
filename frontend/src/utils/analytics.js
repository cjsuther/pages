export const GA_MEASUREMENT_ID = import.meta.env.VITE_GA_MEASUREMENT_ID || 'G-XXXXXXXXXX';

export const pageview = (url, title) => {
  if (typeof window.gtag !== 'undefined') {
    window.gtag('config', GA_MEASUREMENT_ID, {
      page_path: url,
      page_title: title,
    });
  }
};

const sendEvent = (action, params = {}) => {
  if (typeof window.gtag !== 'undefined') {
    window.gtag('event', action, params);
  }
};

export const trackEvent = {
  event: (action, params = {}) => {
    sendEvent(action, params);
  },
  pageView: (pageName, params = {}) => {
    sendEvent('page_view', {
      page_name: pageName,
      ...params
    });
  },

  userLogin: (method = 'email') => {
    sendEvent('login', {
      method: method
    });
  },

  userRegister: (method = 'email') => {
    sendEvent('sign_up', {
      method: method
    });
  },

  createPage: (pageId) => {
    sendEvent('create_page', {
      page_id: pageId
    });
  },

  editPage: (pageId) => {
    sendEvent('edit_page', {
      page_id: pageId
    });
  },

  addLink: (linkType) => {
    sendEvent('add_link', {
      link_type: linkType
    });
  },

  addEvent: (eventId) => {
    sendEvent('add_event', {
      event_id: eventId
    });
  },

  viewPublicPage: (slug) => {
    sendEvent('view_public_page', {
      page_slug: slug
    });
  },

  clickLink: (linkUrl, linkTitle) => {
    sendEvent('click_link', {
      link_url: linkUrl,
      link_title: linkTitle
    });
  },

  shareEvent: (eventId, shareMethod) => {
    sendEvent('share_event', {
      event_id: eventId,
      method: shareMethod
    });
  },

  searchPages: (query) => {
    sendEvent('search', {
      search_term: query
    });
  },

  viewMap: () => {
    sendEvent('view_map');
  },

  interactMap: (action) => {
    sendEvent('map_interaction', {
      interaction_type: action
    });
  },

  changeTemplate: (templateName) => {
    sendEvent('change_template', {
      template_name: templateName
    });
  },

  uploadImage: (imageType) => {
    sendEvent('upload_image', {
      image_type: imageType
    });
  }
};
