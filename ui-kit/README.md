# ASI Finance UI reference kit

Place this entire `ui-kit` folder directly inside your project root: the folder containing `package.json` (Next.js) or `composer.json` and `artisan` (Laravel). Keep it out of `public`.

This is a design reference and optional CSS token file. Copying it into a project does not change the application, install components, load fonts, or automatically make every coding assistant read it.

## Start here

1. Read `UI_GUIDELINES.md` for brand and layout decisions.
2. Read `COMPONENT_PATTERNS.md` for finance screens and states.
3. Use `tokens.css` as a proposed mapping to the existing theme, after inspecting it. Do not blindly import it or replace your global stylesheet.
4. Add approved screenshots to `references/`, then describe exactly what to borrow in `references/README.md`.
5. Ask your coding assistant to read these files explicitly for each UI task, or merge the short pointer below into your existing root agent instructions.

## Optional root instruction pointer

If your coding assistant supports a root `AGENTS.md`, append this section to the existing file, or create it if absent. Do not replace existing project instructions. Other assistants may require their own rules file; the explicit prompt below works without relying on automatic discovery.

```markdown
## UI design reference
For UI tasks, read ui-kit/UI_GUIDELINES.md, ui-kit/COMPONENT_PATTERNS.md,
ui-kit/tokens.css, and ui-kit/references/README.md before editing.
Use approved local screenshots when provided. Preserve existing behavior,
permissions, data, and workflows. Apply the requested page scope only.
User instructions and existing project requirements take precedence.
```

## Copy-paste task prompt

Read ui-kit/README.md, ui-kit/UI_GUIDELINES.md, ui-kit/COMPONENT_PATTERNS.md, ui-kit/tokens.css, and ui-kit/references/README.md first. Inspect the existing project and shared UI components. Apply this brand direction to [PAGE OR COMPONENT] while preserving current routes, fields, validation, calculations, data sources, role permissions, and approval workflows. Reuse existing components and map tokens into the current theme. Do not introduce a new framework or rebuild the application. Check desktop and mobile layouts and the existing relevant validation commands. Report the files changed and what was verified.

Replace [PAGE OR COMPONENT] with a specific target, for example the Finance dashboard. Start with one page, then reuse the established components across the remaining pages.

## Fonts and assets

Cardo and Open Sans font binaries and the company logo are not included. Reuse existing licensed assets or obtain the fonts through your project's approved font setup. The CSS includes fallback stacks but does not load font files. Keep the logo's shape, proportions, and colors intact.
