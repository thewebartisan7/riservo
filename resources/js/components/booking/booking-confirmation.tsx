import { useTrans } from '@/hooks/use-trans';
import { show } from '@/actions/App/Http/Controllers/Booking/BookingManagementController';
import type { PublicProvider, PublicService } from '@/types';
import { Button } from '@/components/ui/button';
import { Display } from '@/components/ui/display';
import {
    formatDateLong,
    formatDay,
    formatMonthShort,
} from '@/lib/booking-format';

interface BookingConfirmationProps {
    status: string;
    token: string;
    service: PublicService;
    provider: PublicProvider | null;
    date: string;
    time: string;
    businessName: string;
    onBookAnother: () => void;
}

export default function BookingConfirmation({
    status,
    token,
    service,
    provider,
    date,
    time,
    businessName,
    onBookAnother,
}: BookingConfirmationProps) {
    const { t } = useTrans();
    const isConfirmed = status === 'confirmed';

    return (
        <div className="flex flex-col gap-8">
            {/* Hand-drawn check / stamp — not a generic checkmark circle */}
            <div className="flex items-center gap-4">
                <svg
                    width="56"
                    height="56"
                    viewBox="0 0 56 56"
                    fill="none"
                    aria-hidden
                    className="shrink-0 stroke-primary"
                >
                    <circle
                        cx="28"
                        cy="28"
                        r="26"
                        strokeWidth="1.5"
                        strokeDasharray="3 4"
                        className="animate-confirm-circle"
                    />
                    <path
                        d="M17 29 L25 37 L40 20"
                        strokeWidth="2.25"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeDasharray="60"
                        className="animate-confirm-check"
                    />
                </svg>
                <div>
                    <p className="text-xs uppercase tracking-widest text-primary">
                        {isConfirmed ? t('Confirmed') : t('Received')}
                    </p>
                    <Display
                        render={<h2 />}
                        className="mt-0.5 text-2xl font-semibold leading-tight text-foreground"
                    >
                        {isConfirmed
                            ? t("You're booked in.")
                            : t("We've got it.")}
                    </Display>
                </div>
            </div>

            <p className="max-w-[40ch] text-sm leading-relaxed text-secondary-foreground">
                {isConfirmed
                    ? t('A confirmation is on its way to your inbox. See you soon at :business.', { business: businessName })
                    : t("We'll email you as soon as :business confirms.", { business: businessName })}
            </p>

            {/* Hero date block */}
            <div className="flex items-center gap-5 rounded-xl border border-ring bg-honey-soft px-5 py-5">
                <div className="flex flex-col items-center justify-center">
                    <span className="tabular-nums text-xs font-semibold uppercase tracking-widest text-primary">
                        {formatMonthShort(date)}
                    </span>
                    <Display className="tabular-nums text-5xl font-semibold leading-none text-primary-foreground">
                        {formatDay(date)}
                    </Display>
                </div>
                <div className="h-10 w-px bg-ring" aria-hidden />
                <div className="min-w-0">
                    <Display
                        render={<p />}
                        className="text-lg font-semibold leading-tight text-primary-foreground"
                    >
                        {service.name}
                    </Display>
                    <p className="tabular-nums mt-1 text-sm text-primary-foreground opacity-80">
                        {formatDateLong(date)} · {time}
                    </p>
                    {provider && (
                        <p className="mt-0.5 text-xs text-primary-foreground opacity-70">
                            {t('with :name', { name: provider.name })}
                        </p>
                    )}
                </div>
            </div>

            <div className="flex flex-col gap-2">
                <Button
                    variant="ghost"
                    render={<a href={show.url(token)} />}
                    className="h-12 sm:h-12 bg-foreground text-background hover:bg-foreground hover:[filter:brightness(1.15)]"
                >
                    <Display>{t('View booking details')}</Display>
                </Button>
                <Button
                    variant="ghost"
                    size="lg"
                    className="h-11 sm:h-11 text-sm"
                    onClick={onBookAnother}
                >
                    <Display>{t('Book another appointment')}</Display>
                </Button>
            </div>
        </div>
    );
}
