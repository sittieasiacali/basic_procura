# Request categories

The request form, create/update validation, and title generation read `request_categories` directly. SQL changes appear on the next page load; no asset build or cache clear is needed.

Run `php artisan migrate` after deploying this change. The migration preserves the twelve original options and their team/order. It does not change existing purchase requests.

In phpMyAdmin, select the application's database and run:

```sql
INSERT INTO request_categories (team, code, label, sort_order)
VALUES ('IT', 'FOR_TEST', 'FOR test', 100)
ON DUPLICATE KEY UPDATE label = 'FOR test', sort_order = 100;
```

Use `PREMISES` instead of `IT` to add an option to Premises. Codes must be unique within each team. Keep codes stable because saved requests store the code. Edit `label` to change display text and `sort_order` to change ordering. SQL label edits do not rewrite existing request titles; saving a request derives its title from the current category label.

Deleting a category removes it from the dropdown and future validation. Keep categories referenced by existing requests so those requests can still be edited with their original category.
