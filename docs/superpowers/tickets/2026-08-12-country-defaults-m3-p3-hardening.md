# Country defaults M3 P3 hardening

- Make server-generated defaults-editor credentials demonstrably satisfy every shared
  `Password::defaults()` category on every generation, including guaranteed lower- and uppercase
  characters. `Str::password(24)` currently provides strong entropy and guarantees letters,
  numbers, and symbols, but does not guarantee both cases on every random output.
- Localize every `RequireCentralAdminRole` denial through the existing English/French Country
  Defaults translation catalogs. This middleware predates M3, but M3 exposes its hardcoded English
  role-denial response on the new editor/template surfaces.
- Replace `StaticCountryCatalogProvider`'s code-as-name placeholders with real localized ISO country
  display names while preserving the pinned catalog version and generic-fallback translation.
- Add bounded pagination to the template and defaults-editor index endpoints, including stable
  ordering and response metadata, before the M6 UI begins consuming an unbounded collection.
