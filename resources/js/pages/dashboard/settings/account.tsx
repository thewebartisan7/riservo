import SettingsLayout from '@/layouts/settings-layout';
import { Card, CardPanel, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel, FieldError, FieldDescription } from '@/components/ui/field';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    SectionHeading,
    SectionTitle,
    SectionRule,
} from '@/components/ui/section-heading';
import {
    AlertDialog,
    AlertDialogClose,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogPopup,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { useTrans } from '@/hooks/use-trans';
import { Form, router, useHttp } from '@inertiajs/react';
import {
    updateProfile,
    updatePassword,
    uploadAvatar as uploadAvatarAction,
    removeAvatar as removeAvatarAction,
    toggleProvider,
} from '@/actions/App/Http/Controllers/Dashboard/Settings/AccountController';
import type { ChangeEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { getInitials } from '@/lib/booking-format';
import type { AvatarUploadResponse } from '@/types';

interface AccountUser {
    name: string;
    email: string;
    avatar_url: string | null;
}

interface Props {
    user: AccountUser;
    hasPassword: boolean;
    isAdmin: boolean;
    isProvider: boolean;
    hasProviderRow: boolean;
}

export default function Account({
    user,
    hasPassword,
    isAdmin,
    isProvider,
    hasProviderRow,
}: Props) {
    const { t } = useTrans();
    const [avatarUrl, setAvatarUrl] = useState<string | null>(user.avatar_url);
    const [toggleLoading, setToggleLoading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const avatarHttp = useHttp({ avatar: null as File | null });
    const pendingAvatarUpload = useRef(false);

    function handleAvatarChange(e: ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;

        avatarHttp.setData('avatar', file);
        pendingAvatarUpload.current = true;
    }

    useEffect(() => {
        if (pendingAvatarUpload.current && avatarHttp.data.avatar) {
            pendingAvatarUpload.current = false;
            avatarHttp.post(uploadAvatarAction.url(), {
                onSuccess: (response: unknown) => {
                    const data = response as AvatarUploadResponse;
                    setAvatarUrl(data.url);
                },
            });
        }
    }, [avatarHttp.data.avatar]);

    function handleToggle() {
        setToggleLoading(true);
        router.post(
            toggleProvider().url,
            {},
            { onFinish: () => setToggleLoading(false) },
        );
    }

    return (
        <SettingsLayout
            title={t('Account')}
            eyebrow={t('Settings · You')}
            heading={t('Your account')}
            description={t('Your profile, password, and avatar.')}
        >
            <div className="flex flex-col gap-10">
                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Profile')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Form action={updateProfile()} method="put">
                        {({ errors, processing }) => (
                            <Card>
                                <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                                    <Field>
                                        <FieldLabel>{t('Name')}</FieldLabel>
                                        <Input
                                            name="name"
                                            defaultValue={user.name}
                                            required
                                        />
                                        {errors.name && <FieldError match>{errors.name}</FieldError>}
                                    </Field>

                                    <Field>
                                        <FieldLabel>{t('Email')}</FieldLabel>
                                        <Input
                                            name="email"
                                            type="email"
                                            defaultValue={user.email}
                                            required
                                        />
                                        <FieldDescription>
                                            {t('Changing your email sends a verification link to the new address.')}
                                        </FieldDescription>
                                        {errors.email && <FieldError match>{errors.email}</FieldError>}
                                    </Field>
                                </CardPanel>
                                <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                                    <Button type="submit" loading={processing}>
                                        {t('Save profile')}
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}
                    </Form>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{hasPassword ? t('Change password') : t('Set password')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Form
                        action={updatePassword()}
                        method="put"
                        resetOnSuccess
                    >
                        {({ errors, processing }) => (
                            <Card>
                                <CardPanel className="flex flex-col gap-5 p-5 sm:p-6">
                                    {hasPassword && (
                                        <Field>
                                            <FieldLabel>{t('Current password')}</FieldLabel>
                                            <Input
                                                name="current_password"
                                                type="password"
                                                autoComplete="current-password"
                                                required
                                            />
                                            {errors.current_password && (
                                                <FieldError match>{errors.current_password}</FieldError>
                                            )}
                                        </Field>
                                    )}
                                    {!hasPassword && (
                                        <p className="text-xs leading-relaxed text-muted-foreground">
                                            {t('You currently sign in with a magic link. Set a password to also sign in with one.')}
                                        </p>
                                    )}

                                    <Field>
                                        <FieldLabel>{t('New password')}</FieldLabel>
                                        <Input
                                            name="password"
                                            type="password"
                                            autoComplete="new-password"
                                            required
                                        />
                                        {errors.password && <FieldError match>{errors.password}</FieldError>}
                                    </Field>

                                    <Field>
                                        <FieldLabel>{t('Confirm new password')}</FieldLabel>
                                        <Input
                                            name="password_confirmation"
                                            type="password"
                                            autoComplete="new-password"
                                            required
                                        />
                                    </Field>
                                </CardPanel>
                                <CardFooter className="justify-end border-t bg-muted/50 px-5 py-3 sm:px-6">
                                    <Button type="submit" loading={processing}>
                                        {hasPassword ? t('Change password') : t('Set password')}
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}
                    </Form>
                </section>

                <section className="flex flex-col gap-4">
                    <SectionHeading>
                        <SectionTitle>{t('Avatar')}</SectionTitle>
                        <SectionRule />
                    </SectionHeading>

                    <Card>
                        <CardPanel className="flex items-center gap-5 p-5 sm:p-6">
                            <Avatar className="size-16 shrink-0 rounded-2xl border border-border bg-muted">
                                <AvatarImage
                                    src={avatarUrl ?? undefined}
                                    alt=""
                                    className="rounded-2xl object-cover"
                                />
                                <AvatarFallback className="rounded-2xl bg-muted font-display text-base font-semibold text-muted-foreground">
                                    {getInitials(user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="flex flex-col items-start gap-1.5">
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={handleAvatarChange}
                                    className="sr-only"
                                />
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => fileInputRef.current?.click()}
                                        loading={avatarHttp.processing}
                                    >
                                        {avatarUrl ? t('Replace photo') : t('Upload photo')}
                                    </Button>
                                    {avatarUrl && (
                                        <Form
                                            action={removeAvatarAction()}
                                            method="delete"
                                            options={{
                                                onSuccess: () => setAvatarUrl(null),
                                            }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-muted-foreground"
                                                    loading={processing}
                                                >
                                                    {t('Remove')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {t('JPG, PNG, or WebP · up to 2 MB')}
                                </p>
                                {avatarHttp.errors.avatar && (
                                    <p className="text-xs text-primary" role="alert">
                                        {avatarHttp.errors.avatar}
                                    </p>
                                )}
                            </div>
                        </CardPanel>
                    </Card>
                </section>

                {isAdmin && (
                    <section className="flex flex-col gap-4">
                        <SectionHeading>
                            <SectionTitle>{t('Bookable provider')}</SectionTitle>
                            <SectionRule />
                        </SectionHeading>

                        <Card>
                            <CardPanel className="p-5 sm:p-6">
                                <label className="flex cursor-pointer items-start justify-between gap-4">
                                    <span className="flex flex-col gap-1">
                                        <span className="text-sm font-medium text-foreground">
                                            {t('I take bookings myself')}
                                        </span>
                                        <span className="text-xs leading-relaxed text-muted-foreground">
                                            {t('Turn this on to appear as a provider on your booking page. Manage your schedule under Settings → Availability.')}
                                        </span>
                                    </span>
                                    {isProvider ? (
                                        <AlertDialog>
                                            <AlertDialogTrigger render={<Switch checked={true} />} />
                                            <AlertDialogPopup>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>
                                                        {t('Stop taking bookings?')}
                                                    </AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        {t('Customers will not be able to book you until you turn this back on. Your schedule and exceptions are preserved.')}
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogClose render={<Button variant="outline" />}>
                                                        {t('Cancel')}
                                                    </AlertDialogClose>
                                                    <AlertDialogClose
                                                        render={<Button variant="destructive" />}
                                                        onClick={handleToggle}
                                                    >
                                                        {t('Stop taking bookings')}
                                                    </AlertDialogClose>
                                                </AlertDialogFooter>
                                            </AlertDialogPopup>
                                        </AlertDialog>
                                    ) : (
                                        <Switch
                                            checked={false}
                                            disabled={toggleLoading}
                                            onCheckedChange={handleToggle}
                                        />
                                    )}
                                </label>
                            </CardPanel>
                        </Card>

                        {!isProvider && hasProviderRow && (
                            <p className="text-xs text-muted-foreground">
                                {t('Your previous schedule and exceptions are preserved. Turn "Bookable provider" back on to use them.')}
                            </p>
                        )}
                    </section>
                )}
            </div>
        </SettingsLayout>
    );
}
