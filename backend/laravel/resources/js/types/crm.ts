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

export type CrmTaskStatus = 'open' | 'in_progress' | 'done' | 'cancelled';
export type CrmTaskPriority = 'low' | 'normal' | 'high';

export type CrmTask = {
    id: number;
    crm_contact_id: number | null;
    assigned_to_user_id: number | null;
    created_by_user_id: number | null;
    title: string;
    description: string | null;
    status: CrmTaskStatus;
    priority: CrmTaskPriority;
    due_at: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    assignee?: Pick<User, 'id' | 'name'> | null;
    contact?: Pick<CrmContact, 'id' | 'name' | 'email'> | null;
};

/** Operator a task can be handed to — the CRM allow list, resolved to users. */
export type CrmAssignee = {
    id: number;
    name: string;
    wallet_address?: string | null;
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
    tasks?: CrmTask[];
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

export type { Paginated } from './pagination';
