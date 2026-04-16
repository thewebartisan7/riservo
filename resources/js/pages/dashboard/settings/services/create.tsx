import SettingsLayout from '@/layouts/settings-layout';
import { useTrans } from '@/hooks/use-trans';
import { ServiceForm } from '@/components/settings/service-form';
import { store } from '@/actions/App/Http/Controllers/Dashboard/Settings/ServiceController';

interface Props {
    providers: { id: number; name: string }[];
}

export default function CreateService({ providers }: Props) {
    const { t } = useTrans();

    return (
        <SettingsLayout
            title={t('New Service')}
            eyebrow={t('Settings · Team')}
            heading={t('New service')}
            description={t('Add a treatment, set its duration and price, and choose who performs it.')}
        >
            <ServiceForm
                action={store()}
                providers={providers}
                submitLabel={t('Create service')}
            />
        </SettingsLayout>
    );
}
