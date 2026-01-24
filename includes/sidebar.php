<!-- Sidebar -->
<nav class="sidebar">
    <div class="brand-logo">
        <i class="fa-solid fa-wallet"></i> ExpenseMngr
    </div>

    <div class="sidebar-nav">
        <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-house"></i> Dashboard
        </a>
        <a href="my_cards.php"
            class="nav-link <?php echo ($current_page == 'my_cards.php' || $current_page == 'add_card.php' || $current_page == 'edit_card.php' || $current_page == 'view_card.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-credit-card"></i> My Cards
        </a>

        <a href="expenses.php"
            class="nav-link <?php echo ($current_page == 'expenses.php' || $current_page == 'add_expense.php' || $current_page == 'monthly_expenses.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-receipt"></i> Expenses
        </a>
        <a href="income.php"
            class="nav-link <?php echo ($current_page == 'income.php' || $current_page == 'add_income.php' || $current_page == 'monthly_income.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-sack-dollar"></i> Income
        </a>
        <a href="bank_balances.php"
            class="nav-link <?php echo ($current_page == 'bank_balances.php' || $current_page == 'add_balance.php' || $current_page == 'monthly_balances.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-building-columns"></i> My Banks
        </a>
        <a href="subscriptions.php"
            class="nav-link <?php echo ($current_page == 'subscriptions.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-repeat"></i> Subscriptions
        </a>
        <a href="budget.php" class="nav-link <?php echo ($current_page == 'budget.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-pie"></i> Budget
        </a>
        <a href="goals.php" class="nav-link <?php echo ($current_page == 'goals.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-bullseye"></i> Goals
        </a>
        <a href="calendar.php" class="nav-link <?php echo ($current_page == 'calendar.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-days"></i> My Calendar
        </a>

        <?php
        $tracker_pages = [
            'company_tracker.php', 'monthly_incentives.php',
            'zakath_tracker.php', 'zakath_calculator.php',
            'interest_tracker.php', 'monthly_interest.php',
            'sadaqa_tracker.php', 'monthly_sadaqa.php',
            'lending_tracker.php'
        ];
        $is_tracker_active = in_array($current_page, $tracker_pages);
        ?>
        <a class="nav-link d-flex justify-content-between align-items-center <?php echo $is_tracker_active ? '' : 'collapsed'; ?>" 
           data-bs-toggle="collapse" href="#trackersMenu" role="button" aria-expanded="<?php echo $is_tracker_active ? 'true' : 'false'; ?>">
            <span><i class="fa-solid fa-toolbox"></i> Special Trackers</span>
            <i class="fa-solid fa-chevron-down small"></i>
        </a>
        <div class="collapse <?php echo $is_tracker_active ? 'show' : ''; ?>" id="trackersMenu">
            <div class="ps-3 border-start ms-3 border-2 mb-2">
                <a href="company_tracker.php" class="nav-link py-1 <?php echo ($current_page == 'company_tracker.php' || $current_page == 'monthly_incentives.php') ? 'active text-primary' : 'text-muted'; ?>">
                    Incentive Tracker
                </a>
                <a href="zakath_tracker.php" class="nav-link py-1 <?php echo ($current_page == 'zakath_tracker.php' || $current_page == 'zakath_calculator.php') ? 'active text-primary' : 'text-muted'; ?>">
                    Zakath Tracker
                </a>
                <a href="interest_tracker.php" class="nav-link py-1 <?php echo ($current_page == 'interest_tracker.php' || $current_page == 'monthly_interest.php') ? 'active text-primary' : 'text-muted'; ?>">
                    Interest Tracker
                </a>
                <a href="sadaqa_tracker.php" class="nav-link py-1 <?php echo ($current_page == 'sadaqa_tracker.php' || $current_page == 'monthly_sadaqa.php') ? 'active text-primary' : 'text-muted'; ?>">
                    Sadaqa Tracker
                </a>
                <a href="lending_tracker.php" class="nav-link py-1 <?php echo ($current_page == 'lending_tracker.php') ? 'active text-primary' : 'text-muted'; ?>">
                    Money Lending
                </a>
            </div>
        </div>

        <a href="reminders.php" class="nav-link <?php echo ($current_page == 'reminders.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-bell"></i> Reminders
        </a>
        <a href="search.php" class="nav-link <?php echo ($current_page == 'search.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-magnifying-glass"></i> Advanced Search
        </a>
    </div>

    <div class="mt-auto px-3 pb-3">
        <button id="privacyBtn" onclick="togglePrivacy()"
            class="btn btn-outline-secondary w-100 border-0 text-start bg-light hover-shadow mb-2">
            <i class="fa-solid fa-eye me-2"></i> Visible
        </button>
        <a href="logout.php" class="btn btn-outline-danger w-100 border-0 text-start bg-light hover-shadow">
            <i class="fa-solid fa-right-from-bracket me-2"></i> Logout
        </a>
    </div>
</nav>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Main Content Wrapper -->
<main class="main-content">
    <button class="btn btn-white shadow-sm mobile-toggle-btn mb-3 border text-primary fw-bold" onclick="toggleSidebar()">
        <i class="fa-solid fa-bars me-2"></i> Menu
    </button>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }
    </script>
    <div class="container-fluid">