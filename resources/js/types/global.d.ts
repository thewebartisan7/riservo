/// <reference types="vite/client" />

import type { PageProps as AppPageProps } from './';

declare module '@inertiajs/react' {
    interface PageProps extends AppPageProps {}
}
