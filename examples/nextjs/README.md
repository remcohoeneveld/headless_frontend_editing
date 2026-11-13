# Next.js example

An illustrative sketch (TypeScript, app router), not a complete app. Copy the
files into your project.

- `app/drupal-preview/page.tsx`: the preview route. Reads `path`, `token`,
  `revision` and `edit`, fetches the route over GraphQL with `X-HFE-Preview-Token`
  and loads `client.js` only when `edit=1`.
- `components/Editable.tsx`: adds `data-hfe` only in edit mode and handles `hfe:show`.
- `app/api/revalidate/route.ts`: checks `X-HFE-Revalidate-Secret` and revalidates a tag.

Set `DRUPAL_URL` (public Drupal URL), `DRUPAL_GRAPHQL_URL` and
`HFE_REVALIDATE_SECRET` (same value as the Drupal `settings.php` secret).
Add these headers for `/drupal-preview` in `next.config`:
`Content-Security-Policy: frame-ancestors <drupal-origin>` and
`Referrer-Policy: no-referrer`.
