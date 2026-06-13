<?php
require_once "admin_only.php";
require_once "conn.php";

$message = "";
$messageClass = "";
$newSupervisorID = null;

// Add a supervisor after checking that the employee name is unique.
if (isset($_POST['add_supervisor'])) {
    $empName = trim($_POST['emp_name']);
    $password = trim($_POST['password']);
    $role = "supervisor";

    if ($empName === "" || $password === "") {
        $message = "Name and password are required.";
        $messageClass = "alert-danger";
    } else {
        $checkSql = "SELECT EmpID FROM employee WHERE EmpName = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $empName);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->fetch_assoc()) {
            $message = "An employee with that name already exists.";
            $messageClass = "alert-danger";
        } else {
            $insertSql = "INSERT INTO employee (EmpName, Password, Role) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("sss", $empName, $password, $role);

            if ($insertStmt->execute()) {
                $newSupervisorID = $conn->insert_id;
                $message = "Supervisor added successfully.";
                $messageClass = "alert-success";
            } else {
                $message = "Error adding supervisor.";
                $messageClass = "alert-danger";
            }
        }
    }
}

// Administrators can reset supervisor passwords by employee ID.
if (isset($_POST['reset_password'])) {
    $empID = (int)$_POST['emp_id'];
    $newPassword = trim($_POST['new_password']);

    if ($newPassword === "") {
        $message = "New password is required.";
        $messageClass = "alert-danger";
    } else {
        $resetSql = "UPDATE employee SET Password = ? WHERE EmpID = ? AND Role = 'supervisor'";
        $resetStmt = $conn->prepare($resetSql);
        $resetStmt->bind_param("si", $newPassword, $empID);

        if ($resetStmt->execute() && $resetStmt->affected_rows > 0) {
            $message = "Password reset successfully for supervisor ID {$empID}.";
            $messageClass = "alert-success";
        } else {
            $message = "Unable to reset password.";
            $messageClass = "alert-danger";
        }
    }
}

// The role condition prevents this action from deleting an administrator.
if (isset($_GET['delete'])) {
    $empID = (int)$_GET['delete'];

    if (isset($_SESSION['emp_id']) && $empID === (int)$_SESSION['emp_id']) {
        $message = "You cannot remove your own admin account.";
        $messageClass = "alert-danger";
    } else {
        $deleteSql = "DELETE FROM employee WHERE EmpID = ? AND Role = 'supervisor'";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $empID);

        if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
            $message = "Supervisor removed successfully.";
            $messageClass = "alert-success";
        } else {
            $message = "Error removing supervisor.";
            $messageClass = "alert-danger";
        }
    }
}

// Load the current supervisor list for the maintenance table.
$supervisorSql = "SELECT EmpID, EmpName, Role FROM employee WHERE Role = 'supervisor' ORDER BY EmpName";
$supervisorResult = $conn->query($supervisorSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintain Employees</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        body {
            background: #f5f5f5;
            padding: 30px;
        }

        .page-wrap {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 24px;
        }

        .card-box {
            background: #ffffff;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 8px 18px rgba(0,0,0,0.08);
        }

        h2, h3 {
            margin-top: 0;
        }

        .inline-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .inline-form input {
            min-width: 180px;
        }

        .new-id-box {
            margin-top: 12px;
            padding: 12px;
            border-radius: 8px;
            background: #eef8ee;
            border: 1px solid #cfe7cf;
        }

        @media (max-width: 900px) {
            .page-wrap {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">
    <div class="card-box">
        <h2>Add Supervisor</h2>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo $messageClass; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($newSupervisorID !== null): ?>
            <div class="new-id-box">
                <strong>New Supervisor Created</strong><br>
                EmpID: <strong><?php echo $newSupervisorID; ?></strong><br>
                Share this EmpID and the temporary password with the supervisor.
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="emp_name">Supervisor Name</label>
                <input type="text" class="form-control" id="emp_name" name="emp_name" required>
            </div>

            <div class="form-group">
                <label for="password">Temporary Password</label>
                <input type="text" class="form-control" id="password" name="password" required>
            </div>

            <button type="submit" name="add_supervisor" class="btn btn-success">Add Supervisor</button>
            <a href="moviesphp.php" class="btn btn-default">Back</a>
        </form>
    </div>

    <div class="card-box">
        <h3>Current Supervisors</h3>

        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Emp ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th style="width: 360px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($supervisorResult && $supervisorResult->num_rows > 0): ?>
                        <?php while ($row = $supervisorResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['EmpID']); ?></td>
                                <td><?php echo htmlspecialchars($row['EmpName']); ?></td>
                                <td><?php echo htmlspecialchars($row['Role']); ?></td>
                                <td>
                                    <form method="post" action="" class="inline-form" style="margin-bottom:8px;">
                                        <input type="hidden" name="emp_id" value="<?php echo $row['EmpID']; ?>">
                                        <input type="text" name="new_password" class="form-control input-sm" placeholder="New password" required>
                                        <button type="submit" name="reset_password" class="btn btn-primary btn-sm">Reset Password</button>
                                    </form>

                                    <a href="manageemployees.php?delete=<?php echo $row['EmpID']; ?>"
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Remove this supervisor?');">
                                       Remove
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">No supervisors found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
