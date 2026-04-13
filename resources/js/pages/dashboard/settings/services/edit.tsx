import SettingsLayout from '@/layouts/settings-layout';
import { useTrans } from '@/hooks/use-trans';
import { ServiceForm } from '@/components/settings/service-form';
import { update } from '@/actions/App/Http/Controllers/Dashboard/Settings/ServiceController';

interface Props {
    service: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        duration_minutes: number;
        price: number | null;
        buffer_before: number;
        buffer_after: number;
        slot_interval_minutes: number;
        is_active: boolean;
        collaborator_ids: number[];
    };
    collaborators: { id: number; name: string }[];
}

export default function EditService({ service, collaborators }: Props) {
    const { t } = useTrans();

    return (
        <SettingsLayout title={t('Edit Service')}>
            <ServiceForm
                action={update(service.id)}
                service={service}
                collaborators={collaborators}
                submitLabel={t('Save changes')}
            />
        </SettingsLayout>
    );
}
