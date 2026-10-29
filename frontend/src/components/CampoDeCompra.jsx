import React from 'react';

/** Un campo de texto de los formularios de compra de productos, con su etiqueta y su ayuda. */
function CampoDeCompra({ id, etiqueta, ayuda, value, onChange, type = 'text', ...resto }) {
  const conProblema = resto['aria-invalid'] === 'true';
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
        {etiqueta}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={`w-full px-4 py-3 bg-white border text-tinta focus:border-verde-oscuro transition ${
          conProblema ? 'border-red-200' : 'border-borde-fuerte'
        }`}
        {...resto}
      />
      {ayuda && <p className="text-xs text-tinta-suave mt-1">{ayuda}</p>}
    </div>
  );
}

export default CampoDeCompra;
