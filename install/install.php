<?php
// install/install.php
// Automated Installer for Expense Manager

session_start();

// Helper to determine root path
$rootDir = dirname(__DIR__);
$envFile = $rootDir . '/.env';

// Functions
function writeEnvFile($path, $data) {
    $content = "";
    foreach ($data as $key => $value) {
        $content .= "{$key}={$value}\n";
    }
    return file_put_contents($path, $content);
}

// 1. Handle Form Submission
$step = isset($_POST['step']) ? intval($_POST['step']) : 1;
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        // DB Configuration Step
        $host = trim($_POST['db_host']);
        $name = trim($_POST['db_name']);
        $user = trim($_POST['db_user']);
        $pass = $_POST['db_pass'];

        try {
            // Test Connection
            $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Save to .env
            $envData = [
                'DB_HOST' => $host,
                'DB_NAME' => $name,
                'DB_USER' => $user,
                'DB_PASS' => '"' . str_replace('"', '\"', $pass) . '"' // Escape quotes
            ];
            
            if (writeEnvFile($envFile, $envData)) {
                // Determine base URL for next step redirect if needed, 
                // but we carry state via session or hidden inputs.
                // For simplicity, we just move to next step in UI.
                $step = 3;
                $_SESSION['db_config'] = $envData; // Temp store
            } else {
                $error = "Failed to write .env file. Check permissions for $rootDir";
                $step = 1; // Stay here
            }

        } catch (PDOException $e) {
            $error = "Connection Failed. Please check your credentials.";
            if ($e->getCode() == 1045) $error = "Access Denied: Invalid Username or Password.";
            if ($e->getCode() == 2002) $error = "Connection Failed: Could not find Database Host.";
            $step = 1;
        }
    } elseif ($step === 3) {
        // Table Creation Step
        $envData = $_SESSION['db_config'] ?? [];
        if (empty($envData)) {
            $step = 1;
            $error = "Session expired. Please start again.";
        } else {
             try {
                $dsn = "mysql:host={$envData['DB_HOST']};dbname={$envData['DB_NAME']};charset=utf8mb4";
                $pdo = new PDO($dsn, $envData['DB_USER'], trim($envData['DB_PASS'], '"'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                // DATA TABLES
                $queries = [
                    "CREATE TABLE IF NOT EXISTS users (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        email VARCHAR(150) NOT NULL UNIQUE,
                        password VARCHAR(255) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )",
                    "CREATE TABLE IF NOT EXISTS banks (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        bank_name VARCHAR(100) NOT NULL,
                        account_type VARCHAR(50) DEFAULT 'Current',
                        account_number VARCHAR(100),
                        iban VARCHAR(100),
                        currency VARCHAR(10) DEFAULT 'AED',
                        notes TEXT,
                        is_default TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS cards (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        bank_name VARCHAR(100) NOT NULL,
                        card_name VARCHAR(100),
                        card_type VARCHAR(50) DEFAULT 'Credit',
                        network VARCHAR(50),
                        tier VARCHAR(50),
                        limit_amount DECIMAL(15,2),
                        bill_day INT,
                        statement_day INT,
                        cashback_struct TEXT,
                        bank_url TEXT,
                        features TEXT,
                        bank_id INT,
                        first_four VARCHAR(4),
                        last_four VARCHAR(4),
                        fee_type VARCHAR(20) DEFAULT 'LTF',
                        card_image TEXT,
                        is_default TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS expenses (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        amount DECIMAL(15,2) NOT NULL,
                        description VARCHAR(255) NOT NULL,
                        category VARCHAR(100) NOT NULL,
                        payment_method VARCHAR(50) DEFAULT 'Cash',
                        card_id INT,
                        expense_date DATE NOT NULL,
                        is_subscription TINYINT(1) DEFAULT 0,
                        currency VARCHAR(3) DEFAULT 'AED',
                        original_amount DECIMAL(15,2),
                        tags VARCHAR(255),
                        cashback_earned DECIMAL(10,2) DEFAULT 0,
                        is_fixed TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS income (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        amount DECIMAL(15,2) NOT NULL,
                        description VARCHAR(255) NOT NULL,
                        category VARCHAR(100),
                        income_date DATE NOT NULL,
                        is_recurring TINYINT(1) DEFAULT 0,
                        recurrence_day INT,
                        currency VARCHAR(3) DEFAULT 'AED',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                     "CREATE TABLE IF NOT EXISTS bank_balances (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        bank_name VARCHAR(100) NOT NULL,
                        amount DECIMAL(15,2) NOT NULL,
                        balance_date DATE NOT NULL,
                        bank_id INT,
                        currency VARCHAR(3) DEFAULT 'AED',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS card_payments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        card_id INT NOT NULL,
                        bank_id INT,
                        amount DECIMAL(15,2) NOT NULL,
                        payment_date DATE NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                     "CREATE TABLE IF NOT EXISTS reminders (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        alert_date DATE NOT NULL,
                        recurrence_type VARCHAR(20) DEFAULT 'none',
                        color VARCHAR(20) DEFAULT 'primary',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS sinking_funds (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        name VARCHAR(100) NOT NULL,
                        target_amount DECIMAL(15,2) NOT NULL,
                        current_saved DECIMAL(15,2) DEFAULT 0,
                        target_date DATE NOT NULL,
                        icon VARCHAR(50) DEFAULT 'fa-bullseye',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS zakath_calculations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        cycle_name VARCHAR(100) NOT NULL,
                        cash_balance DECIMAL(15,2) DEFAULT 0,
                        gold_silver DECIMAL(15,2) DEFAULT 0,
                        investments DECIMAL(15,2) DEFAULT 0,
                        liabilities DECIMAL(15,2) DEFAULT 0,
                        total_zakath DECIMAL(15,2) NOT NULL,
                        status ENUM('Pending', 'Paid') DEFAULT 'Pending',
                        due_date DATE DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS sadaqa_tracker (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        sadaqa_date DATE NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS company_incentives (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        title VARCHAR(255) DEFAULT 'Monthly Incentive',
                        amount DECIMAL(10,2) NOT NULL,
                        incentive_date DATE NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                     "CREATE TABLE IF NOT EXISTS lending_tracker (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        borrower_name VARCHAR(100) NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        currency VARCHAR(10) DEFAULT 'AED',
                        lent_date DATE NOT NULL,
                        due_date DATE DEFAULT NULL,
                        status ENUM('Pending', 'Paid', 'Partially Paid') DEFAULT 'Pending',
                        notes TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                     "CREATE TABLE IF NOT EXISTS interest_tracker (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        interest_date DATE NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                     "CREATE TABLE IF NOT EXISTS url_cache (
                        url_hash VARCHAR(64) PRIMARY KEY,
                        url_data TEXT,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    )"
                ];

                foreach ($queries as $sql) {
                    $pdo->exec($sql);
                }

                $step = 4; // Move to Account Creation

             } catch (Exception $e) {
                 $error = "Schema Creation Failed. Please check database permissions.";
                 $step = 3; // Retry
             }
        }
    } elseif ($step === 4) {
        // Admin Account Creation
        $envData = $_SESSION['db_config'];
        $email = trim($_POST['email']);
        $pass = $_POST['password'];
        $name = trim($_POST['name']);

        try {
            $dsn = "mysql:host={$envData['DB_HOST']};dbname={$envData['DB_NAME']};charset=utf8mb4";
            $pdo = new PDO($dsn, $envData['DB_USER'], trim($envData['DB_PASS'], '"'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Check if user exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                 $error = "User already exists. Please login or use a different email.";
                 $step = 4; 
            } else {
                $hashed = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$name, $email, $hashed]);

                $success = "Installation Complete!";
                $step = 5;
            }

        } catch (Exception $e) {
            $error = "Account Setup Failed. Please try again.";
            $step = 4;
        }
    } elseif ($step === 5) {
        // Cleanup and finish
        // Self-destruction logic is triggered by the button click (GET or POST)
        // Here we just redirect or show the final "Go" button
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Expense Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #6c5ce7; --bg-gradient: linear-gradient(135deg, #a8c0ff 0%, #3f2b96 100%); }
        body { background: var(--bg-gradient); min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Inter', sans-serif; }
        .glass-panel { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); border-radius: 20px; box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37); border: 1px solid rgba(255, 255, 255, 0.18); overflow: hidden; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 2rem; position: relative; }
        .step-indicator::before { content: ''; position: absolute; top: 15px; left: 0; right: 0; height: 3px; background: #e9ecef; z-index: 0; }
        .step { width: 35px; height: 35px; background: #e9ecef; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; position: relative; z-index: 1; color: #6c757d; transition: all 0.3s; }
        .step.active { background: var(--primary-color); color: white; box-shadow: 0 0 0 5px rgba(108, 92, 231, 0.2); }
        .step.completed { background: #2ecc71; color: white; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="glass-panel p-5">
                <div class="text-center mb-4">
                    <i class="fa-solid fa-wallet fa-3x text-primary mb-3"></i>
                    <h2 class="fw-bold">Expense Manager Setup</h2>
                    <p class="text-muted">Follow the steps to configure your application</p>
                </div>

                <!-- Progress Steps -->
                <div class="step-indicator px-4">
                    <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">1</div>
                    <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">2</div>
                    <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">3</div>
                    <div class="step <?php echo $step >= 4 ? 'active' : ''; ?> <?php echo $step > 4 ? 'completed' : ''; ?>">4</div>
                </div>

                <!-- Error Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger shadow-sm border-0 shake-animation">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    
                    <!-- STEP 1: WELCOME & CONNECT -->
                    <?php if ($step === 1): ?>
                        <input type="hidden" name="step" value="2">
                        <h4 class="fw-bold mb-3">Database Connection</h4>
                        <div class="mb-3">
                            <label class="form-label">Database Host</label>
                            <input type="text" name="db_host" class="form-control form-control-lg" placeholder="localhost" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" name="db_name" class="form-control form-control-lg" placeholder="expense_manager" required>
                        </div>
                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <label class="form-label">Username</label>
                                <input type="text" name="db_user" class="form-control" placeholder="root" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Password</label>
                                <input type="password" name="db_pass" class="form-control" placeholder="******">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold shadow">
                            Check Connection <i class="fa-solid fa-arrow-right ms-2"></i>
                        </button>
                    <?php endif; ?>

                    <!-- STEP 3 (Skipped 2 logic implicit): CREATE TABLES -->
                    <?php if ($step === 3): ?>
                        <input type="hidden" name="step" value="3"> <!-- Submits to itself for processing -->
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary mb-3" role="status" style="display:none;" id="installSpinner"></div>
                            <i class="fa-solid fa-database fa-4x text-info mb-3" id="dbIcon"></i>
                            <h4 class="fw-bold">Database Connected!</h4>
                            <p class="text-muted">We are ready to install the database schema.</p>
                            
                            <div class="alert alert-info border-0 bg-info-subtle text-info-emphasis text-start">
                                <small>
                                <i class="fa-solid fa-list-check me-2"></i> <strong>Will Create:</strong><br>
                                Users, Banks, Cards, Expenses, Income, Reminders, Goals, Zakath, Sadaqa, Company, Lending...
                                </small>
                            </div>

                            <button type="submit" class="btn btn-success w-100 py-3 fw-bold shadow" onclick="showLoader()">
                                Install Database Tables <i class="fa-solid fa-hammer ms-2"></i>
                            </button>
                        </div>
                        <script>
                            function showLoader() {
                                document.getElementById('installSpinner').style.display = 'inline-block';
                                document.getElementById('dbIcon').style.display = 'none';
                            }
                        </script>
                    <?php endif; ?>

                    <!-- STEP 4: CREATE ADMIN -->
                    <?php if ($step === 4): ?>
                        <input type="hidden" name="step" value="4">
                        <h4 class="fw-bold mb-3">Create Admin Account</h4>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control form-control-lg" placeholder="John Doe" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control form-control-lg" placeholder="john@example.com" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control form-control-lg" required>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                             <span class="text-muted small">Need more users? You can add them later manually directly in DB or we can build an invite system.</span>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold shadow">
                            Create Account <i class="fa-solid fa-check ms-2"></i>
                        </button>
                    <?php endif; ?>

                    <!-- STEP 5: SUCCESS & CLEANUP -->
                    <?php if ($step === 5): ?>
                        <div class="text-center py-5">
                            <div class="mb-4 text-success">
                                <i class="fa-solid fa-circle-check fa-5x "></i>
                            </div>
                            <h3 class="fw-bold text-success">Installation Complete!</h3>
                            <p class="text-muted mb-4">Your Expense Manager is ready to use.</p>
                            
                            <a href="../dashboard.php" class="btn btn-dark btn-lg w-100 py-3 fw-bold shadow mb-3" onclick="cleanup()">
                                Go to Dashboard <i class="fa-solid fa-rocket ms-2"></i>
                            </a>
                            <p class="text-danger small"><i class="fa-solid fa-shield-halved me-1"></i> Installer will self-destruct after you leave.</p>
                        </div>

                        <script>
                            function cleanup() {
                                // Trigger cleanup via beacon or simple timeout fetch
                                // Since we are redirecting, we can attempt a sync fetch or 
                                // more reliably, let the user click and WE perform the action via a separate tiny script?
                                // OR: We can just use a PHP script as the target of the link that deletes files then redirects.
                                window.location.href = "finish_install.php";
                            }
                        </script>
                    <?php endif; ?>

                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
