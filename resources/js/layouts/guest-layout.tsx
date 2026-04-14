import { Head, Link } from '@inertiajs/react';
import { home } from '@/routes/index';
import type { PropsWithChildren } from 'react';
import { useTrans } from '@/hooks/use-trans';
import { Display } from '@/components/ui/display';

interface GuestLayoutProps {
    title?: string;
}

export default function GuestLayout({
    title,
    children,
}: PropsWithChildren<GuestLayoutProps>) {
    const { t } = useTrans();

    return (
        <>
            {title && <Head title={title} />}
            <div className="relative flex min-h-svh flex-col bg-background">
                <header className="flex items-center justify-between px-5 pt-6 pb-4 sm:px-8 sm:pt-8 sm:pb-6">
                    <Link
                        href={home()}
                        className="inline-flex items-baseline gap-1.5 text-foreground transition-colors hover:text-foreground/80"
                    >
                        <Display className="text-lg font-semibold leading-none">
                            riservo
                        </Display>
                        <span
                            aria-hidden="true"
                            className="size-1 translate-y-[-1px] rounded-full bg-primary"
                        />
                    </Link>
                    <span className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground sm:text-[11px]">
                        {t('Appointments, quietly handled')}
                    </span>
                </header>

                <main className="flex flex-1 items-center justify-center px-5 pb-12 pt-2 sm:px-8 sm:pb-20 sm:pt-4">
                    <div className="animate-rise w-full max-w-[420px]">
                        {children}
                    </div>
                </main>

                <footer className="flex items-center justify-between gap-4 px-5 pb-6 sm:px-8 sm:pb-8">
                    <span className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground sm:text-[11px]">
                        {t('Crafted in Switzerland')}
                    </span>
                    <span className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground sm:text-[11px]">
                        © {new Date().getFullYear()} riservo
                    </span>
                </footer>
            </div>
        </>
    );
}
