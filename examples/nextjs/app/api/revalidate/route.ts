import { revalidateTag } from 'next/cache';
import { NextResponse } from 'next/server';

export async function POST(request: Request) {
  if (request.headers.get('X-HFE-Revalidate-Secret') !== process.env.HFE_REVALIDATE_SECRET) {
    return NextResponse.json({ error: 'Forbidden' }, { status: 403 });
  }
  // Body: {"entities":[{"type":"node","uuid":"…","langcode":"en"}]}. Tag your
  // fetches with 'drupal' (or per uuid) and refresh them here.
  revalidateTag('drupal');
  return NextResponse.json({ revalidated: true });
}
