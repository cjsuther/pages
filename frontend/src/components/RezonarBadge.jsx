import React from 'react';

function RezonarBadge() {
  return (
    <a
      href="/"
      className="fixed top-4 left-4 z-40 block rounded-lg overflow-hidden shadow-lg opacity-80 hover:opacity-100 transition-opacity"
      style={{ width: '100px' }}
      title="Ir a Rezonar"
    >
      <img src="/logo.png" alt="Rezonar" className="w-full block" />
    </a>
  );
}

export default RezonarBadge;
