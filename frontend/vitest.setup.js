import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, vi } from 'vitest';

// Desmonta el árbol de React entre tests para que no se filtre estado.
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  localStorage.clear();
  sessionStorage.clear();
});

beforeEach(() => {
  // Por defecto ningún test sale a la red: quien necesite una respuesta la
  // declara explícitamente con los helpers de tests/helpers/api.js.
  global.fetch = vi.fn(() =>
    Promise.reject(new Error('fetch no simulado: declaralo con mockFetch()'))
  );
});

// --- APIs del navegador que jsdom no implementa y la app usa ---

window.matchMedia = window.matchMedia || function matchMedia(query) {
  return {
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  };
};

window.scrollTo = vi.fn();

global.IntersectionObserver = class {
  observe() {}
  unobserve() {}
  disconnect() {}
};

global.ResizeObserver = class {
  observe() {}
  unobserve() {}
  disconnect() {}
};

// Google Analytics: los tests que lo verifiquen lo sustituyen por un spy.
window.gtag = undefined;

// Google Maps se carga por <script> en index.html; en tests no existe salvo
// que el propio test lo declare.
window.google = undefined;

// La API de portapapeles no está en jsdom y la usan los botones de compartir.
if (!navigator.clipboard) {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn(() => Promise.resolve()) },
    writable: true,
    configurable: true,
  });
}

// jsdom no implementa canvas y loguea un error por cada getContext(). El
// generador de QR lo usa, así que se provee un contexto inerte; los tests que
// necesiten inspeccionar el dibujo instalan su propio doble encima.
HTMLCanvasElement.prototype.getContext = function getContext() {
  return {
    fillStyle: '', strokeStyle: '', font: '', textAlign: '', globalAlpha: 1,
    fillRect: () => {}, clearRect: () => {}, fillText: () => {}, strokeText: () => {},
    drawImage: () => {}, measureText: (t) => ({ width: String(t).length * 10 }),
    save: () => {}, restore: () => {}, beginPath: () => {}, closePath: () => {},
    arc: () => {}, clip: () => {}, moveTo: () => {}, lineTo: () => {}, stroke: () => {},
    getImageData: () => ({ data: [] }), putImageData: () => {}, translate: () => {},
    scale: () => {}, rotate: () => {}, setTransform: () => {},
  };
};

HTMLCanvasElement.prototype.toBlob = function toBlob(callback) {
  callback(new Blob([''], { type: 'image/png' }));
};

HTMLCanvasElement.prototype.toDataURL = function toDataURL() {
  return 'data:image/png;base64,';
};

// URL.createObjectURL lo usan las previsualizaciones de imagen y el QR.
if (!global.URL.createObjectURL) {
  global.URL.createObjectURL = vi.fn(() => 'blob:mock');
  global.URL.revokeObjectURL = vi.fn();
}
