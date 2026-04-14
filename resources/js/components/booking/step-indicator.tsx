import { useTrans } from '@/hooks/use-trans';
import { Button } from '@/components/ui/button';

interface StepIndicatorProps {
    current: number;
    total: number;
    stepLabel: string;
    onBack?: () => void;
}

export default function StepIndicator({ current, total, stepLabel, onBack }: StepIndicatorProps) {
    const { t } = useTrans();
    const progress = current / total;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-4">
                <div className="tabular-nums text-xs uppercase tracking-widest text-muted-foreground">
                    <span className="text-secondary-foreground">
                        {String(current).padStart(2, '0')}
                    </span>
                    <span className="mx-1.5 text-rule-strong">/</span>
                    <span>{String(total).padStart(2, '0')}</span>
                    <span className="ml-3 inline-block align-middle">·</span>
                    <span className="ml-3">{stepLabel}</span>
                </div>
                {onBack && (
                    <Button
                        variant="link"
                        size="xs"
                        className="text-muted-foreground hover:text-foreground"
                        onClick={onBack}
                    >
                        ← {t('Back')}
                    </Button>
                )}
            </div>
            <div className="relative h-[2px] w-full overflow-hidden rounded-full bg-border">
                <div
                    className="absolute inset-y-0 left-0 rounded-full bg-primary transition-[width] duration-500 ease-[cubic-bezier(0.2,0.8,0.2,1)]"
                    style={{ width: `${progress * 100}%` }}
                />
            </div>
        </div>
    );
}
