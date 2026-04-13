import { Head, Link } from '@inertiajs/react';
import { home } from '@/routes/index';
import type { PropsWithChildren } from 'react';

interface GuestLayoutProps {
    title?: string;
}

export default function GuestLayout({
    title,
    children,
}: PropsWithChildren<GuestLayoutProps>) {
    return (
        <>
            {title && <Head title={title} />}
            <div className="flex min-h-screen flex-col items-center justify-center bg-muted/40 p-4">
                <div className="mb-8">
                    <Link href={home()} className="text-2xl font-bold">
                        riservo
                    </Link>
                </div>
                <div className="w-full max-w-md">{children}</div>
            </div>
        </>
    );
}
