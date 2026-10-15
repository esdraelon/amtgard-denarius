# Contributing

## Git commit identity

Commits on `esdraelon/amtgard-denarius` should use the **`esdraelon`** GitHub account identity so history links correctly on GitHub.

In this repository (local config only):

```bash
git config user.name "Megiddo sel Esdraelon"
git config user.email "megiddo@amtgard.com"
```

Use an email [verified on the `esdraelon` account](https://github.com/settings/emails), or GitHub’s noreply form: `7896847+esdraelon@users.noreply.github.com`.

Before opening pull requests, activate the matching GitHub CLI user:

```bash
gh auth switch --user esdraelon
```

**October 2026:** `main` history was rewritten once to replace accidental `inoahsmi@amazon.com` author metadata with the identity above. No source files changed—only commit SHAs. After that update, reset local `main` with `git fetch origin && git reset --hard origin/main` and restack any local branches.
