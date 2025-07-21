<?php
session_start();
// Set or get the suspension start time in session
if (!isset($_SESSION['suspension_start'])) {
    $_SESSION['suspension_start'] = time();
}
$suspension_start = $_SESSION['suspension_start'];
// 1 year in seconds
$suspension_duration = 365 * 24 * 60 * 60;
$suspension_end = $suspension_start + $suspension_duration;

// Handle receipt upload and update user's table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipt'])) {
    include 'config.php';

    // --- 1. Identify User ---
    $user_id = null;
    $user_type = null; // 'student', 'supervisor', or 'admin'

    if (isset($_SESSION['matric'])) {
        $user_id = $_SESSION['matric'];
        $user_type = 'student';
    } elseif (isset($_SESSION['sup_id'])) {
        $user_id = $_SESSION['sup_id'];
        $user_type = 'supervisor';
    } elseif (isset($_SESSION['hod_id'])) {
        $user_id = $_SESSION['hod_id'];
        $user_type = 'admin';
    }

    // --- 2. File Handling & Validation ---
    // A more organized upload path outside the 'form' directory
    $upload_dir = dirname(__DIR__) . '/uploads/receipts/'; // Resolves to htdocs/updo/uploads/receipts/
    if (!is_dir($upload_dir)) {
        // Create directory with safer permissions
        mkdir($upload_dir, 0755, true);
    }

    $file = $_FILES['receipt'];
    $allowed_types = ['image/png', 'image/jpeg'];
    $max_size = 20 * 1024 * 1024; // 20MB

    // --- 3. Improved Error Messages ---
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $upload_error = "File is too large.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $upload_error = "No file was selected.";
                break;
            default:
                $upload_error = "An unknown error occurred during upload.";
        }
    } elseif (!in_array($file['type'], $allowed_types)) {
        $upload_error = "Invalid file type. Only PNG and JPG are allowed.";
    } elseif ($file['size'] > $max_size) {
        $upload_error = "File size exceeds the 20MB limit.";
    } else {
        // --- 4. Secure Naming and File Move ---
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        // Include sanitized user ID in filename for easier tracking
        $sanitized_user_id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $user_id);
        $filename = 'receipt_' . $sanitized_user_id . '_' . uniqid() . '.' . $ext;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // --- 5. Database Update for All User Types ---
            if ($user_id && $user_type) {
                $table = '';
                $id_column = '';

                switch ($user_type) {
                    case 'student':    $table = 'student';     $id_column = 'matric'; break;
                    case 'supervisor': $table = 'supervisors'; $id_column = 'sup_id'; break;
                    case 'admin':      $table = 'admin';       $id_column = 'hod_id'; break;
                }

                if ($table && $id_column) {
                    // NOTE: This assumes an 'online_payment' column exists in 'supervisors' and 'admin' tables.
                    $sql = "UPDATE {$table} SET online_payment = ? WHERE {$id_column} = ?";
                    $stmt = $conn->prepare($sql);

                    // Check if the prepare() statement failed
                    if ($stmt === false) {
                        // Log the actual error for debugging, but show a generic message to the user.
                        error_log("Prepare failed in suspension_dashboard.php: (" . $conn->errno . ") " . $conn->error);
                        $upload_error = "A database error occurred. Please contact an administrator.";
                    } else {
                        $stmt->bind_param("ss", $filename, $user_id);
                        if ($stmt->execute()) {
                            $upload_success = true;
                        } else {
                            $upload_error = "Database update failed: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            } else {
                $upload_error = "User not identified. Cannot save receipt.";
            }
        } else {
            $upload_error = "Failed to save the uploaded file.";
        }
    }
}

// Make sure $conn is always defined before use
if (!isset($conn)) {
    include 'config.php'; // Ensure $conn is available for DB queries
}

// Fetch admin details for alignment and payment info
$admin_institution = $admin_department = $admin_faculty = '';
$hod_account_details = null;
if (isset($_SESSION['hod_id'])) {
    include 'config.php';
    $admin_username = $_SESSION['hod_id'];
    $admin_sql = "SELECT institution, department, faculty, account_number, account_name, bank_name FROM admin WHERE hod_id = ?";
    $stmt = $conn->prepare($admin_sql);
    if ($stmt) {
        $stmt->bind_param("s", $admin_username);
        $stmt->execute();
        $stmt->bind_result($admin_institution, $admin_department, $admin_faculty, $account_number, $account_name, $bank_name);
        $stmt->fetch();
        $hod_account_details = [
            'account_number' => $account_number,
            'account_name' => $account_name,
            'bank_name' => $bank_name
        ];
        $stmt->close();
    }
}

// Check alignment for student and display HOD account details if match
$can_show_hod_account = false;
$hod_account_details = null;
if (isset($_SESSION['matric'])) {
    $user_id = $_SESSION['matric'];
    $sql = "SELECT institution, department, faculty FROM student WHERE matric = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $student_institution = strtolower(trim($row['institution'] ?? ''));
            $student_department = strtolower(trim($row['department'] ?? ''));
            $student_faculty = strtolower(trim($row['faculty'] ?? ''));
            // Check admin table for matching institution, department, faculty
            $admin_sql = "SELECT account_number, account_name, bank_name FROM admin WHERE LOWER(TRIM(institution)) = ? AND LOWER(TRIM(department)) = ? AND LOWER(TRIM(faculty)) = ?";
            $admin_stmt = $conn->prepare($admin_sql);
            if ($admin_stmt) {
                $admin_stmt->bind_param("sss", $student_institution, $student_department, $student_faculty);
                $admin_stmt->execute();
                $admin_result = $admin_stmt->get_result();
                if ($admin_row = $admin_result->fetch_assoc()) {
                    $hod_account_details = [
                        'account_number' => $admin_row['account_number'],
                        'account_name' => $admin_row['account_name'],
                        'bank_name' => $admin_row['bank_name']
                    ];
                    $can_show_hod_account = true;
                } else {
                    $can_show_hod_account = false;
                    $hod_account_details = null;
                }
                $admin_stmt->close();
            }
        }
        $stmt->close();
    }
}
if (!$can_show_hod_account && isset($_SESSION['sup_id'])) {
    $user_id = $_SESSION['sup_id'];
    $sql = "SELECT institution, department, faculty FROM supervisors WHERE sup_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $sup_institution = strtolower(trim($row['institution'] ?? ''));
            $sup_department = strtolower(trim($row['department'] ?? ''));
            $sup_faculty = strtolower(trim($row['faculty'] ?? ''));
            // Now check admin table for matching institution, department, faculty
            $admin_sql = "SELECT account_number, account_name, bank_name FROM admin WHERE LOWER(TRIM(institution)) = ? AND LOWER(TRIM(department)) = ? AND LOWER(TRIM(faculty)) = ?";
            $admin_stmt = $conn->prepare($admin_sql);
            if ($admin_stmt) {
                $admin_stmt->bind_param("sss", $sup_institution, $sup_department, $sup_faculty);
                $admin_stmt->execute();
                $admin_result = $admin_stmt->get_result();
                if ($admin_row = $admin_result->fetch_assoc()) {
                    $hod_account_details = [
                        'account_number' => $admin_row['account_number'],
                        'account_name' => $admin_row['account_name'],
                        'bank_name' => $admin_row['bank_name']
                    ];
                    $can_show_hod_account = true;
                } else {
                    $can_show_hod_account = false;
                    $hod_account_details = null;
                }
                $admin_stmt->close();
            }
        }
        $stmt->close();
    }
}
if (!$can_show_hod_account && isset($_SESSION['hod_id'])) {
    $user_id = $_SESSION['hod_id'];
    $sql = "SELECT institution, department, faculty FROM admin WHERE hod_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $admin_institution_check = strtolower(trim($row['institution'] ?? ''));
            $admin_department_check = strtolower(trim($row['department'] ?? ''));
            $admin_faculty_check = strtolower(trim($row['faculty'] ?? ''));
            $admin_institution_norm = strtolower(trim($admin_institution ?? ''));
            $admin_department_norm = strtolower(trim($admin_department ?? ''));
            $admin_faculty_norm = strtolower(trim($admin_faculty ?? ''));
            if (
                $admin_institution_check === $admin_institution_norm &&
                $admin_department_check === $admin_department_norm &&
                $admin_faculty_check === $admin_faculty_norm
            ) {
                $can_show_hod_account = true;
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACCOUNT SUSPENDED</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-image: url(white.png);
            background-size: cover;
                    background-attachment: fixed;
            background-repeat: no-repeat;
            background-position: center center;
            color: #333;
            text-align: center;
            padding: 50px 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            background: #3498db;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 5px solid #e74c3c;
            animation: blink-border 3s linear infinite;
        }
        @keyframes blink-border {
            0% { border-color: green; }
            50% { border-color: #fff; }
            100% { border-color: #e74c3c; }
        }
        h3 {
            color: #e74c3c;
        }
        .logo {
            max-width: 150px;
            margin-bottom: 20px;
        }
        .countdown {
            font-size: 1.5em;
            margin: 20px 0;
            font-weight: bold;
            color: #2c3e50;
        }
        .contact {
            margin-top: 20px;
            font-style: italic;
            color: #7f8c8d;
        }
        .logout-btn {
            display: block;
            margin: 40px auto 0 auto;
            padding: 12px 40px;
            background: #e74c3c;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1.2em;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .logout-btn:hover {
            background: #c0392b;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Replace with your logo -->
        <img src="updo.png" alt="Company Logo" class="logo">

        <h3>🚧 Your account is automatically suspended by the system due to too much of attemting an incorrect date of birth. 🚧</h3>
        
        <div style="margin: 25px 0 10px 0; font-size: 1.1em;">
            Meet your HOD or ADMIN to retrieve your suspended account with the retrieval form.<br>
            <a href="retrival_form.pdf" download class="logout-btn" style="background:#3498db; margin: 18px auto 0 auto; display:inline-block; font-size:1em;">Download Here</a>
        </div>
        <div style="margin: 25px 0 10px 0; font-size: 1.1em;">
            Remove your account from suspension by making payment to the HOD if you are far away.<br>
            <form id="payForm" action="#" method="post" style="margin-top:12px;">
                <button type="button" class="logout-btn" style="background:#27ae60;" id="payBtn">Pay</button>
            </form>
            <?php if (isset($upload_success) && $upload_success): ?>
                <div style="color:green; font-weight:bold; margin-top:10px;">Receipt uploaded successfully!</div>
            <?php elseif (isset($upload_error)): ?>
                <div style="color:#e74c3c; font-weight:bold; margin-top:10px;"><?php echo htmlspecialchars($upload_error); ?></div>
            <?php endif; ?>
        </div>
        
        <!-- Countdown Timer -->
        <div class="countdown" id="countdown">
            Estimated time remaining: 1 year<span id="time"></span>
        </div>
        <p>Thank you for your patience.</p>
        <form action="student_login.php" method="post">
            <button type="submit" class="logout-btn">LOG OUT</button>
        </form>
    </div>
    <!-- Payment Modal -->
    <div id="paymentModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.35); z-index:3000; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:38px 32px 28px 32px; border-radius:12px; min-width:320px; max-width:95vw; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; text-align:left;">
            <button id="closePaymentModal" style="position:absolute; top:10px; right:16px; background:none; border:none; font-size:1.5em; color:#e74c3c; cursor:pointer;">&times;</button>
            <div style="font-size:1.1em; font-weight:600; margin-bottom:18px;">
                <?php if ($can_show_hod_account && $hod_account_details): ?>
                    Account Number: <span style="color:#2980b9;"><?php echo htmlspecialchars($hod_account_details['account_number']); ?></span><br>
                    Account Name: <span style="color:#2980b9;"><?php echo htmlspecialchars($hod_account_details['account_name']); ?></span><br>
                    Bank Name: <span style="color:#2980b9;"><?php echo htmlspecialchars($hod_account_details['bank_name']); ?></span>
                <?php else: ?>
                    <span style="color:#e74c3c;">You are not aligned with the HOD's institution, department, or faculty.</span>
                <?php endif; ?>
            </div>
            <div style="margin-bottom:10px; font-weight:bold;">Upload Receipt</div>
            <form id="uploadReceiptForm" enctype="multipart/form-data" method="post" action="">
                <input type="file" name="receipt" id="receiptInput" accept="image/png, image/jpeg" style="margin-bottom:12px;" required>
                <div id="fileError" style="color:#e74c3c; font-size:0.98em; margin-bottom:10px; display:none;"></div>
                <button type="submit" class="logout-btn" style="background:#27ae60; font-size:1em; padding:8px 28px;">Submit</button>
            </form>
        </div>
    </div>
    <!-- JavaScript for Countdown and Payment Modal -->
    <script>
        // Use PHP to pass the suspension_end timestamp to JS
        const suspensionEnd = <?php echo $suspension_end * 1000; ?>; // JS uses ms
        function updateCountdown() {
            const now = Date.now();
            const diff = suspensionEnd - now;
            if (diff <= 0) {
                document.getElementById('time').textContent = " Maintenance complete!";
                return;
            }
            const totalHours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            document.getElementById('time').textContent =
                `${totalHours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        setInterval(updateCountdown, 1000);
        updateCountdown();

        // Payment Modal logic
        document.getElementById('payBtn').onclick = function(e) {
            e.preventDefault();
            document.getElementById('paymentModal').style.display = 'flex';
        };
        document.getElementById('closePaymentModal').onclick = function() {
            document.getElementById('paymentModal').style.display = 'none';
            document.getElementById('fileError').style.display = 'none';
            document.getElementById('receiptInput').value = '';
        };

        // Validate file type and size before submit
        document.getElementById('uploadReceiptForm').onsubmit = function(e) {
            var fileInput = document.getElementById('receiptInput');
            var fileError = document.getElementById('fileError');
            fileError.style.display = 'none';
            var file = fileInput.files[0];
            if (!file) {
                fileError.textContent = "Please select a file.";
                fileError.style.display = 'block';
                e.preventDefault();
                return false;
            }
            var allowedTypes = ['image/png', 'image/jpeg'];
            if (allowedTypes.indexOf(file.type) === -1) {
                fileError.textContent = "Only PNG or JPG images are allowed.";
                fileError.style.display = 'block';
                e.preventDefault();
                return false;
            }
            if (file.size > 20 * 1024 * 1024) { // 20MB
                fileError.textContent = "File size must not exceed 20MB.";
                fileError.style.display = 'block';
                e.preventDefault();
                return false;
            }
        };
    </script>
</body>
</html>