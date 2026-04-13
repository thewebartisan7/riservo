import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { useTrans } from '@/hooks/use-trans';

export default function Dashboard() {
    const { t } = useTrans();

    return (
        <AuthenticatedLayout title={t('Dashboard')}>
            <p className="text-muted-foreground">
                {t('Dashboard placeholder')}
            </p>
        </AuthenticatedLayout>
    );
}
