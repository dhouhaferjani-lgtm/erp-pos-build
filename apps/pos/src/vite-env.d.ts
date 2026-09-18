/// <reference types="vite/client" />
// The generated types live at a relative path (not an @types-style package)
// and wrap their namespaces in `declare global { … }`. A path-based
// triple-slash reference is the simplest way to pull them into the ambient
// scope of every file compiled from apps/pos. This form is deliberate.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="../../../packages/shared/types/generated.d.ts" />

interface ImportMetaEnv {
  readonly VITE_REVERB_APP_KEY?: string;
  readonly VITE_REVERB_HOST?: string;
  readonly VITE_REVERB_PORT?: string;
  readonly VITE_REVERB_WSS_PORT?: string;
  readonly VITE_REVERB_SCHEME?: string;
  readonly VITE_APP_VERSION?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
