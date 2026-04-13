import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';

export default function Welcome() {
    const { t } = useTrans();

    return (
        <>
            <Head title={t('Welcome')} />
            <div className="flex min-h-screen flex-col items-center justify-center">
                <h1 className="mb-8 text-4xl font-bold">riservo</h1>
                <p className="mb-8 text-muted-foreground">
                    {t('Welcome to :app', { app: 'riservo' })}
                </p>
                <div className="flex gap-4">
                    <Button variant="outline" render={<Link href="/login" />}>
                        {t('Log in')}
                    </Button>
                    <Button render={<Link href="/register" />}>
                        {t('Register')}
                    </Button>
                </div>
            </div>
        </>
    );
}
