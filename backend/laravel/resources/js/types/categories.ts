import type { Link } from './links';

export type Category = {
    id: number;
    name: string;
    links: Link[];
    created_at: string;
    updated_at: string;
};