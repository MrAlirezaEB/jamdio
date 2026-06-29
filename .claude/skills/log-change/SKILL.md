---
name: log-change
description: Generate a versioned changelog markdown file in .changelogs/ describing the feature or changes just implemented. Use when the user runs /log-change or asks to log/record a change after implementing a feature.
---

# Log Change

Create a versioned changelog entry in the `.changelogs/` folder that describes the changes that were just made.

## Steps

1. **Gather the changes.** Determine what actually changed by inspecting the working tree and recent history. Run these in parallel:
   - `git status --short` — staged + unstaged files
   - `git diff` and `git diff --staged` — the actual content changes
   - If the working tree is clean, fall back to the most recent commit: `git show --stat HEAD` and `git log -1 --format='%H %s'`.

   If there are no changes at all (clean tree and nothing meaningful in the last commit), tell the user there is nothing to log and stop.

2. **Determine the next version.** Read the existing files in `.changelogs/` to find the latest version:
   - List `.changelogs/*.md`. Filenames follow the pattern `vMAJOR.MINOR.PATCH.md` (e.g. `v0.1.0.md`).
   - If the folder is empty or missing, start at `v0.1.0`.
   - Otherwise pick the highest existing version and increment it using semantic versioning based on the nature of the change:
     - **PATCH** (`v0.1.0` → `v0.1.1`): bug fixes, refactors, small tweaks, docs.
     - **MINOR** (`v0.1.0` → `v0.2.0`): a new feature or backward-compatible capability.
     - **MAJOR** (`v0.1.0` → `v1.0.0`): a breaking change.
   - When unsure between MINOR and PATCH, treat a newly implemented feature as MINOR. If the user passed an explicit version or bump type as an argument (e.g. `/log-change patch` or `/log-change v1.2.0`), honor it.

3. **Write the changelog file** at `.changelogs/<version>.md` using the template below. Group changes under the relevant headings; omit any section that has no entries. Be concrete — name the files, classes, endpoints, or behaviors that changed and explain *what* and *why*, not just *that* something changed.

4. **Report** the created file path and the version to the user, with a short summary of what was logged.

## Template

```markdown
# <version> — <short title of the change>

**Date:** <YYYY-MM-DD>

## Summary

<One or two sentences describing the overall change.>

## Added

- <New features / files / endpoints>

## Changed

- <Modified behavior / refactors>

## Fixed

- <Bug fixes>

## Removed

- <Deleted features / files>

## Files touched

- `path/to/file` — <what changed>
```

## Notes

- Use today's date for the `Date` field.
- Keep entries written for a human reader skimming the project history — clear, specific, no filler.
- Create the `.changelogs/` directory if it does not exist.
- One file per `/log-change` invocation; never overwrite an existing version file — always bump to a new version.
