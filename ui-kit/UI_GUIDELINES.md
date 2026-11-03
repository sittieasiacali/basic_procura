# ASI PROCURA UI guidelines

## Direction

A clean corporate procurement dashboard with a navy horizontal navigation bar, Baby Powder workspace, readable operational tables, and restrained Cardo headings. These are proposed design rules based on the supplied brand palette. They guide presentation; they do not define business logic.

## Exact brand colors

| Name | Hex | RGB | Intended use |
| --- | --- | --- | --- |
| Navy Blue | #160D76 | 22, 13, 118 | Sidebar, primary actions, headings, text links |
| Tufts Blue | #4094D9 | 64, 148, 217 | Chart series, informational accents, selected indicators |
| Cadmium Orange | #F08530 | 240, 133, 48 | Recommended quotation accents and limited emphasis |
| Baby Powder | #FFFFFA | 255, 255, 250 | Main background and light text on Navy |
| Eerie Black | #222222 | 34, 34, 34 | Body text, amounts, form labels |

Use predominantly light surfaces. Navy provides structure; blue and orange are restrained accents. Derived tints and neutral borders are supporting UI colors, not additional official brand colors.

## Approved compact direction

The existing horizontal, role-aware navigation is the approved navigation model; do not introduce a sidebar as part of this direction. Keep dashboard headers compact, show account/workspace metadata once, and use slim KPI cards with subtle borders and restrained shadows. This compact direction takes precedence over earlier sidebar or large-card suggestions in this kit.

Use Baby Powder text on solid Navy. Use Eerie Black text on solid Tufts Blue or Orange. Do not use orange or blue for small body text on a light background. Validate the actual rendered text, focus, and control contrast when integrating. Status badges must contain a label or icon as well as color. Retain existing semantic success/error colors when needed; brand colors must not obscure meaning.

## Typography

- Cardo Bold: page titles (28–32px desktop, 24–28px mobile), section and dialog headings (20–24px).
- Open Sans: navigation, body, inputs, buttons, table headers, badges, and numeric metrics.
- Body: 14–16px, line-height about 1.5. Do not shrink dense tables below readable size to fit more columns.
- Use tabular numerals for amounts; align numeric columns right and labels left.
- Preserve existing currency, locale, precision, date formatting, and financial rounding logic.
- Preserve user zoom and text scaling. Avoid fixed heights that clip wrapped content.

## Layout

- Desktop: approximately 240–256px sidebar; content padding 24–32px.
- Mobile: collapsible navigation, 16px content padding, stacked forms and cards.
- Use a 4px spacing scale: 4, 8, 12, 16, 24, 32, 48.
- Corners: 8px for controls and 12px for cards/dialogs.
- Prefer subtle borders and restrained shadows; avoid decorative gradients and glass effects on operational forms or tables.
- Page header: compact title, one short supporting line if useful, and the existing main action. Do not repeat workspace metadata already shown nearby.
- Keep filters together above the relevant table. Put detailed information in the details view instead of duplicating it in multiple cards.
- Tables can scroll horizontally inside their own region on small screens; the whole page must not overflow.

## Scope and integration

Inspect the existing stack, stylesheet, shared components, and project instructions first. Reuse the current component library. A screenshot is inspiration for visual treatment, not authority to change fields or workflow. Do not change backend logic, authentication, authorization, routes, API contracts, database schema, records, validation, exports, or calculations as part of styling.

Do not install shadcn, React, Tailwind, or another framework merely because a reference uses it. These guidelines can be implemented in Next.js, Laravel, PHP, or the project's existing stack.

No full application redesign unless explicitly requested. Adapt an existing dark theme separately; these tokens describe light mode only and must not overwrite dark-mode rules.

## Review before finishing

Check readable text; visible keyboard focus; labeled inputs and errors; loading, empty and error states; long supplier names and large amounts; narrow screens and text zoom; unchanged visible actions per role; and the existing relevant build/lint checks. Confirm what was actually verified and identify anything untested.
