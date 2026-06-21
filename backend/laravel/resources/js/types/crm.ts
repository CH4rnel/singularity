import type { User } from './auth';

export type CrmContactType = 'lead' | 'holder' | 'whale';
export type CrmContactStatus =
    | 'new'
    | 'contacted'
    | 'qualified'
    | 'customer'
    | 'lost';
export type CrmContactSource = 'manual' | 'platform' | 'bridge' | 'whale_bot';

export type CrmNote = {
    id: number;
    crm_contact_id: number;
    user_id: number | null;
    type: string;
    body: string;
    created_at: string;
    updated_at: string;
    author?: User | null;
};

export type CrmContact = {
    id: number;
    name: string | null;
    email: string | null;
    telegram: string | null;
    evm_address: string | null;
    solana_address: string | null;
    type: CrmContactType;
    status: CrmContactStatus;
    source: CrmContactSource;
    user_id: number | null;
    cyber_balance: string | null;
    cyber_sol_balance: string | null;
    tags: string[] | null;
    metadata: Record<string, unknown> | null;
    last_synced_at: string | null;
    created_at: string;
    updated_at: string;
    notes_count?: number;
    notes?: CrmNote[];
    user?: User | null;
};

export type CrmBridgeActivity = {
    id: number;
    direction: string;
    token: string;
    amount: string;
    status: string;
    created_at: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
