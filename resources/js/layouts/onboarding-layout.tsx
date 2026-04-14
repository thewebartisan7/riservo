import { Head, Link, router } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { destroy } from '@/actions/App/Http/Controllers/Auth/LoginController';
import { show } from '@/actions/App/Http/Controllers/OnboardingController';
import { home } from '@/routes/index';
import { Display } from '@/components/ui/display';
import { useTrans } from '@/hooks/use-trans';

interface OnboardingLayoutProps {
    step: number;
    totalSteps?: number;
    title?: string;
    eyebrow?: string;
    heading?: string;
    description?: string;
}

const STEP_LABELS = [
    'Business profile',
    'Working hours',
    'First service',
    'Invite team',
    'Review & launch',
] as const;

export default function OnboardingLayout({
    step,
    totalSteps = 5,
    title,
    eyebrow,
    heading,
    description,
    children,
}: PropsWithChildren<OnboardingLayoutProps>) {
    const { t } = useTrans();
    const stepLabel = t(STEP_LABELS[step - 1] ?? '');
    const progress = step / totalSteps;

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
                    <Link
                        href={destroy()}
                        method="post"
                        as="button"
                        className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground sm:text-[11px]"
                    >
                        {t('Log out')}
                    </Link>
                </header>

                <main className="flex flex-1 flex-col items-center px-5 pb-12 pt-2 sm:px-8 sm:pb-20 sm:pt-4">
                    <div className="w-full max-w-[640px]">
                        <div className="mb-6 flex flex-col gap-3 sm:mb-8">
                            <div className="flex items-center justify-between gap-4">
                                <div className="tabular-nums text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                                    <span className="text-foreground">
                                        {String(step).padStart(2, '0')}
                                    </span>
                                    <span className="mx-1.5 text-rule-strong">/</span>
                                    <span>{String(totalSteps).padStart(2, '0')}</span>
                                    <span className="mx-3 text-rule-strong" aria-hidden="true">·</span>
                                    <span>{stepLabel}</span>
                                </div>
                                {step > 1 && (
                                    <button
                                        type="button"
                                        onClick={() => router.visit(show(step - 1))}
                                        className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground transition-colors hover:text-foreground sm:text-[11px]"
                                    >
                                        ← {t('Back')}
                                    </button>
                                )}
                            </div>
                            <div
                                className="relative h-px w-full overflow-hidden bg-border"
                                role="progressbar"
                                aria-valuenow={step}
                                aria-valuemin={1}
                                aria-valuemax={totalSteps}
                                aria-label={t('Onboarding progress')}
                            >
                                <div
                                    className="absolute inset-y-0 left-0 bg-primary transition-[width] duration-500 ease-[cubic-bezier(0.2,0.8,0.2,1)]"
                                    style={{ width: `${progress * 100}%` }}
                                />
                            </div>
                        </div>

                        {(eyebrow || heading || description) && (
                            <div className="mb-6 flex flex-col gap-2 sm:mb-8">
                                {eyebrow && (
                                    <p className="text-[11px] uppercase tracking-[0.22em] text-muted-foreground">
                                        {eyebrow}
                                    </p>
                                )}
                                {heading && (
                                    <Display
                                        render={<h1 />}
                                        className="text-[clamp(1.625rem,1.3rem+1vw,2rem)] font-semibold leading-[1.05] text-foreground"
                                    >
                                        {heading}
                                    </Display>
                                )}
                                {description && (
                                    <p className="text-balance text-sm leading-relaxed text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        <div key={step} className="animate-rise">
                            {children}
                        </div>
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
