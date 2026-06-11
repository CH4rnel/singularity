# Releases

Singularity should publish small, regular tagged releases. For a token-facing public project, releases are not just packaging; they are an auditable activity log.

## Tag Format

Use date-based tags:

```text
vYYYY.MM.DD
```

If multiple releases happen on one day, append a short suffix:

```text
vYYYY.MM.DD-2
```

## Release Checklist

1. Make sure CI is green on the release commit.
2. Update `CHANGELOG.md`: move completed items from `Unreleased` to a new dated section.
3. Verify any deployed URLs or contract addresses mentioned in the notes.
4. Create and push the tag:

```bash
git tag vYYYY.MM.DD
git push origin vYYYY.MM.DD
```

5. Create a GitHub release from the tag and paste the changelog section into the release notes.

## Good Release Notes

Release notes should answer three questions:

- What changed for users?
- What changed for developers/operators?
- What is still incomplete or trust-sensitive?

For bridge, token, or contract releases, include addresses, explorer links, owner/relayer assumptions, and migration notes.
