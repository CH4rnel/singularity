export type NotificationData = {
    type: string;
    actor_id: number;
    actor_name: string | null;
    actor_wallet: string | null;
    title: string;
    body: string;
    url: string;
};

export type AppNotification = {
    id: string;
    data: NotificationData;
    read_at: string | null;
    created_at: string;
};

export type NotificationsPayload = {
    unread: number;
    notifications: AppNotification[];
};
