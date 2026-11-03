# ASI PROCURA component patterns

## Navigation and actions

Use Navy for the existing horizontal navigation with Baby Powder labels. A selected item can use a restrained orange underline or a subtle light-blue treatment; keep its text readable. Preserve active-route behavior, navigation semantics, keyboard access, and mobile collapse behavior.

Use a Navy primary button with light text, a bordered secondary button, and the existing semantic danger style for destructive actions. Keep a visible focus ring, loading feedback, and genuine disabled states. Never remove backend permission checks because an action is hidden.

## Dashboard

Suggested composition: compact role-aware overview header, its existing primary action, current authorized KPI metrics, Requests Awaiting Action, recent requests, and only the quick links already authorized for that role.

Use the controller's existing role-specific metric definitions. Where six metrics are supplied, show six slim cards in one row at sufficient width and wrap naturally on smaller screens. Do not fabricate budgets, spending, or other unsupported figures.

Keep urgent work visible near the top. Card values use Open Sans; the page title uses Cardo. Charts need labels and a readable summary or accessible data equivalent.

## Tables and filters

Use aligned labels and values, restrained dividers, a distinct header row, and a clear hover/focus treatment. Keep search and related filters in one toolbar, with Clear filters when active. Preserve pagination, sorting, selections, and filter behavior.

Never make a whole row the only way to open a record: keep an accessible record link. Avoid nested click targets. Use labeled action menus for secondary actions. Keep dates and currency readable and preserve current formatting.

## Forms and dialogs

Use persistent labels, required indicators, field-level errors, and clear submit feedback. Arrange related fields in sections. Use two columns only where there is enough width; stack on mobile. Keep long forms scrollable and their actions reachable. Preserve draft behavior, validation, and existing submitted values. Existing confirmation and dialog focus behavior must keep working.

## Quotation comparison

Show request details once, followed by comparable supplier quotations and a short recommendation summary. Highlight the existing recommended quotation with a pale orange tint, orange border, and a text badge. Emphasize its price and the existing documented recommendation reason.

Recommended is not necessarily cheapest. Use the system's existing recommendation flag or rule. Do not invent a winner, change the calculation, or claim savings unless comparable data and an established calculation support it. Preserve approval state and currency distinctions.

## Purchase orders and approvals

Keep View, Export, and approval actions visually distinct where they already exist. Style export actions without changing print/PDF behavior. Keep status, authorized next action, and existing approval history easy to scan. Do not add, merge, skip, or rename workflow stages as a design task. Render unauthorized views consistently with existing permission rules.

## Status and feedback

Pair each status color with explicit text. Keep pending, approved, declined, overdue, and paid distinguishable according to the current system. Empty views explain why no records appear and show a permitted next action. Error views retain user input and provide an available recovery action. Loading placeholders should resemble the final layout without inventing data.
