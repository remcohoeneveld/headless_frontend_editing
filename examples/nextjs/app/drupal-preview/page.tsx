import Script from 'next/script';
import { Editable } from '@/components/Editable';

export const dynamic = 'force-dynamic';

type Props = {
  searchParams: Promise<{ path?: string; token?: string; revision?: string; edit?: string }>;
};

const QUERY = `query ($path: String!) {
  route(path: $path) {
    ... on RouteInternal {
      entity {
        ... on NodePage { uuid title langcode { id } }
      }
    }
  }
}`;

export default async function DrupalPreview({ searchParams }: Props) {
  const { path = '/', token = '', edit } = await searchParams;
  const editing = edit === '1';

  // The token only works on the GraphQL endpoint; never send it elsewhere.
  const response = await fetch(process.env.DRUPAL_GRAPHQL_URL!, {
    method: 'POST',
    cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-HFE-Preview-Token': token },
    body: JSON.stringify({ query: QUERY, variables: { path } }),
  });
  const node = (await response.json()).data?.route?.entity;
  if (!node) {
    return <p>Not found.</p>;
  }

  return (
    <main lang={node.langcode.id}>
      <Editable target={`node:${node.uuid}`} editing={editing}>
        <h1>{node.title}</h1>
      </Editable>
      {editing && (
        <Script src={`${process.env.DRUPAL_URL}/headless-frontend-editing/client.js`} strategy="afterInteractive" />
      )}
    </main>
  );
}
