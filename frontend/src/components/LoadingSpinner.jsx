import React from 'react';

function LoadingSpinner({ size = 'md', message = 'Cargando...' }) {
  const sizeClasses = {
    sm: 'w-4 h-4 border-2',
    md: 'w-8 h-8 border-3',
    lg: 'w-12 h-12 border-4'
  };

  return (
    <div className="flex flex-col items-center justify-center gap-3">
      <div className={`${sizeClasses[size]} border-blue-600 border-t-transparent rounded-full animate-spin`} />
      {message && <p className="text-gray-600 text-sm">{message}</p>}
    </div>
  );
}

export default LoadingSpinner;
