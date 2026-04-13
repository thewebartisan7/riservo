import GuestLayout from '@/layouts/guest-layout';
import {
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardPanel,
    CardFooter,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/hooks/use-trans';

export default function Login() {
    const { t } = useTrans();

    return (
        <GuestLayout title={t('Log in')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Log in')}</CardTitle>
                    <CardDescription>{t('Welcome back')}</CardDescription>
                </CardHeader>
                <CardPanel className="flex flex-col gap-4">
                    <Input
                        type="email"
                        placeholder={t('Email')}
                        aria-label={t('Email')}
                    />
                    <Input
                        type="password"
                        placeholder={t('Password')}
                        aria-label={t('Password')}
                    />
                </CardPanel>
                <CardFooter className="flex justify-end">
                    <Button type="button">{t('Log in')}</Button>
                </CardFooter>
            </Card>
        </GuestLayout>
    );
}
