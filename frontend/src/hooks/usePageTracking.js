import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { pageview } from '../utils/analytics';

export const usePageTracking = () => {
  const location = useLocation();

  useEffect(() => {
    const title = document.title;
    pageview(location.pathname + location.search, title);
  }, [location]);
};
