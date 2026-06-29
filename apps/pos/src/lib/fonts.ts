/**
 * Self-hosted webfonts for the Caisse redesign (offline-first Tauri — no CDN).
 * Imported once from `main.tsx`. Latin subset covers French (Tunisia) incl.
 * accents. Weights kept minimal to bound the bundle:
 *   Montserrat   — display/headings/brand (700, 800)
 *   Public Sans  — UI/body (400, 500, 600, 700)
 *   IBM Plex Mono — numbers/prices/IDs (400, 500, 600, 700 for grand total)
 * Wired to --font-display / --font-sans / --font-mono in index.css.
 */
import '@fontsource/montserrat/latin-700.css';
import '@fontsource/montserrat/latin-800.css';

import '@fontsource/public-sans/latin-400.css';
import '@fontsource/public-sans/latin-500.css';
import '@fontsource/public-sans/latin-600.css';
import '@fontsource/public-sans/latin-700.css';

import '@fontsource/ibm-plex-mono/latin-400.css';
import '@fontsource/ibm-plex-mono/latin-500.css';
import '@fontsource/ibm-plex-mono/latin-600.css';
import '@fontsource/ibm-plex-mono/latin-700.css'; // grand total = mono bold
