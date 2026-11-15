# Headless Frontend Editing

In-place editing for decoupled frontends. Not related to Frontend Editing, which needs Drupal-rendered pages.

## Introduction

The node View tab in Drupal shows your decoupled frontend in an iframe. Editors
hover or focus a section, a field or a whole page and edit it in a sidebar that
contains the normal Drupal form. After saving, the preview and the frontend
cache are refreshed. The frontend renders itself; Drupal only supplies the
overlay client, the forms and the access checks.

- Entity, field and (with the submodule) Paragraphs section targets, in any language.
- Read-only preview tokens, so unpublished content and revisions can be previewed.
- Live preview while typing, for rich text and other fields.
- Panel tools above the preview: "Edit page" opens the whole node form (with
  the submodule, without its sections, which have their own forms), and
  modules add their own with `hook_headless_frontend_editing_tools_alter()`.
- On-demand revalidation of the frontend after content changes.

Submodule `headless_frontend_editing_paragraphs` adds and deletes Paragraphs
sections, and adds a "Page layout" view to reorder them by dragging; the
preview shows each new order before it is saved.

## Requirements

- Drupal core ^11.4 or ^12, PHP 8.3 or newer.
- A GraphQL (for example GraphQL Compose) or JSON:API endpoint the frontend reads from.
- Paragraphs, only for the submodule.

## Installation

Install with Composer and enable the module:

    composer require drupal/headless_frontend_editing
    drush en headless_frontend_editing

## Configuration

1. Grant `use headless frontend editing` to editors. Grant
   `administer headless frontend editing` (restricted) only to administrators.
2. Go to `/admin/config/services/headless-frontend-editing` and set:
   - Frontend URL: the public URL of the frontend.
   - Frontend internal URL: the URL Drupal uses to reach the frontend server-side.
   - Preview path (default `/drupal-preview`) and revalidate path (default
     `/api/revalidate`, must start with a slash; empty turns revalidation off).
   - Form mode used for editing (default `headless_editing`, falls back to `default`).
   - Content types that use the frontend view: config `bundles` is a map keyed by
     entity type, for example `bundles: { node: [article, page] }`; empty means all.
   - Entity types that trigger revalidation.
3. Set the shared revalidation secret in `settings.php`:

       $settings['headless_frontend_editing_revalidate_secret'] = getenv('HFE_REVALIDATE_SECRET');

   The status report warns while the URL, the internal URL or the secret is missing.
4. If you use the `next` contrib module's revalidation, leave the revalidate path
   empty so only one of the two revalidates.

Editors without the permission see the live frontend URL in the iframe, without
token and without edit mode. Editing is offered only on the saved default
revision and only if the user may update the node; revisions and unsaved
previews are read-only.

## Frontend contract

Everything below is also described in `docs/protocol.md`. A runnable sketch for
Next.js is in `examples/nextjs`.

1. **Preview route.** Serve a route at the configured preview path (default
   `/drupal-preview`). It reads the query parameters `path`, `token`, `revision`
   and `edit`, renders without caching, and fetches content with the header
   `X-HFE-Preview-Token: <token>`. The `path` may carry a language prefix
   (`/nl/node/preview/…/full`); resolve it like any Drupal path.
2. **Edit mode.** Only when `edit=1`: put `data-hfe` attributes on elements and
   load the client:

       <script src="{DRUPAL}/headless-frontend-editing/client.js" defer></script>

   Never load it outside preview/edit mode. It is served with `max-age=300` and
   an ETag. Web servers that serve `*.js` as static files (some nginx
   configurations) must pass this path to Drupal.
3. **Headers on the preview route.** `Content-Security-Policy: frame-ancestors
   <drupal-origin>` and `Referrer-Policy: no-referrer`. No analytics or tag
   manager on that route: the URL carries the preview token.
4. **Revalidate endpoint.** Drupal POSTs to internal URL + revalidate path with
   header `X-HFE-Revalidate-Secret` and body
   `{"entities":[{"type":"node","uuid":"…","langcode":"en"}]}` after a request
   that changed entities. Check the secret and refresh your cache.

### The `data-hfe` attribute

    data-hfe="entity_type:uuid"             whole entity, for example node:<uuid>
    data-hfe="entity_type:uuid:field_name"  one field, for example node:<uuid>:field_intro
    data-hfe="paragraph:uuid"               a section (submodule: also delete, add)

The language comes from `data-hfe-lang` on the element or its closest ancestor,
else from `<html lang>`. Any entity type that has a form works. Targets the
user may not edit are not shown. Live preview needs a node as the root.

### Soft navigation

When the sidebar saves or live-previews, Drupal sends `hfe:show` with a fresh
`path` and `token`. The client fires a cancelable `hfe:show` event on `window`
with `detail: {path, token}`. If nobody calls `preventDefault()` it replaces the
`path` and `token` query parameters and reloads. Frameworks with soft
navigation (Next.js `router.replace`) should handle the event.

### Keyboard

Hover or focus a target to see its toolbar. Alt+Enter moves focus to the
toolbar. Escape closes the sidebar; it asks before discarding unsaved changes.

## Security

- The token travels in the preview route's query string, because the
  frontend's server needs it. Load no third-party scripts on that route
  (analytics and tag managers send the full URL to their servers), and keep
  its query string out of access logs.
- Everything on the frontend origin is trusted with the editor's preview: a
  script there can open the editing forms and move sections of the page shown
  (moves need a real click and must name that page).
- The token (`{uid}.{expires}.{hmac}`, 10 minutes) is read-only. Send
  `X-HFE-Preview-Token` only to the JSON:API and GraphQL endpoint. Other Drupal
  routes answer 403 when the header is present.
- A GraphQL mutation with the token is refused with 403, and so are persisted
  queries (`queryId`). A JSON:API write with the token is handled as anonymous,
  so it gets the anonymous user's access (normally a 403).
- GraphQL results for token requests are not stored in the GraphQL results
  cache, so preview content never reaches another user.
- The token route is a same-origin GET for the signed-in editor.
- The token provider is only listed on the JSON:API and GraphQL routes. Sites
  with subrequest-style modules should not expose routes that list all
  authentication providers, as those would accept the token too.
- Responses to requests with the token are never page-cached (private, no-cache).
- Every action is checked server-side: entity update access, field edit access,
  the root entity for Paragraphs, content moderation states, CSRF tokens on
  non-form writes.
- The client builds all text with `textContent`; messages are checked on
  origin, source, protocol version and a strict target pattern.
- The revalidate URL is only configurable with the restricted administer permission.

## Troubleshooting

- **Overlay does not appear:** the frontend must load client.js only with
  `edit=1`; check the browser console, that client.js is not blocked (static
  `*.js` rule) and that the page is shown in the Drupal iframe.
- **Iframe stays blank:** the frontend must send `frame-ancestors` with the Drupal origin.
- **403 on content:** the token only works on the GraphQL and JSON:API endpoints and expires after 10 minutes.
- **Changes do not show:** run the status report; check the secret and internal URL, and that the endpoint answers 2xx.
- **Language-prefixed paths 404:** resolve `/nl/...` paths like any Drupal path.

## Maintainers

- remcohoeneveld - https://www.drupal.org/u/remcohoeneveld
