import type { Locale } from '@/composables/useLocale';

/**
 * Copy shipped straight from config/gamification.php. English is the only
 * language promised: a quest added in one language still renders, and a
 * language added here still renders before the config has caught up.
 */
export type LocalizedText = { en: string } & Partial<Record<Locale, string>>;

export type Quest = {
    key: string;
    period: 'daily' | 'weekly';
    title: LocalizedText;
    description: LocalizedText;
    target: number;
    progress: number;
    completed: boolean;
    xp: number;
};

export type XpEntry = {
    source: string;
    amount: number;
    created_at: string | null;
};

/** Public standing, safe to render on anyone's profile. */
export type PublicProgress = {
    xp: number;
    level: number;
    title: string;
    current_streak: number;
    longest_streak: number;
    rank: number | null;
};

/** What a level has actually unlocked, keyed by perk name. */
export type Perks = { crosschain_fee_discount?: number };

/** The signed-in user's own progress, as served to /profile. */
export type Progress = PublicProgress & {
    /**
     * XP the chain vouched for, and the level built on it. Perks read this
     * rather than `xp`, because `visit` XP is credited on the browser's word
     * and a level that discounts a fee cannot be earned by opening pages.
     */
    proven_xp: number;
    proven_level: number;
    perks: Perks;
    next_perk: { level: number; xp: number; perks: Perks } | null;
    level_floor_xp: number;
    /** Cumulative XP for the next level; null at max level. */
    next_level_xp: number | null;
    progress_pct: number;
    last_active_on: string | null;
    active_today: boolean;
    quests: Quest[];
    recent: XpEntry[];
};

export type LeaderboardRow = {
    position: number;
    user_id: number;
    name: string | null;
    avatar: string | null;
    profile_url: string;
    wallet_address: string | null;
    xp: number;
    level: number;
    title: string;
    current_streak: number;
};
