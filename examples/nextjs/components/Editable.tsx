'use client';

import { useEffect, type ReactNode } from 'react';
import { useRouter } from 'next/navigation';

/** Marks its child as an editing target, only in edit mode. */
export function Editable({ target, editing, children }: { target: string; editing: boolean; children: ReactNode }) {
  const router = useRouter();

  useEffect(() => {
    if (!editing) return;
    // Soft navigation: take over the client's default full reload.
    const onShow = (event: Event) => {
      event.preventDefault();
      const { path, token } = (event as CustomEvent<{ path: string; token: string }>).detail;
      router.replace(`?${new URLSearchParams({ path, token, edit: '1' })}`);
    };
    window.addEventListener('hfe:show', onShow);
    return () => window.removeEventListener('hfe:show', onShow);
  }, [editing, router]);

  return <div data-hfe={editing ? target : undefined}>{children}</div>;
}
