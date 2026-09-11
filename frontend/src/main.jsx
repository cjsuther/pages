import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import { iniciarAnalytics } from './utils/analytics';
import './fuentes.css';
import './index.css';

// Antes de montar: la primera vista la manda el hook al montarse y necesita
// el tag ya configurado.
iniciarAnalytics();

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
