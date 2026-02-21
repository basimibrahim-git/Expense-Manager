# 💰 Expense Manager

![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1.svg)

**Expense Manager** is a premium, web-based financial tracking application designed to help families and individuals manage their finances with elegance and precision. Built with modern PHP and a glassmorphism-inspired UI, it provides a comprehensive 360-degree view of your wealth, spending habits, and spiritual obligations, all while supporting a robust multi-tenant architecture.

---

## 🚀 Multi-Tenant Family Architecture
The system has been upgraded to support **tenant isolation**, allowing multiple families to use the same platform independently.
- **Family Accounts**: All data (expenses, cards, goals) is strictly isolated to your family.
- **Member Management**: Family Admins can add members, assign roles, and manage permissions.
- **Root Admin Suite**: A dedicated system administration dashboard for managing family accounts (tenants), renaming families, and monitoring system-wide performance.

---

## ✨ Key Features

### 📊 Interactive Dashboard
- **Real-Time Overview**: Instant access to Income, Expenses, Net Worth, and Credit Limits.
- **Visual Analytics**: Interactive charts for spending breakdown (Doughnut), monthly trends (Line), and wealth journey monitoring.
- **Dual-Currency Switcher**: Toggle your entire dashboard between **AED** and **INR** instantly.

### 🎯 Proactive Budgeting & Planning
- **Budgeting Engine**: Set monthly spending limits per category with visual progress bars.
- **Variance Analysis**: Real-time tracking of actual vs. budgeted spending with "Safe to Spend" insights.
- **Upcoming Bills**: Smart widget that scans active subscriptions and alerts you to renewals due.

### 💳 Smart Finance Management
- **Card Portfolio**: Visual wallet for credit/debit cards with "Best Card" recommendations based on categories.
- **Bank Balances**: Net worth engine that aggregates assets across multiple bank accounts.
- **Liquidity Gauge**: Calculates "True Liquidity" by subtracting unbilled credit card expenses from cash on hand.

### 🤲 Islamic Finance Modules
- **Zakath Tracker**: Smart calculator that pulls bank balances to compute and track 2.5% Zakath obligations.
- **Sadaqa Tracker**: Log and track voluntary charity donations.
- **Interest Purifier**: Separate tracking for interest income to ensure ethical disposal.

### 📈 Advanced Reporting
- **YoY Analysis**: Compare spending habits between years with granular category growth/reduction trends.
- **Spending Intensity**: Visual heatmap tracking spending frequency by Month vs. Day of Week.
- **Professional Statements**: Dedicated print-optimized statement engine for monthly summaries.

### 📂 Premium UI & Navigation
- **Collapsible Sidebar**: Reorganized, category-based navigation (Core, Tools, Trackers, Admin) to reduce clutter.
- **Dark Mode**: Premium, high-contrast dark theme with browser-level persistence.
- **Responsive Design**: Mobile-first glassmorphism UI that adapts to any screen size.

---

## 🛠️ Technology Stack

- **Backend**: PHP 8.0+, MySQL (PDO).
- **Frontend**: HTML5, CSS3 (Variables), ES6+ JavaScript, Chart.js, Bootstrap 5.
- **Architecture**:
  - **Tenant Isolation**: Column-level filtering ensuring zero data leakage between families.
  - **RBAC**: Role-Based Access Control (Root Admin, Family Admin, Standard User).
- **Security**:
  - **Multi-Tenant Audit Logs**: Comprehensive tracking of all actions with performer attribution and IP logging.
  - **CSRF Protection**: Token-based security for all actions.
  - **Input Sanitization**: Native protection against XSS and SQL injection.

---

## 📂 Application Structure

- **`/admin`**: Root Administration suite for tenant and family management.
- **`/includes`**: Reusable components (`sidebar.php`, `header.php`, `audit_helper.php`).
- **`/assets`**: Modern styles and Service Worker for PWA capabilities.
- **Core Trackers**: `expenses.php`, `income.php`, `zakath_tracker.php`, `my_cards.php`, `security_audit.php`.

---

## 🚀 Installation Guide

### ⚡ Quick Installation (Automated)

1.  **Clone & Configure**
    ```bash
    git clone https://github.com/basimibrahim-git/Expense-Manager.git
    cd Expense-Manager
    ```

2.  **Run the Installer**
    - Navigate to `http://your-server/Expense-Manager/install/install.php`
    - Follow the wizard to connect the database and create your **Root Admin** account.

3.  **Create Families**
    - Log in as Root Admin to create families and manage tenants.

---

## 📜 License
This project is licensed under the MIT License.
