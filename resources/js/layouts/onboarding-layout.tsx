import { Head, Link, router } from '@inertiajs/react';
import { Progress } from '@/components/ui/progress';
import { useTrans } from '@/hooks/use-trans';
import type { PropsWithChildren } from 'react';

interface OnboardingLayoutProps {
    step: number;
    totalSteps?: number;
    title?: string;
}

const STEP_LABELS = [
    'Business Profile',
    'Working Hours',
    'First Service',
    'Invite Team',
    'Review & Launch',
];

export default function OnboardingLayout({
    step,
    totalSteps = 5,
    title,
    children,
}: PropsWithChildren<OnboardingLayoutProps>) {
    const { t } = useTrans();

    return (
        <>
            {title && <Head title={title} />}
            <div className="flex min-h-screen flex-col bg-muted/40">
                <header className="flex items-center justify-between border-b bg-background px-6 py-4">
                    <span className="text-xl font-bold">riservo</span>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        {t('Log out')}
                    </Link>
                </header>

                <div className="mx-auto w-full max-w-2xl px-4 py-4">
                    <div className="mb-2 flex items-center justify-between text-sm text-muted-foreground">
                        <span>{t('Step :current of :total', { current: step, total: totalSteps })}</span>
                        <span>{t(STEP_LABELS[step - 1] ?? '')}</span>
                    </div>
                    <Progress value={(step / totalSteps) * 100} />

                    <nav className="mt-3 flex gap-1">
                        {STEP_LABELS.map((label, index) => {
                            const stepNum = index + 1;
                            const isCompleted = stepNum < step;
                            const isCurrent = stepNum === step;

                            return (
                                <button
                                    key={stepNum}
                                    type="button"
                                    onClick={() => isCompleted && router.visit(`/onboarding/step/${stepNum}`)}
                                    disabled={!isCompleted}
                                    className={`flex-1 rounded-md px-2 py-1.5 text-xs transition-colors ${
                                        isCurrent
                                            ? 'bg-primary/10 font-medium text-primary'
                                            : isCompleted
                                              ? 'cursor-pointer text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                                              : 'text-muted-foreground/50'
                                    }`}
                                >
                                    {t(label)}
                                </button>
                            );
                        })}
                    </nav>
                </div>

                <main className="mx-auto w-full max-w-2xl flex-1 px-4 pb-8">
                    {children}
                </main>
            </div>
        </>
    );
}
