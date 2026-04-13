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

export default function Register() {
    const { t } = useTrans();

    return (
        <GuestLayout title={t('Register')}>
            <Card>
                <CardHeader>
                    <CardTitle>{t('Register')}</CardTitle>
                    <CardDescription>
                        {t('Create your account')}
                    </CardDescription>
                </CardHeader>
                <CardPanel className="flex flex-col gap-4">
                    <Input
                        type="text"
                        placeholder={t('Name')}
                        aria-label={t('Name')}
                    />
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
                    <Input
                        type="password"
                        placeholder={t('Confirm Password')}
                        aria-label={t('Confirm Password')}
                    />
                </CardPanel>
                <CardFooter className="flex justify-end">
                    <Button type="button">{t('Register')}</Button>
                </CardFooter>
            </Card>
        </GuestLayout>
    );
}
