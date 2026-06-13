<?php
session_start();
require_once "conn.php";

$message = "";
$messageClass = "error-message";
$next = isset($_GET['next']) ? trim($_GET['next']) : "moviesphp.php";

// Only allow local redirect paths after login, not external web addresses.
if ($next === "" || preg_match('/^https?:\/\//i', $next)) {
    $next = "moviesphp.php";
}

function login_patron($row) {
    // Store the logged-in patron's identity and role in the session.
    $_SESSION['patron_id'] = $row['PatronID'];
    $_SESSION['patron_name'] = $row['PatronName'];
    $_SESSION['patron_email'] = $row['Email'];
    $_SESSION['role'] = "patron";
}

if (isset($_POST['login'])) {
    $identifier = trim($_POST['identifier']);
    $password = trim($_POST['password']);
    $next = trim($_POST['next']);

    if ($next === "" || preg_match('/^https?:\/\//i', $next)) {
        $next = "moviesphp.php";
    }

    if ($identifier === "" || $password === "") {
        $message = "Please enter your login details.";
    } elseif (ctype_digit($identifier)) {
        // Numeric identifiers are treated as employee IDs.
        $sql = "SELECT EmpID, EmpName, Password, Role FROM employee WHERE EmpID = ?";
        $stmt = $conn->prepare($sql);
        $empid = (int)$identifier;
        $stmt->bind_param("i", $empid);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if ($password === $row['Password']) {
                $_SESSION['emp_id'] = $row['EmpID'];
                $_SESSION['emp_name'] = $row['EmpName'];
                $_SESSION['role'] = strtolower(trim($row['Role']));

                header("Location: moviesphp.php");
                exit();
            }
        }

        $message = "Incorrect staff login details.";
    } else {
        // Email identifiers are treated as patron accounts.
        $sql = "SELECT PatronID, PatronName, Email, Password FROM patron WHERE Email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $storedPassword = $row['Password'];
            $passwordMatches = password_verify($password, $storedPassword) || $password === $storedPassword;

            if ($passwordMatches) {
                login_patron($row);
                header("Location: " . $next);
                exit();
            }
        }

        $message = "Incorrect email or password.";
    }
}

if (isset($_POST['register'])) {
    // Validate a new patron account before inserting it.
    $patronName = trim($_POST['patron_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['new_password']);
    $next = trim($_POST['next']);

    if ($next === "" || preg_match('/^https?:\/\//i', $next)) {
        $next = "moviesphp.php";
    }

    if ($patronName === "" || $email === "" || $password === "") {
        $message = "Please complete all account fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } else {
        // Prepared statements keep the email separate from the SQL command.
        $checkSql = "SELECT PatronID FROM patron WHERE Email = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->fetch_assoc()) {
            $message = "An account already exists with that email.";
        } else {
            // Patrons' passwords are stored as secure hashes, not plain text.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $insertSql = "INSERT INTO patron (PatronName, Email, Password) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("sss", $patronName, $email, $passwordHash);

            if ($insertStmt->execute()) {
                login_patron([
                    "PatronID" => $conn->insert_id,
                    "PatronName" => $patronName,
                    "Email" => $email
                ]);

                header("Location: " . $next);
                exit();
            }

            $message = "Unable to create account right now.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backyard Cinemas Login</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="style2.css">
</head>
<body>

<div class="login-page">
    <div class="login-shell">
        <div class="login-intro">
            <div class="popcorn-mark"><span></span></div>
            <h1>Backyard Cinemas</h1>
            <p>Sign in to buy tickets, or use your staff credentials to manage the cinema.</p>
        </div>

        <div class="login-card">
            <?php if (!empty($message)): ?>
                <div class="<?php echo $messageClass; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <div class="auth-grid">
                <form action="login.php" method="post" class="auth-panel">
                    <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                    <h2>Log In</h2>
                    <p>Use an email for patrons or an employee ID for staff.</p>

                    <div class="form-group">
                        <label for="identifier">Email or Employee ID</label>
                        <input type="text" placeholder="you@example.com or 1001" id="identifier" name="identifier" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" placeholder="Enter password" id="password" name="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-login btn-block" name="login">Login</button>
                </form>

                <form action="login.php" method="post" class="auth-panel create-panel">
                    <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                    <h2>Create Account</h2>
                    <p>Patron accounts can purchase movie tickets.</p>

                    <div class="form-group">
                        <label for="patron_name">Name</label>
                        <input type="text" placeholder="Your name" id="patron_name" name="patron_name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" placeholder="you@example.com" id="email" name="email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">Password</label>
                        <input type="password" placeholder="Create password" id="new_password" name="new_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-create btn-block" name="register">Create Account</button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
