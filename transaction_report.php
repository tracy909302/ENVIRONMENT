<?php
include 'config.php';

// Handle POST: Record transaction (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data safely
    $matric = isset($_POST['matric']) ? trim($_POST['matric']) : '';
    $sup_id = isset($_POST['sup_id']) ? trim($_POST['sup_id']) : '';
    $fullname = isset($_POST['fullname']) ? trim($_POST['fullname']) : '-';
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $payment = isset($_POST['payment']) ? trim($_POST['payment']) : 'cash';

    // Only allow cash or bank
    if ($payment !== 'cash' && $payment !== 'bank') $payment = 'cash';

    // Only insert if at least one of matric or sup_id is present, fullname is present, and amount > 0
    if (($matric || $sup_id) && $fullname && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO transactions (matric, sup_id, fullname, amount, payment, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssss", $matric, $sup_id, $fullname, $amount, $payment);
        if ($stmt->execute()) {
            echo "success";
        } else {
            echo "fail";
        }
        $stmt->close();
    } else {
        echo "fail";
    }
    exit; // Prevent HTML output for AJAX
}

// Handle GET: Show trial balance
$result = $conn->query("SELECT fullname, amount, payment, created_at FROM transactions ORDER BY created_at DESC");

$trialBalanceEntries = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $dateTime = $row['created_at'];
        $particular = $row['fullname'];
        $folio = $row['payment']; // Cash or Bank
        $amount = $row['amount'];

        if ($amount < 500) {
            // Less than 500 goes to credit
            $trialBalanceEntries[] = [
                'dateTime' => $dateTime,
                'particular' => $particular,
                'folio' => $folio,
                'debit' => 0,
                'credit' => $amount,
            ];
        } else {
            // 500 or more goes to debit
            $trialBalanceEntries[] = [
                'dateTime' => $dateTime,
                'particular' => $particular,
                'folio' => $folio,
                'debit' => $amount,
                'credit' => 0,
            ];
        }
    }
}

// Calculate totals for debit and credit
$totalDebit = 0;
$totalCredit = 0;
foreach ($trialBalanceEntries as $entry) {
    $totalDebit += $entry['debit'];
    $totalCredit += $entry['credit'];
}

// Get admin institution, department, faculty for filtering
$admin_institution = $admin_department = $admin_faculty = '';
session_start();
if (isset($_SESSION['hod_id'])) {
    $admin_username = $_SESSION['hod_id'];
    $stmt = $conn->prepare("SELECT institution, department, faculty FROM admin WHERE hod_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $admin_username);
        $stmt->execute();
        $stmt->bind_result($admin_institution, $admin_department, $admin_faculty);
        $stmt->fetch();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trial Balance</title>
    <link rel="stylesheet" href="../css/Admin_dashboard.css">
    <style>
        table { width: 90%; margin: 40px auto; border-collapse: collapse; background: #fff; }
        th, td { padding: 12px 18px; border: 3px solid wheat; text-align: left; }
        th { background: blue; color: #fff; }
        tr:nth-child(even) { background: #f7f7f7; }
        h2 { text-align: center; margin-top: 30px; }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal-content {
            background-color: #fff;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div style="width:95%; margin: 30px auto 0 auto; display:flex; align-items:center;">
        <a href="admin_dashboard.php" style="text-decoration:none; background:none; border:none; color:inherit; box-shadow:none; font-size:1.5em; margin-right:18px; cursor:pointer;">
            &#8592;
        </a>
        <span style="font-size:1.15em; font-weight:600;">Back to Dashboard</span>
    </div>
    <form method="get" id="refreshForm">
        <button type="submit" style="margin-left:95%; margin-top:10px; margin-bottom:10px; padding:8px 22px; border-radius:5px; border:none; background:#2c3e50; color:#fff; font-weight:600; font-size:1em; cursor:pointer;">
            REFRESH
        </button>
    </form>
    <h2>TRANSACTION REPORT</h2>
    <table>
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Matric</th>
                <th>Supervisor ID</th>
                <th>Amount Paid</th>
                <th>Payment Method</th>
                <th>Date/Time</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Only display transactions for users with matching institution, department, faculty
            $result = $conn->query("SELECT t.fullname, t.matric, t.sup_id, t.amount, t.payment, t.created_at,
                s.institution AS s_institution, s.department AS s_department, s.faculty AS s_faculty,
                v.institution AS v_institution, v.department AS v_department, v.faculty AS v_faculty
                FROM transactions t
                LEFT JOIN student s ON t.matric = s.matric
                LEFT JOIN supervisors v ON t.sup_id = v.sup_id
                ORDER BY t.created_at DESC");
            if ($result && $result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
                    $show = false;
                    $admin_inst = strtolower(trim($admin_institution));
                    $admin_dept = strtolower(trim($admin_department));
                    $admin_fac = strtolower(trim($admin_faculty));
                    // Check for student match
                    if (!empty($row['matric']) && strtolower(trim($row['s_institution'])) === $admin_inst
                        && strtolower(trim($row['s_department'])) === $admin_dept
                        && strtolower(trim($row['s_faculty'])) === $admin_fac) {
                        $show = true;
                    }
                    // Check for supervisor match
                    if (!empty($row['sup_id']) && strtolower(trim($row['v_institution'])) === $admin_inst
                        && strtolower(trim($row['v_department'])) === $admin_dept
                        && strtolower(trim($row['v_faculty'])) === $admin_fac) {
                        $show = true;
                    }
                    if ($show):
            ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                        <td><?php echo htmlspecialchars($row['matric']); ?></td>
                        <td><?php echo htmlspecialchars($row['sup_id']); ?></td>
                        <td><?php echo htmlspecialchars($row['amount']); ?></td>
                        <td><?php echo htmlspecialchars($row['payment']); ?></td>
                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                    </tr>
            <?php
                    endif;
                endwhile;
            else:
            ?>
                <tr><td colspan="6">No transactions found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div style="width:90%; margin: 0 auto; text-align:right;">
        <button id="prepareAccountBtn" style="margin-top:18px; padding:10px 32px; border-radius:6px; border:none; background:blue; color:#fff; font-weight:600; font-size:1em; cursor:pointer;">
            PREPARE ACCOUNT
        </button>
    </div>
    <!-- Modal for Password Input -->
    <div id="passwordModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.35); z-index:2000; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:38px 32px 28px 32px; border-radius:12px; min-width:320px; max-width:90vw; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; text-align:center;">
            <button id="cancelPasswordModal" style="position:absolute; top:10px; right:16px; background:none; border:none; font-size:1.5em; color:#e74c3c; cursor:pointer;">&times;</button>
            <div style="font-size:1.2em; font-weight:600; margin-bottom:22px;">Enter HOD Password</div>
            <form id="passwordForm" autocomplete="off">
                <input type="password" id="hodPassword" placeholder="Password" style="padding:10px; width:80%; border-radius:6px; border:1px solid #bbb; font-size:1em;" required autocomplete="off" maxlength="6" minlength="1" pattern=".{1,6}">
                <div id="passwordError" style="color:#e74c3c; margin-top:10px; font-weight:bold; display:none;"></div>
                <!-- No Verify button -->
            </form>
        </div>
    </div>
    <!-- Loading Spinner Modal -->
    <div id="loadingSpinnerModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.18); z-index:2100; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:38px 32px 28px 32px; border-radius:12px; min-width:120px; max-width:90vw; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; text-align:center;">
            <div class="spinner" style="margin:0 auto 12px auto; width:48px; height:48px; border:6px solid #f3f3f3; border-top:6px solid blue; border-radius:50%; animation:spin 1s linear infinite;"></div>
            <div style="font-size:1.1em; font-weight:600; color:blue;">Verifying...</div>
        </div>
    </div>
    <style>
        @keyframes spin {
            0% { transform: rotate(0deg);}
            100% { transform: rotate(360deg);}
        }
    </style>
    <!-- Modal for Trial Balance -->
    <div id="trialBalanceModal" class="modal">
        <div class="modal-content" id="trialBalanceContent">
            <span class="close">&times;</span>
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <button id="showInstructionBtn" style="padding:7px 18px; border-radius:5px; border:none; background:blue; color:#fff; font-weight:600; font-size:1em; cursor:pointer; margin-top:18px;">
                    Instruction
                </button>
                <h2 style="margin:0; flex:1; text-align:center;">TRIAL BALANCE</h2>
            </div>
            <div id="instructionBox" style="display:none; font-weight:bold; color:#c0392b; margin-bottom:18px; font-size:1.1em;">
                Any amount less than 500 is recorded as <span style="color:#2980b9;">credit</span> because it is giving.<br>
                If the amount is 500 Naira and you allow the student or supervisor to pay 100 Naira, then you will deduct 
                100 Naira from 500 Naira, that means you are giving out the sum of 400 Naira which mean you are making loss to the company.<br>
                BE VERY CAUTION ON THIS! 
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Particulars</th>
                        <th>Folio</th>
                        <th>Debit (₦ receiving)</th>
                        <th>Credit (₦ giving)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($trialBalanceEntries)): ?>
                        <?php foreach ($trialBalanceEntries as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['dateTime']); ?></td>
                                <td><?php echo htmlspecialchars($entry['particular']); ?></td>
                                <td><?php echo htmlspecialchars($entry['folio']); ?></td>
                                <td><?php echo htmlspecialchars($entry['debit']); ?></td>
                                <td><?php echo htmlspecialchars($entry['credit']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight:bold; background:#f1f1f1;">
                            <td colspan="3" style="text-align:right;">Total:</td>
                            <td><?php echo htmlspecialchars(number_format($totalDebit, 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format($totalCredit, 2)); ?></td>
                        </tr>
                        <tr style="font-weight:bold; background:#e0e0e0;">
                            <td colspan="3" style="text-align:right;">Total Balance b/f:</td>
                            <td colspan="2" style="text-align:left;">
                                <?php
                                    $totalBF = $totalDebit + $totalCredit;
                                    echo "₦ " . number_format($totalBF, 2) . " (Debit + Credit)";
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="5">No transactions found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <!-- Calculation Section -->
            <?php
                $rowCount = count($trialBalanceEntries);
                $expectedTotal = $rowCount * 500;
                $loss = $expectedTotal - $totalDebit;
                $creditLoss = $expectedTotal - $totalCredit;
            ?>
            <div style="margin-top:30px;">
                <h3 style="color:#2c3e50;">Calculation</h3>
                <table style="width:60%; margin-bottom:10px;">
                    <tr>
                        <td style="font-weight:bold;">Debit Side Total:</td>
                        <td><?php echo number_format($totalDebit, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Credit Side Total:</td>
                        <td><?php echo number_format($totalCredit, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Number of Rows × 500:</td>
                        <td><?php echo $rowCount . " × 500 = " . number_format($expectedTotal, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Total Debit Loss (Expected - Debit):</td>
                        <td><?php echo number_format($loss, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Total Credit Loss (Expected - Credit):</td>
                        <td><?php echo number_format($creditLoss, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Total Balance b/f (Debit + Credit):</td>
                        <td style="color:green; font-weight:bold;">
                            <?php echo number_format($totalBF, 2); ?>
                        </td>
                    </tr>
                </table>
                <!-- Remove total debit loss and credit, and show overall loss -->
                <?php
                    // Calculate total bank and cash from transactions
                    $bankTotal = 0;
                    $cashTotal = 0;
                    $result = $conn->query("SELECT amount, payment FROM transactions");
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            if (strtolower($row['payment']) === 'bank') {
                                $bankTotal += floatval($row['amount']);
                            } else if (strtolower($row['payment']) === 'cash') {
                                $cashTotal += floatval($row['amount']);
                            }
                        }
                    }
                    // Calculate overall loss: expectedTotal - (totalDebit + totalCredit)
                    $overallLoss = $expectedTotal - ($totalDebit + $totalCredit);
                ?>
                <div style="font-weight:bold; color:#c0392b;">
                    Total Transaction Loss = <?php echo number_format($overallLoss, 2); ?>
                </div>
                <div style="margin-top:18px; font-weight:bold; color:#2c3e50;">
                    CASH AT BANK: <span style="color:#2980b9;">₦<?php echo number_format($bankTotal, 2); ?></span><br>
                    CASH IN HAND: <span style="color:#27ae60;">₦<?php echo number_format($cashTotal, 2); ?></span>
                    <span style="color:green;"><br>
                      Total of cash in hand and cash in bank = <?php echo number_format($totalBF, 2); ?>
                    </span>
                </div>
                <div style="margin-top:18px; font-weight:bold; color:blue; text-align:center;">
                    <?php
                        date_default_timezone_set('Africa/Lagos');
                        $preparedDate = date('l, j F Y \a\t h:i:s A');
                        echo "THIS ACCOUNT IS BEING PREPARED ON $preparedDate";
                    ?>
                </div>
                <div style="width:100%; text-align:center; margin-top:30px;">
                    <button id="printTrialBalanceBtn" style="background:blue; color:#fff; border:none; border-radius:5px; font-size:1em; font-weight:600; padding:10px 32px; cursor:pointer;">
                        Print
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Get the modal
        var modal = document.getElementById("trialBalanceModal");

        // Get the button that opens the modal
        var btn = document.getElementById("prepareAccountBtn");

        // Get the <span> element that closes the modal
        var span = document.getElementsByClassName("close")[0];

        // When the user clicks the button, open the modal
        btn.onclick = function() {
            modal.style.display = "block";
        }

        // When the user clicks on <span> (x), close the modal
        span.onclick = function() {
            modal.style.display = "none";
        }

        // When the user clicks anywhere outside of the modal, close it
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        // Instruction button logic
        document.getElementById('showInstructionBtn').onclick = function() {
            var box = document.getElementById('instructionBox');
            box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
        };

        // Password modal logic
        var passwordModal = document.getElementById("passwordModal");
        var trialBalanceModal = document.getElementById("trialBalanceModal");
        var prepareBtn = document.getElementById("prepareAccountBtn");
        var passwordForm = document.getElementById("passwordForm");
        var hodPasswordInput = document.getElementById("hodPassword");
        var passwordError = document.getElementById("passwordError");
        var cancelPasswordModal = document.getElementById("cancelPasswordModal");
        var loadingSpinnerModal = document.getElementById("loadingSpinnerModal");

        prepareBtn.onclick = function(e) {
            e.preventDefault();
            passwordModal.style.display = "flex";
            hodPasswordInput.value = '';
            passwordError.style.display = "none";
            hodPasswordInput.focus();
        };

        cancelPasswordModal.onclick = function() {
            passwordModal.style.display = "none";
            hodPasswordInput.value = '';
            passwordError.style.display = "none";
        };

        // Only allow max 6 characters in password input
        hodPasswordInput.addEventListener('input', function() {
            passwordError.style.display = "none";
            // Enforce max length 6
            if (hodPasswordInput.value.length > 6) {
                hodPasswordInput.value = hodPasswordInput.value.slice(0, 6);
            }
            var password = hodPasswordInput.value;
            if (password.length === 0) {
                return;
            }
            // AJAX to verify password on every input (if length <= 6)
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'verify_hod_password.php', true);
            xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200 && xhr.responseText.trim() === "success") {
                        passwordModal.style.display = "none";
                        hodPasswordInput.value = '';
                        // Show spinner for 3 seconds, then show trial balance modal
                        loadingSpinnerModal.style.display = "flex";
                        setTimeout(function() {
                            loadingSpinnerModal.style.display = "none";
                            trialBalanceModal.style.display = "block";
                        }, 3000);
                    } else if (xhr.status === 200 && password.length > 0) {
                        passwordError.textContent = "Wrong password";
                        passwordError.style.display = "block";
                        // Do not clear the input, let user continue typing
                        hodPasswordInput.focus();
                    }
                }
            };
            xhr.send("password=" + encodeURIComponent(password));
        });

        // Prevent form submission on enter
        passwordForm.onsubmit = function(e) {
            e.preventDefault();
        };

        // Print only the trial balance modal content
        document.getElementById('printTrialBalanceBtn').onclick = function() {
            var printContents = document.getElementById('trialBalanceContent').innerHTML;
            var printWindow = window.open('', '', 'height=700,width=900');
            printWindow.document.write('<html><head><title>Print Trial Balance</title>');
            printWindow.document.write('<link rel="stylesheet" href="../css/Admin_dashboard.css">');
            printWindow.document.write('<style>body{background:#fff;} table{width:90%;margin:40px auto;border-collapse:collapse;background:#fff;} th,td{padding:12px 18px;border:3px solid wheat;text-align:left;} th{background:blue;color:#fff;} tr:nth-child(even){background:#f7f7f7;} h2{text-align:center;margin-top:30px;}</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(printContents);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function() {
                printWindow.print();
                printWindow.close();
            }, 500);
        };
    </script>
</body>
</html>