import { useForm } from '@inertiajs/react';
import { Bell, BellRing, Lock, Music, Play, VolumeX } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import type { TranslationFn } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update as updateRoute } from '@/routes/notification-preferences';

type Channel = 'in_app' | 'sound' | 'email';

interface Preference {
    in_app: boolean;
    sound: boolean;
    email: boolean;
}

interface Props {
    preferences: Record<string, Preference>;
    mandatoryEventTypes: string[];
    configurableEventTypes: string[];
    soundChoices: string[];
    notificationSound: string;
}

interface EventMeta {
    label: string;
    description: string;
}

const EVENT_META: Record<string, EventMeta> = {
    listing_taken: {
        label: 'Listing taken',
        description: 'Someone took your listing — match is starting.',
    },
    match_settled: {
        label: 'Match settled',
        description: 'Your match resolved — won, lost, or draw.',
    },
    match_manual_review: {
        label: 'Match flagged for review',
        description: 'Your match needs admin attention to resolve.',
    },
    dispute_opened: {
        label: 'Dispute opened',
        description: 'You or your opponent reported a problem.',
    },
    cancellation_requested: {
        label: 'Cancellation requested',
        description: 'Your opponent wants to call off the match.',
    },
};

interface EventGroup {
    title: string;
    description: string;
    events: string[];
}

const EVENT_GROUPS: EventGroup[] = [
    {
        title: 'Match activity',
        description: 'Notifications about your matches in progress.',
        events: ['listing_taken', 'match_settled', 'cancellation_requested'],
    },
    {
        title: 'Disputes & moderation',
        description: 'When something needs admin attention to resolve.',
        events: ['dispute_opened', 'match_manual_review'],
    },
];

interface SoundMeta {
    label: string;
    description: string;
    icon: LucideIcon;
}

const SOUND_META: Record<string, SoundMeta> = {
    off: {
        label: 'Off',
        description: 'Mute all notification sounds.',
        icon: VolumeX,
    },
    classic: {
        label: 'Classic',
        description: 'Standard notification chime.',
        icon: Bell,
    },
    soft: {
        label: 'Soft',
        description: 'Subtle and gentle.',
        icon: Music,
    },
    ding: {
        label: 'Ding',
        description: 'Short, attention-grabbing.',
        icon: BellRing,
    },
};

export default function NotificationPreferencesPage({
    preferences,
    mandatoryEventTypes,
    configurableEventTypes,
    soundChoices,
    notificationSound,
}: Props) {
    const t = useT();
    const form = useForm({
        preferences,
        notification_sound: notificationSound,
    });

    useEffect(() => {
        if (form.recentlySuccessful) {
            toast.success(t('Notification preferences saved'));
        }
    }, [form.recentlySuccessful, t]);

    const togglePref = (eventType: string, channel: Channel) => {
        form.setData('preferences', {
            ...form.data.preferences,
            [eventType]: {
                ...form.data.preferences[eventType],
                [channel]: !form.data.preferences[eventType][channel],
            },
        });
    };

    const applyBulk = (prefs: Preference) => {
        const next = configurableEventTypes.reduce<Record<string, Preference>>(
            (acc, eventType) => {
                acc[eventType] = {
                    ...prefs,
                    in_app: mandatoryEventTypes.includes(eventType)
                        ? true
                        : prefs.in_app,
                };

                return acc;
            },
            {},
        );

        form.setData('preferences', next);
    };

    const switchOffAll = () => {
        applyBulk({ in_app: false, sound: false, email: false });
    };

    const emailOnly = () => {
        applyBulk({ in_app: false, sound: false, email: true });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(updateRoute().url, { preserveScroll: true });
    };

    const previewSound = (choice: string) => {
        if (choice === 'off') {
            return;
        }

        new Audio(`/sounds/${choice}.mp3`).play().catch(() => {
            // File missing or autoplay blocked — silently skip.
        });
    };

    const isSoundOff = form.data.notification_sound === 'off';

    return (
        <>
            <PageMeta
                title={t('Notifications — Settings')}
                description={t('Notification preferences.')}
                noindex
            />

            <form onSubmit={handleSubmit} className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 className="font-display text-lg font-semibold text-foreground">
                            {t('Notifications')}
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('Choose how each event reaches you.')}
                        </p>
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
                        <BulkActionButton onClick={switchOffAll}>
                            {t('Switch off all')}
                        </BulkActionButton>
                        <BulkActionButton onClick={emailOnly}>
                            {t('Email only')}
                        </BulkActionButton>
                    </div>
                </div>

                {EVENT_GROUPS.map((group) => (
                    <PreferenceCard
                        key={group.title}
                        title={t(group.title)}
                        description={t(group.description)}
                    >
                        {group.events.map((eventType) => {
                            const meta = EVENT_META[eventType];

                            if (!meta) {
                                return null;
                            }

                            const prefs = form.data.preferences[eventType];
                            const isMandatory =
                                mandatoryEventTypes.includes(eventType);

                            return (
                                <EventRow
                                    key={eventType}
                                    meta={meta}
                                    isMandatory={isMandatory}
                                    prefs={prefs}
                                    onToggle={(channel) =>
                                        togglePref(eventType, channel)
                                    }
                                    soundDisabled={isSoundOff}
                                    t={t}
                                />
                            );
                        })}
                    </PreferenceCard>
                ))}

                <PreferenceCard
                    title={t('Sound')}
                    description={t(
                        'Pick the chime that plays when an event has Sound on.',
                    )}
                >
                    <div
                        role="radiogroup"
                        aria-label={t('Notification sound')}
                    >
                        {soundChoices.map((choice) => {
                            const meta = SOUND_META[choice];

                            if (!meta) {
                                return null;
                            }

                            const isSelected =
                                form.data.notification_sound === choice;

                            return (
                                <SoundRow
                                    key={choice}
                                    meta={meta}
                                    selected={isSelected}
                                    onSelect={() =>
                                        form.setData(
                                            'notification_sound',
                                            choice,
                                        )
                                    }
                                    onPreview={
                                        choice !== 'off'
                                            ? () => previewSound(choice)
                                            : undefined
                                    }
                                    t={t}
                                />
                            );
                        })}
                    </div>
                </PreferenceCard>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing
                            ? t('Saving…')
                            : t('Save preferences')}
                    </Button>
                </div>
            </form>
        </>
    );
}

interface BulkActionButtonProps {
    onClick: () => void;
    children: React.ReactNode;
}

function BulkActionButton({ onClick, children }: BulkActionButtonProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex shrink-0 cursor-pointer items-center rounded-full border border-border/60 bg-card/60 px-3.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors duration-150 hover:border-primary/40 hover:bg-primary/10 hover:text-foreground"
        >
            {children}
        </button>
    );
}

interface PreferenceCardProps {
    title: string;
    description: string;
    children: React.ReactNode;
}

function PreferenceCard({ title, description, children }: PreferenceCardProps) {
    return (
        <div className="overflow-hidden rounded-2xl border border-border/60 bg-card/60">
            <div className="border-b border-border/40 px-5 py-4">
                <h3 className="font-display text-base font-semibold text-foreground">
                    {title}
                </h3>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {description}
                </p>
            </div>

            <div className="divide-y divide-border/40">{children}</div>
        </div>
    );
}

interface EventRowProps {
    meta: EventMeta;
    isMandatory: boolean;
    prefs: Preference;
    onToggle: (channel: Channel) => void;
    soundDisabled: boolean;
    t: TranslationFn;
}

function EventRow({
    meta,
    isMandatory,
    prefs,
    onToggle,
    soundDisabled,
    t,
}: EventRowProps) {
    return (
        <div className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <p className="text-sm font-medium text-foreground">
                        {t(meta.label)}
                    </p>
                    {isMandatory && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span
                                    aria-label={t('Required')}
                                    className="inline-flex"
                                >
                                    <Lock className="size-3 text-muted-foreground" />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>
                                {t(
                                    "Required — affects your money. Can't be silenced.",
                                )}
                            </TooltipContent>
                        </Tooltip>
                    )}
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {t(meta.description)}
                </p>
            </div>

            <div className="flex items-center gap-4 sm:gap-6">
                <ChannelCheckbox
                    label={t('In-app')}
                    checked={prefs.in_app}
                    onChange={() => onToggle('in_app')}
                    disabled={isMandatory}
                    tooltip={
                        isMandatory
                            ? t("Required event — can't be turned off.")
                            : undefined
                    }
                />
                <ChannelCheckbox
                    label={t('Sound')}
                    checked={prefs.sound}
                    onChange={() => onToggle('sound')}
                    disabled={soundDisabled}
                    tooltip={
                        soundDisabled
                            ? t('Sound is off globally.')
                            : undefined
                    }
                />
                <ChannelCheckbox
                    label={t('Email')}
                    checked={prefs.email}
                    onChange={() => onToggle('email')}
                />
            </div>
        </div>
    );
}

interface ChannelCheckboxProps {
    label: string;
    checked: boolean;
    onChange: () => void;
    disabled?: boolean;
    tooltip?: string;
}

function ChannelCheckbox({
    label,
    checked,
    onChange,
    disabled,
    tooltip,
}: ChannelCheckboxProps) {
    const content = (
        <label
            className={cn(
                'inline-flex items-center gap-2 select-none',
                disabled ? 'cursor-not-allowed' : 'cursor-pointer',
            )}
        >
            <Checkbox
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
                aria-label={label}
            />
            <span
                className={cn(
                    'text-sm',
                    disabled ? 'text-muted-foreground' : 'text-foreground',
                )}
            >
                {label}
            </span>
        </label>
    );

    if (tooltip) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex">{content}</span>
                </TooltipTrigger>
                <TooltipContent>{tooltip}</TooltipContent>
            </Tooltip>
        );
    }

    return content;
}

interface SoundRowProps {
    meta: SoundMeta;
    selected: boolean;
    onSelect: () => void;
    onPreview?: () => void;
    t: TranslationFn;
}

function SoundRow({ meta, selected, onSelect, onPreview, t }: SoundRowProps) {
    const Icon = meta.icon;

    return (
        <label
            className={cn(
                'flex cursor-pointer items-center gap-4 px-5 py-4 transition-colors',
                selected ? 'bg-primary/5' : 'hover:bg-primary/5',
            )}
        >
            <input
                type="radio"
                name="notification_sound"
                checked={selected}
                onChange={onSelect}
                className="sr-only"
            />

            <div
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors',
                    selected
                        ? 'bg-primary/15 text-primary'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <Icon className="size-4" />
            </div>

            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">
                    {t(meta.label)}
                </p>
                <p className="text-xs text-muted-foreground">
                    {t(meta.description)}
                </p>
            </div>

            <div className="flex items-center gap-3">
                {onPreview && (
                    <button
                        type="button"
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onPreview();
                        }}
                        aria-label={t('Preview :name', { name: t(meta.label) })}
                        className="inline-flex size-8 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-primary/10 hover:text-primary"
                    >
                        <Play className="size-4" />
                    </button>
                )}
                <span
                    aria-hidden
                    className={cn(
                        'relative inline-flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors',
                        'after:absolute after:rounded-full after:transition-all after:duration-150',
                        selected
                            ? 'border-primary bg-primary/10 after:size-2 after:bg-primary'
                            : 'border-border bg-card/60 after:size-0',
                    )}
                />
            </div>
        </label>
    );
}
