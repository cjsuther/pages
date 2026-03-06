import React, { useState, useEffect, useContext } from 'react';
import { useParams } from 'react-router-dom';
import { Helmet } from 'react-helmet-async';
import { AuthContext } from '../App';
import { trackEvent } from '../utils/analytics';
import MinimalTemplate from '../components/templates/MinimalTemplate';
import CardsTemplate from '../components/templates/CardsTemplate';
import ModernTemplate from '../components/templates/ModernTemplate';
import CondensedTemplate from '../components/templates/CondensedTemplate';

function PublicPage() {
  const { slug } = useParams();
  const { apiUrl } = useContext(AuthContext);
  const [page, setPage] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchPage();
  }, [slug]);

  const fetchPage = async () => {
    try {
      const response = await fetch(`${apiUrl}/public/page.php?slug=${slug}`);
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Página no encontrada');
      }

      setPage(data.page);
      trackEvent.viewPublicPage(slug);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-black text-white flex items-center justify-center">
        <p className="text-xl font-medium">Cargando...</p>
      </div>
    );
  }

  if (error || !page) {
    return (
      <div className="min-h-screen bg-black text-white flex items-center justify-center">
        <div className="text-center space-y-4">
          <h1 className="text-6xl font-black">404</h1>
          <p className="text-xl text-gray-400">{error || 'Página no encontrada'}</p>
        </div>
      </div>
    );
  }

  const pageTitle = `${page.title} | Rezonar`;
  const pageDescription = page.description || `Página de ${page.title} en Rezonar. Explora enlaces, eventos y contenido.`;
  const pageImage = page.profile_image || page.background_image || '';
  const pageUrl = window.location.href;

  const template = page.template || 'minimal';

  return (
    <>
      <Helmet>
        <title>{pageTitle}</title>
        <meta name="description" content={pageDescription} />
        <meta property="og:title" content={pageTitle} />
        <meta property="og:description" content={pageDescription} />
        <meta property="og:type" content="profile" />
        <meta property="og:url" content={pageUrl} />
        {pageImage && <meta property="og:image" content={pageImage} />}
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content={pageTitle} />
        <meta name="twitter:description" content={pageDescription} />
        {pageImage && <meta name="twitter:image" content={pageImage} />}
        <link rel="canonical" href={pageUrl} />
      </Helmet>
      {template === 'cards' && <CardsTemplate page={page} />}
      {template === 'modern' && <ModernTemplate page={page} />}
      {template === 'condensed' && <CondensedTemplate page={page} />}
      {template === 'minimal' && <MinimalTemplate page={page} />}
      {!['cards', 'modern', 'condensed', 'minimal'].includes(template) && <MinimalTemplate page={page} />}
    </>
  );
}

export default PublicPage;
