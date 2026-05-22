# Code Comments Guide

This project uses a consistent commenting style so you (and FYP examiners) can navigate the codebase quickly.

## PHP file header (top of every `.php` file)

```php
/**
 * @file path/to/file.php
 * @brief Short title — what this file does in one line
 * @access public | student | admin | warden | system | layout
 * @database table1, table2   (optional)
 */
```

Added automatically by `tools/annotate_project.php`. Re-run after adding new PHP pages:

```bash
C:\xampp\php\php.exe tools\annotate_project.php
```

(Skips files that already contain `@file`.)

## Section comments (inside larger files)

```php
// ---------------------------------------------------------------------------
// Section name — brief explanation
// ---------------------------------------------------------------------------
```

Used in `includes/app.php`, `login.php`, models, and JavaScript.

## Function docblocks

Models and services use PHPDoc for parameters and return types:

```php
/** @return list<array<string,mixed>> */
function example(PDO $pdo, int $id): array
```

## Where to look first

| If you need… | Open |
|--------------|------|
| Sessions, login, CSRF | `includes/app.php` |
| Database connection | `config/db.php` |
| Student hostel apply | `student/apply.php` + `includes/models/student_model.php` |
| AI room ranking | `includes/services/recommendation_service.php` + `ai_service/app.py` |
| Admin analytics | `admin/dashboard.php` |
| Navigation menus | `includes/layout/sidebar.php` |

## Python AI service

`ai_service/app.py` has a module docstring at the top and inline comments on routes `/health` and `/recommend`.

## CSS

- `assets/css/style.css` — dashboards and shared layout
- `assets/css/landing.css` — public marketing page only
- `assets/css/components.css` — toasts, chat UI, empty states
