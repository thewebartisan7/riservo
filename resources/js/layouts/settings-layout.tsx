import type { PropsWithChildren } from 'react';
import AuthenticatedLayout from './authenticated-layout';
import { SettingsNav } from '@/components/settings/settings-nav';

interface SettingsLayoutProps {
    title?: string;
}

export default function SettingsLayout({
    title,
    children,
}: PropsWithChildren<SettingsLayoutProps>) {
    return (
        <AuthenticatedLayout title={title}>
            <div className="flex flex-col gap-8 lg:flex-row">
                <aside className="w-full shrink-0 lg:w-48">
                    <SettingsNav />
                </aside>
                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </AuthenticatedLayout>
    );
}
