# 💰 Expense Manager

![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1.svg)

**Expense Manager** is a premium, web-based financial tracking application designed to help you manage your personal finances with elegance and precision. Built with modern PHP and a glassmorphism-inspired UI, it provides a comprehensive 360-degree view of your wealth, spending habits, credit card portfolio, and spiritual obligations.

---

## ✨ Key Features

### 📊 Interactive Dashboard
- **Real-Time Overview**: Instant access to Income, Expenses, Net Worth, and Credit Limits.
- **Visual Analytics**: Interactive charts for spending breakdown (Doughnut), monthly trends (Line), and wealth journey monitoring.

### 💳 Smart Finance Management
- **Card Portfolio**: Visual wallet for credit/debit cards with "Best Card" recommendations for specific spend categories (e.g., Dining, Fuel).
- **Bank Balances**: Net worth engine that aggregates assets across multiple bank accounts.
- **Liquidity Gauge**: Calculates "True Liquidity" by subtracting unbilled credit card expenses from cash on hand.

### 🤲 Islamic Finance Modules
- **Zakath Tracker**: Smart calculator that pulls bank balances and gold/silver rates to compute and track 2.5% Zakath obligations.
- **Sadaqa Tracker**: Log and track voluntary charity donations.
- **Interest Purifier**: Separate tracking for interest income to ensure ethical disposal.

### 💼 Professional Tools
- **Company Tracker**: Monitor annual incentives, bonuses, and sales performance grids.
- **Lending Tracker**: Keep track of money lent to friends and family with repayment status.

### 📱 Mobile & PWA Ready
- **Installable Web App**: Native-like experience on iOS and Android with offline fallback.
- **Responsive Design**: Mobile-first glassmorphism UI that adapts to any screen size.

---

## 🛠️ Technology Stack

This application is built with a robust, standard LAMP/LEMP stack:

- **Backend**:
  - **PHP 8.0+**: Core logic, PDO for database interactions.
  - **MySQL**: Relational database for storing financial data.
- **Frontend**:
  - **HTML5 & CSS3**: Semantic markup with modern CSS variables.
  - **JavaScript (ES6+)**: Dynamic interactions and AJAX handling.
  - **Chart.js**: Data visualization.
  - **Bootstrap 5**: Responsive layout framework.
- **Security**:
  - **CSRF Protection**: Token-based security for all forms.
  - **Input Sanitization**: Protection against XSS and SQL injection.
  - **Environment Variables**: Secure credential management via `.env`.

---

## 📂 Application Structure & Modules

The application is modularized for maintainability:

- **`/includes`**: Reusable UI components.
  - `header.php`: Navigation, meta tags, and CSS inclusions.
  - `sidebar.php`: Main navigation menu for desktop and mobile.
  - `footer.php`: Scripts and closing tags.
- **`/assets`**: Static resources.
  - `/css`: Custom styles (`style.css`) and frameworks.
  - `/js`: Frontend logic and Service Worker (`sw.js`).
- **Core Trackers**:
  - `expenses.php` / `income.php`: Core financial logging.
  - `zakath_tracker.php`: Religious obligation calculation.
  - `company_tracker.php`: Professional income tracking.
  - `my_cards.php`: Credit card management system.

---

## 🚀 Installation Guide

### Prerequisites
- A PHP-supported web server (Apache/Nginx).
- MySQL Database.
- Composer (optional, for future dependencies).

### ⚡ Quick Installation (Automated)

1.  **Clone the Repository**
    ```bash
    git clone https://github.com/yourusername/expense-manager.git
    cd expense-manager
    ```

2.  **Run the Installer**
    - Navigate to `http://localhost/expense-manager/install/install.php`
    - Follow the 4-step wizard to:
        - Connect to your Database
        - Create the `.env` configuration file automatically
        - Set up all database tables
        - Create your Admin Account

3.  **That's it!**
    - The installer will automatically delete itself for security.
    - You will be redirected to your dashboard.

---

## 🔒 Security & Logs

- **Application Logs**: Errors are logged to `error.log` in the root directory (as configured in `config.php`). Ensure this file is not accessible publicly via `.htaccess` or server config in production.
- **CSRF Tokens**: Every form submission is protected by a unique session token.
- **Session Management**: Secure session handling to prevent unauthorized access.

---

## 📜 License

This project is licensed under the MIT License - see the `LICENSE` file for details.