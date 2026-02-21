<!-- Sidebar -->
<nav class="sidebar">
    <div class="brand-logo">
        <i class="fa-solid fa-wallet"></i> ExpenseMngr
    </div>

    <div class="sidebar-nav">
        <a href="<?php echo BASE_URL; ?>dashboard.php"
            class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-house"></i> Dashboard
        </a>
        <a href="<?php echo BASE_URL; ?>my_cards.php"
            class="nav-link <?php echo ($current_page == 'my_cards.php' || $current_page == 'add_card.php' || $current_page == 'edit_card.php' || $current_page == 'view_card.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-credit-card"></i> My Cards
        </a>

        <a href="<?php echo BASE_URL; ?>expenses.php"
            class="nav-link <?php echo ($current_page == 'expenses.php' || $current_page == 'add_expense.php' || $current_page == 'monthly_expenses.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-receipt"></i> Expenses
        </a>
        <a href="<?php echo BASE_URL; ?>income.php"
            class="nav-link <?php echo ($current_page == 'income.php' || $current_page == 'add_income.php' || $current_page == 'monthly_income.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-sack-dollar"></i> Income
        </a>
        <a href="<?php echo BASE_URL; ?>bank_balances.php"
            class="nav-link <?php echo ($current_page == 'bank_balances.php' || $current_page == 'add_balance.php' || $current_page == 'monthly_balances.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-building-columns"></i> My Banks
        </a>
        <a href="<?php echo BASE_URL; ?>subscriptions.php"
            class="nav-link <?php echo $current_page == 'subscriptions.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-repeat"></i> Subscriptions
        </a>
        <a href="<?php echo BASE_URL; ?>budget.php"
            class="nav-link <?php echo $current_page == 'budget.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-pie"></i> Budget
        </a>
        <a href="<?php echo BASE_URL; ?>goals.php"
            class="nav-link <?php echo $current_page == 'goals.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-bullseye"></i> Goals
        </a>
        <a href="<?php echo BASE_URL; ?>calendar.php"
            class="nav-link <?php echo $current_page == 'calendar.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-days"></i> My Calendar
        </a>

        <!-- Tools & Analysis Group -->
        <?php
        $tool_pages = ['reminders.php', 'search.php', 'reports_advanced.php'];
        $is_tools_active = in_array($current_page, $tool_pages);
        ?>
        <button type="button"
            class="nav-link w-100 text-start d-flex justify-content-between align-items-center <?php echo $is_tools_active ? '' : 'collapsed'; ?>"
            data-bs-toggle="collapse" data-bs-target="#toolsMenu" aria-controls="toolsMenu"
            aria-expanded="<?php echo $is_tools_active ? 'true' : 'false'; ?>" style="border:none;background:none;">
            <span><i class="fa-solid fa-screwdriver-wrench"></i> Analysis & Tools</span>
            <i class="fa-solid fa-chevron-down small"></i>
        </button>
        <div class="collapse <?php echo $is_tools_active ? 'show' : ''; ?>" id="toolsMenu">
            <div class="ps-3 border-start ms-3 border-2 mb-2">
                <a href="<?php echo BASE_URL; ?>reminders.php"
                    class="nav-link py-1 <?php echo $current_page == 'reminders.php' ? $activeClass : 'text-muted'; ?>">
                    Reminders
                </a>
                <a href="<?php echo BASE_URL; ?>search.php"
                    class="nav-link py-1 <?php echo $current_page == 'search.php' ? $activeClass : 'text-muted'; ?>">
                    Advanced Search
                </a>
                <a href="<?php echo BASE_URL; ?>reports_advanced.php"
                    class="nav-link py-1 <?php echo $current_page == 'reports_advanced.php' ? $activeClass : 'text-muted'; ?>">
                    Advanced Reports
                </a>
            </div>
        </div>

        <?php
        $tracker_pages = [
            'company_tracker.php',
            'monthly_incentives.php',
            'zakath_tracker.php',
            'zakath_calculator.php',
            'interest_tracker.php',
            'monthly_interest.php',
            'sadaqa_tracker.php',
            'monthly_sadaqa.php',
            'lending_tracker.php'
        ];
        $is_tracker_active = in_array($current_page, $tracker_pages);
        ?>
        <button type="button"
            class="nav-link w-100 text-start d-flex justify-content-between align-items-center <?php echo $is_tracker_active ? '' : 'collapsed'; ?>"
            data-bs-toggle="collapse" data-bs-target="#trackersMenu" aria-controls="trackersMenu"
            aria-expanded="<?php echo $is_tracker_active ? 'true' : 'false'; ?>" style="border:none;background:none;">
            <span><i class="fa-solid fa-toolbox"></i> Special Trackers</span>
            <i class="fa-solid fa-chevron-down small"></i>
        </button>
        <div class="collapse <?php echo $is_tracker_active ? 'show' : ''; ?>" id="trackersMenu">
            <div class="ps-3 border-start ms-3 border-2 mb-2">
                <a href="<?php echo BASE_URL; ?>company_tracker.php"
                    class="nav-link py-1 <?php echo ($current_page == 'company_tracker.php' || $current_page == 'monthly_incentives.php') ? $activeClass : 'text-muted'; ?>">
                    Incentive Tracker
                </a>
                <a href="<?php echo BASE_URL; ?>zakath_tracker.php"
                    class="nav-link py-1 <?php echo ($current_page == 'zakath_tracker.php' || $current_page == 'zakath_calculator.php') ? $activeClass : 'text-muted'; ?>">
                    Zakath Tracker
                </a>
                <a href="<?php echo BASE_URL; ?>interest_tracker.php"
                    class="nav-link py-1 <?php echo ($current_page == 'interest_tracker.php' || $current_page == 'monthly_interest.php') ? $activeClass : 'text-muted'; ?>">
                    Interest Tracker
                </a>
                <a href="<?php echo BASE_URL; ?>sadaqa_tracker.php"
                    class="nav-link py-1 <?php echo ($current_page == 'sadaqa_tracker.php' || $current_page == 'monthly_sadaqa.php') ? $activeClass : 'text-muted'; ?>">
                    Sadaqa Tracker
                </a>
                <a href="<?php echo BASE_URL; ?>lending_tracker.php"
                    class="nav-link py-1 <?php echo ($current_page == 'lending_tracker.php') ? $activeClass : 'text-muted'; ?>">
                    Money Lending
                </a>
            </div>
        </div>

        <!-- Management Group -->
        <?php
        $mgmt_pages = ['family_management.php', 'admin/index.php', 'admin/manage_tenants.php', 'security_audit.php'];
        $is_mgmt_active = false;
        foreach ($mgmt_pages as $p) {
            if (strpos($current_page, $p) !== false) {
                $is_mgmt_active = true;
                break;
            }
        }
        ?>
        <button type="button"
            class="nav-link w-100 text-start d-flex justify-content-between align-items-center <?php echo $is_mgmt_active ? '' : 'collapsed'; ?>"
            data-bs-toggle="collapse" data-bs-target="#mgmtMenu" aria-controls="mgmtMenu"
            aria-expanded="<?php echo $is_mgmt_active ? 'true' : 'false'; ?>" style="border:none;background:none;">
            <span><i class="fa-solid fa-users-gear"></i> Administration</span>
            <i class="fa-solid fa-chevron-down small"></i>
        </button>
        <div class="collapse <?php echo $is_mgmt_active ? 'show' : ''; ?>" id="mgmtMenu">
            <div class="ps-3 border-start ms-3 border-2 mb-2">
                <?php if (in_array($_SESSION['role'] ?? '', ['family_admin', 'root_admin'])): ?>
                    <a href="<?php echo BASE_URL; ?>family_management.php"
                        class="nav-link py-1 <?php echo ($current_page == 'family_management.php') ? $activeClass : 'text-muted'; ?>">
                        Family Settings
                    </a>
                <?php endif; ?>

                <?php if (($_SESSION['role'] ?? '') === 'root_admin'): ?>
                    <a href="<?php echo BASE_URL; ?>admin/index.php"
                        class="nav-link py-1 <?php echo (strpos($current_page, 'admin/index.php') !== false) ? $activeClass : 'text-muted'; ?>">
                        Root Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/manage_tenants.php"
                        class="nav-link py-1 <?php echo (strpos($current_page, 'admin/manage_tenants.php') !== false) ? $activeClass : 'text-muted'; ?>">
                        Manage Tenants
                    </a>
                <?php endif; ?>

                <a href="<?php echo BASE_URL; ?>security_audit.php"
                    class="nav-link py-1 <?php echo $current_page == 'security_audit.php' ? $activeClass : 'text-muted'; ?>">
                    Security Audit
                </a>
            </div>
        </div>
    </div>

    <div class="mt-auto px-3 pb-3">
        <form action="<?php echo BASE_URL; ?>settings_actions.php" method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="toggle_currency">
            <button type="submit" class="btn btn-outline-secondary w-100 border-0 text-start bg-light hover-shadow">
                <i class="fa-solid fa-coins me-2"></i>
                Switch to <?php echo ($_SESSION['preferences']['base_currency'] ?? 'AED') == 'AED' ? 'INR' : 'AED'; ?>
            </button>
        </form>

        <div class="d-flex gap-2 mb-2">
            <button id="themeToggleBtn" onclick="toggleTheme()"
                class="btn btn-outline-secondary flex-grow-1 border-0 text-start bg-light hover-shadow px-2"
                title="Toggle Theme">
                <i class="fa-solid fa-moon"></i> Dark Mode
            </button>
            <button id="privacyBtn" onclick="togglePrivacy()"
                class="btn btn-outline-secondary flex-grow-1 border-0 text-start bg-light hover-shadow px-2"
                title="Toggle Privacy">
                <i class="fa-solid fa-eye"></i> Visible
            </button>
        </div>

        <a href="<?php echo BASE_URL; ?>logout.php"
            class="btn btn-outline-danger w-100 border-0 text-start bg-light hover-shadow mt-2">
            <i class="fa-solid fa-right-from-bracket me-2"></i> Logout
        </a>
    </div>
</nav>

<button type="button" class="sidebar-overlay" onclick="toggleSidebar()" aria-label="Close Sidebar"
    style="border:none;background:transparent;"></button>

<!-- Main Content Wrapper -->
<main class="main-content">
    <!-- Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <button class="btn btn-white shadow-sm mobile-toggle-btn border text-primary fw-bold" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars me-2"></i> Menu
        </button>

        <div class="ms-auto glass-panel px-3 py-2 border-0 shadow-sm">
            <i class="fa-regular fa-calendar-check me-2 text-primary"></i>
            <span id="liveDateTime" class="fw-bold text-muted small" style="letter-spacing: 0.5px;">Loading...</span>
        </div>
    </div>

    <script>
        function updateLiveClock() {
            const now = new Date();
            const options = {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            document.getElementById('liveDateTime').innerText = now.toLocaleString('en-US', options);
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();
    </script>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }
    </script>
    <div class="container-fluid">
