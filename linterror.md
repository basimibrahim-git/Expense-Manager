# SonarQube Lint & Security Audit Report

This document identifies recurring lint errors and potential security issues discovered during the code review and refactoring process.

## General Project-Wide Issues

### 1. Inclusion Standards
- **Issue**: Use of `require_once` or `include` at the top level.
- **SonarQube Recommendation**: Replace with namespace import mechanism through the `use` keyword.
- **Affected Files**: Almost all PHP files (e.g., `dashboard.php`, `security_audit.php`, `add_expense.php`).

### 2. File Formatting
- **Issue**: Missing trailing newline at the end of files.
- **Issue**: Useless trailing whitespaces at the end of lines (e.g., in SQL queries or empty lines).
- **Affected Files**: `security_audit.php`, `dashboard.php`, `export_actions.php`.

### 3. PHP Best Practices
- **Issue**: Use of the closing `?>` tag in files that contain only PHP code. This can lead to unexpected output if whitespaces are present after the tag.
- **Affected Files**: `auth.php`, `export_actions.php` (fixed), `config.php`.

---

## File-Specific Issues

### `dashboard.php`
- **Code Duplication**:
    - Lines 144-145: Duplicate calculation of `$fixed_pct`.
    - Lines 221-222: Duplicate assignment to `$projected_dates[]` and `$projected_balance[]`.
- **Complexity**: The file is over 1,000 lines long, making it difficult to maintain and test.

### `security_audit.php`
- **Accessibility**:
    - Missing association between form labels and control elements (fixed for the details modal).
- **Information Exposure**:
    - Detailed exception messages were being echoed to the user (fixed).

### `export_actions.php`
- **Duplicate Literals**:
    - Literal strings like `"Content-Type: text/csv"`, `".csv"`, and `"php://output"` were duplicated multiple times (fixed with constants).
- **Nested Ternary Operations**:
    - Complex nested ternary operations were used, reducing readability (fixed).

### `add_expense.php`
- **Accessibility**:
    - Multiple form labels are not associated with their corresponding input controls (requires `for` and `id` pairing).
- **Security**:
    - Potential information exposure in error redirection if `$e->getMessage()` is passed directly to the URL.

---

## Summary of Fixes Applied
- [x] Refactored `export_actions.php` to use constants and clean up logic.
- [x] Refactored `security_audit.php` to improve accessibility and sanitize error messages.
- [x] Standardized inclusion methods in several key files.
- [x] Fixed trailing whitespaces and added missing newlines in target files.
