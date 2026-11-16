# Frontend source layout

The React application uses feature-first folders under `src/`.

- `app/` composes providers, route guards, shells, and the route table. `app/App.tsx` contains route declarations only.
- `features/auth/`, `features/student/`, `features/workspace/`, `features/teacher/`, `features/office/`, and `features/admin/` own role pages, feature components, and feature APIs.
- `shared/ui/` contains reusable interface primitives. `shared/components/` contains cross-feature domain UI. `shared/hooks/` contains reusable hooks.
- `shared/api/` contains the API client, storage, and APIs used across features. `shared/lib/` groups pure helpers by topic. `shared/types/` contains types used across feature boundaries.
- `shared/context/` contains low-level shared contexts consumed by shared UI; app-level providers are composed in `app/providers/`.
- `i18n/` contains translation lookup, locale messages, and language-motion support. `styles/` and `styles.css` contain global styles; component styles stay imported by their components.

## Naming and placement

Use PascalCase for React component files, `use*` for hooks, and descriptive lower-camel-case names for pure helpers. Put a route page with its feature, a component used by one feature beside that feature, and code shared across features under `shared/`. Keep API paths, route paths, translation keys, storage keys, CSS class names, IDs, and accessibility attributes stable when reorganizing files.

## Dependency rules

- `shared/*` must not import from `features/*` or `app/*`.
- A feature must not import another feature. Move genuinely cross-feature code into `shared/`.
- `app/*` may compose providers, routes, shells, and any feature entry points.

Use the `@/` alias for imports rooted at `src/`. Avoid large barrel files; import a module from its owning file so feature boundaries stay visible.