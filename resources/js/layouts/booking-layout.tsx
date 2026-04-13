import { Head } from '@inertiajs/react';
import { home } from '@/routes/index';
import type { PropsWithChildren } from 'react';
import { useTrans } from '@/hooks/use-trans';

interface BookingLayoutProps {
    title?: string;
    businessName: string;
    businessLogoUrl?: string | null;
    embed?: boolean;
}

export default function BookingLayout({
    title,
    businessName,
    businessLogoUrl,
    embed,
    children,
}: PropsWithChildren<BookingLayoutProps>) {
    const { t } = useTrans();

    if (embed) {
        return (
            <>
                {title && <Head title={title} />}
                <div className="flex min-h-screen flex-col">
                    <main className="mx-auto w-full max-w-lg flex-1 px-4 py-6">
                        {children}
                    </main>
                </div>
            </>
        );
    }

    return (
        <>
            {title && <Head title={title} />}
            <div className="flex min-h-screen flex-col bg-muted/40">
                <header className="border-b bg-background">
                    <div className="mx-auto flex h-14 max-w-lg items-center justify-between px-4">
                        <div className="flex items-center gap-3">
                            {businessLogoUrl ? (
                                <img
                                    src={businessLogoUrl}
                                    alt={businessName}
                                    className="h-8 w-8 rounded-full object-cover"
                                />
                            ) : null}
                            <span className="font-semibold">{businessName}</span>
                        </div>
                        <a
                            href={home.url()}
                            className="text-xs text-muted-foreground hover:text-foreground"
                        >
                            riservo
                        </a>
                    </div>
                </header>
                <main className="mx-auto w-full max-w-lg flex-1 px-4 py-6">
                    {children}
                </main>
                <footer className="border-t bg-background py-4 text-center text-xs text-muted-foreground">
                    <a href={home.url()} className="hover:text-foreground">
                        {t('Powered by riservo.ch')}
                    </a>
                </footer>
            </div>
        </>
    );
}
