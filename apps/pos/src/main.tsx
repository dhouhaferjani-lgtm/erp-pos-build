import React from 'react';
import ReactDOM from 'react-dom/client';
import './lib/i18n';
import './lib/fonts';
import { App } from './App';
import { syncThemeFromStore } from './lib/initTheme';
import './index.css';

const rootElement = document.getElementById('root');
if (!rootElement) throw new Error('Root element not found');

// Apply persisted appearance settings before first paint (no theme flash).
syncThemeFromStore();

ReactDOM.createRoot(rootElement).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
