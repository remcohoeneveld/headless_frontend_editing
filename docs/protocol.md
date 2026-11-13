# Editing protocol v1

The Drupal host page (the node View tab) embeds the frontend preview in an
iframe and talks to `client.js` in it with `postMessage`. Every message has
`v: 1`; messages with another version, origin or source are ignored.

| Direction | Type | Payload |
|---|---|---|
| frontend to Drupal | `hfe:ready` | `{targets: [{id, lang}]}` |
| frontend to Drupal | `hfe:action` | `{target, lang, action}`; action is `edit`, `up`, `down`, `delete`, `add-before` or `add-after` |
| frontend to Drupal | `hfe:navigate` | `{path}`, only `^/(?!/)` |
| Drupal to frontend | `hfe:describe` | `{targets: [{id, lang, label, actions: [{id, label, disabled}]}]}` |
| Drupal to frontend | `hfe:show` | `{path, token}` |
| sidebar to host (same origin) | `hfe:live` | `{path}`, only `^/(?:[a-z]{2,3}(?:-[a-z0-9]+)?/)?node/preview/[\w-]+/full$` (language prefix allowed) |
| sidebar to host (same origin) | `hfe:saved` | `{}` |

`client.js` sends `hfe:ready` once targets exist and again when new ones appear
(it observes DOM mutations). Labels and actions in `hfe:describe` come from
Drupal, already translated and filtered by access. `hfe:navigate` keeps
the host's route in sync when a visitor follows an internal link.

## Targets

    target = entity_type ":" uuid [ ":" field_name ]
    ^([a-z_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?::([a-z0-9_]+))?$

Set on elements as `data-hfe`; the language is `data-hfe-lang` on the element
or an ancestor, else `<html lang>`.

## Routes

| Route | Purpose |
|---|---|
| `/headless-frontend-editing/client.js` | the overlay client (public, max-age=300, ETag) |
| `/headless-frontend-editing/describe` | POST, CSRF header; describes targets |
| `/headless-frontend-editing/token` | GET; fresh preview token for the signed-in editor |
| `/headless-frontend-editing/edit/{langcode}/{target}` | the edit form in the sidebar |
| `/headless-frontend-editing/saved` | shown after saving |
| `/headless-frontend-editing/paragraph/{uuid}/…` | submodule: add, move (POST), delete |

## Soft navigation

On `hfe:show` the client dispatches a cancelable `hfe:show` event on `window`
with `detail: {path, token}`. Without `preventDefault()` it replaces the `path`
and `token` query parameters and reloads.

## Frontend requirements

1. A preview route (default `/drupal-preview`) reading `path`, `token`,
   `revision` and `edit`, rendered uncached, sending `X-HFE-Preview-Token` to
   the JSON:API or GraphQL endpoint only. The token is read-only: mutations,
   JSON:API writes and persisted queries are refused.
2. With `edit=1` only: `data-hfe` attributes and `client.js`.
3. `Content-Security-Policy: frame-ancestors <drupal-origin>` and
   `Referrer-Policy: no-referrer` on that route.
4. A revalidate endpoint checking `X-HFE-Revalidate-Secret`. Body:
   `{"entities":[{"type","uuid","langcode"}]}`.
