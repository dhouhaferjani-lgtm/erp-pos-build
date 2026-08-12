# Country defaults M3 P3 hardening

- Make server-generated defaults-editor credentials demonstrably satisfy every shared
  `Password::defaults()` category on every generation, including guaranteed lower- and uppercase
  characters. `Str::password(24)` currently provides strong entropy and guarantees letters,
  numbers, and symbols, but does not guarantee both cases on every random output.
