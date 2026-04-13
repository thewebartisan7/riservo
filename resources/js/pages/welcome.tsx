import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/hooks/use-trans';
import { create as loginCreate } from '@/actions/App/Http/Controllers/Auth/LoginController';
import { create as registerCreate } from '@/actions/App/Http/Controllers/Auth/RegisterController';

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
                    <Button variant="outline" render={<Link href={loginCreate()} />}>
                        {t('Log in')}
                    </Button>
                    <Button render={<Link href={registerCreate()} />}>
                        {t('Register')}
                    </Button>
                </div>
            </div>
        </>
    );
}
