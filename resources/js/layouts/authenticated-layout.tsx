import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard } from '@/routes/index';
import { destroy } from '@/actions/App/Http/Controllers/Auth/LoginController';
import type { PropsWithChildren } from 'react';
import type { PageProps } from '@/types';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarRail,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { Separator } from '@/components/ui/separator';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useTrans } from '@/hooks/use-trans';

interface AuthenticatedLayoutProps {
    title?: string;
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((word) => word[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export default function AuthenticatedLayout({
    title,
    children,
}: PropsWithChildren<AuthenticatedLayoutProps>) {
    const { auth } = usePage<PageProps>().props;
    const { t } = useTrans();

    return (
        <>
            {title && <Head title={title} />}
            <SidebarProvider>
                <Sidebar>
                    <SidebarHeader>
                        <span className="text-lg font-bold">riservo</span>
                    </SidebarHeader>
                    <SidebarContent>
                        <SidebarGroup>
                            <SidebarGroupLabel>
                                {t('Navigation')}
                            </SidebarGroupLabel>
                            <SidebarGroupContent>
                                <SidebarMenu>
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            render={<a href={dashboard.url()} />}
                                        >
                                            {t('Dashboard')}
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                </SidebarMenu>
                            </SidebarGroupContent>
                        </SidebarGroup>
                    </SidebarContent>
                    <SidebarFooter>
                        {auth.user && (
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton>
                                        <Avatar className="size-6">
                                            <AvatarImage
                                                src={
                                                    auth.user.avatar ??
                                                    undefined
                                                }
                                                alt={auth.user.name}
                                            />
                                            <AvatarFallback>
                                                {getInitials(auth.user.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span>{auth.user.name}</span>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        render={
                                            <Link
                                                href={destroy()}
                                                method="post"
                                                as="button"
                                            />
                                        }
                                    >
                                        {t('Log out')}
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        )}
                    </SidebarFooter>
                    <SidebarRail />
                </Sidebar>
                <SidebarInset>
                    <header className="flex h-14 items-center gap-2 border-b px-4">
                        <SidebarTrigger />
                        <Separator
                            orientation="vertical"
                            className="h-4"
                        />
                        {title && (
                            <h1 className="text-sm font-medium">{title}</h1>
                        )}
                    </header>
                    <main className="flex-1 p-4">{children}</main>
                </SidebarInset>
            </SidebarProvider>
        </>
    );
}
