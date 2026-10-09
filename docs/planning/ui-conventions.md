# Denarius UI conventions (Twig)

Server-rendered pages use **Twig 3**, **Bootstrap 5** (CDN), and **Font Awesome**. Typography matches Amtgard IDP: Instrument Serif for headings, Roboto Condensed for body. Primary actions use Bootstrap `--bs-primary` (`#3498db`); heading accent colors use `.text-denarius-secondary` and `.text-denarius-tertiary` in `base.twig`.

## Directory layout

| Path | Purpose |
|------|---------|
| `templates/base.twig` | Document shell, Bootstrap, global CSS variables |
| `templates/macros.twig` | Small reusable **HTML fragments** (buttons, one-line fields) |
| `templates/partials/` | Layout fragments without a fixed API (e.g. `brand.twig`) |
| `templates/components/` | Named UI pieces with documented variables/blocks |
| `templates/layout/` | Reserved for future page shells (nav chrome, etc.) |
| `templates/connect/` | Provider-specific enrollment snippets (included from manage) |

## Macros vs components vs partials

**Macros** (`{% import "macros.twig" as ui %}`) — use for controls that appear inline many times and have no inner block: `ui.btn_primary`, `ui.submit`, `ui.btn_login_with_amtgard`. Keep macros limited to short markup; do not put whole sections in macros.

**Components** (`templates/components/*.twig`) — use for repeated structure with optional **embed blocks** or explicit `include` variables:

- `include` when the component is a leaf (alert, form select, data table with simple fields).
- `embed` when the page must supply a block (`section-card` → `section_body`, `page-header` → `header_actions` / `header_meta`).

**Partials** — use for branding or ad hoc layout that pages override via `{% block %}` inside `{% embed %}` (see `partials/brand.twig` on home).

## Include paths

Always use paths relative to the Twig loader root (`templates/`), without a leading slash:

```twig
{% include 'components/alert.twig' with { variant: 'warning', message: '…' } %}
{% embed 'components/section-card.twig' with { title: 'Settings' } %}
    {% block section_body %}…{% endblock %}
{% endembed %}
```

## Page width

Override `container_class` on `base.twig`:

- Default: `container` (~960px cap via Bootstrap).
- Manage / admin: `container-lg`.
- Kingdom statements: `container-xl`.

## Data tables

Prefer `components/data-table.twig` when rows are uniform:

- Pass `columns` (headers) and `rows` plus `fields` (property names on each row object).
- Use inline `<table class="table …">` only when cells need custom markup (e.g. admin granted-permissions email column).

## CSS toolkit choice

This spike **replaced Tailwind CDN with Bootstrap 5** so grid, forms, tables, and alerts stay consistent without maintaining parallel utility class strings. IDP colors are mapped via `:root` and `.text-denarius-*` helpers rather than overloading Bootstrap’s semantic `secondary` color.

## Out of scope for this doc

Connect provider scripts, admin typeahead JS, and enrollment POST flows are unchanged in behavior; only markup/classes were normalized where templates were touched.

## Follow-up

- `privacy-policy.twig` still uses pre-Bootstrap utility class names in the body; migrate when that page is next edited.
