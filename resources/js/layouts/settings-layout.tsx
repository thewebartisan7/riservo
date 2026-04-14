import type { PropsWithChildren, ReactNode } from 'react';
import AuthenticatedLayout from './authenticated-layout';
import { SettingsNav } from '@/components/settings/settings-nav';

interface SettingsLayoutProps {
    title?: string;
    eyebrow?: string;
    heading?: string;
    description?: string;
    actions?: ReactNode;
}

export default function SettingsLayout({
    title,
    eyebrow,
    heading,
    description,
    actions,
    children,
}: PropsWithChildren<SettingsLayoutProps>) {
    return (
        <AuthenticatedLayout
            title={title}
            eyebrow={eyebrow}
            heading={heading}
            description={description}
            actions={actions}
        >
            <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-12">
                <aside className="w-full shrink-0 lg:w-52 lg:sticky lg:top-8">
                    <SettingsNav />
                </aside>
                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </AuthenticatedLayout>
    );
}
