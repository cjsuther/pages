export const handleApiResponse = async (response, navigate, logout) => {
  if (response.status === 401) {
    logout();
    navigate('/');
    throw new Error('Unauthorized');
  }
  return response;
};

export const createAuthenticatedFetch = (token, navigate, logout) => {
  return async (url, options = {}) => {
    const response = await fetch(url, {
      ...options,
      headers: {
        ...options.headers,
        'Authorization': `Bearer ${token}`
      }
    });

    return handleApiResponse(response, navigate, logout);
  };
};
