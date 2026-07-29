import type { Locale } from '@/composables/useLocale';

/** Bilingual copy shipped straight from config/gamification.php. */
export type LocalizedText = Record<Locale, string>;

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

/** The signed-in user's own progress, as served to /profile. */
export type Progress = PublicProgress & {
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
    wallet_address: string | null;
    xp: number;
    level: number;
    title: string;
    current_streak: number;
};
