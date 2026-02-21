# Security Policy

## Supported Versions

We actively monitor and provide security updates for the following versions of **Expense Manager**. If you are using an unsupported version, please upgrade to ensure your data remains protected.

| Version | Supported          |
| ------- | ------------------ |
<<<<<<< HEAD
| 3.1.x   | :white_check_mark: |
=======
| 3.0.x   | :white_check_mark: |
>>>>>>> 6be4e7069e3e3f9313aceb7dd9058fa47675e294
| 2.0.x   | :x:                |
| < 1.0   | :x:                |

> **Note:** As this is an active project, we generally only support the latest major release.

## Reporting a Vulnerability

We take the security of your financial data seriously. If you discover a security vulnerability within this project, please follow these steps:

### How to report
1.  **Do not open a public GitHub issue.** This helps prevent the exploit from being used before a fix is ready.
2.  create a issue ticket in Github with subject `SECURITY VULNERABILITY - Expense Manager`.
3.  Include a detailed description of the vulnerability, steps to reproduce it, and any potential impact.

### What to expect
* **Response:** You will receive an acknowledgment of your report within **48 hours**.
* **Updates:** We will provide status updates at least once every **3 days** while we investigate and work on a fix.
* **Disclosure:** Once a fix is deployed, we will coordinate a public disclosure and credit you for the discovery (if you wish to be named).

## Security Best Practices
As an **Expense Manager** user/developer, we recommend:
* Never hardcoding API keys in the source code.
* Using environment variables (`.env`) for sensitive configurations.
* Regularly updating dependencies via `npm update` or `pip install --upgrade`.
