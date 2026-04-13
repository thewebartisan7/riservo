import SettingsLayout from '@/layouts/settings-layout';
import { useTrans } from '@/hooks/use-trans';
import { ServiceForm } from '@/components/settings/service-form';
import { store } from '@/actions/App/Http/Controllers/Dashboard/Settings/ServiceController';

interface Props {
    collaborators: { id: number; name: string }[];
}

export default function CreateService({ collaborators }: Props) {
    const { t } = useTrans();

    return (
        <SettingsLayout title={t('New Service')}>
            <ServiceForm
                action={store()}
                collaborators={collaborators}
                submitLabel={t('Create Service')}
            />
        </SettingsLayout>
    );
}
