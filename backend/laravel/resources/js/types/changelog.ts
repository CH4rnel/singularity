export type ChangelogSection = {
    label: string;
    items: string[];
};

export type ChangelogRelease = {
    version: string;
    date: string;
    title: string;
    sections: ChangelogSection[];
};

export type AppRelease = {
    current: {
        version: string;
    };
    recent: ChangelogRelease[];
    changelogUrl: string;
};
