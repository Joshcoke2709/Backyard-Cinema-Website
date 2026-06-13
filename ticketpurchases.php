<?php
require_once "staff_only.php";
require_once "conn.php";

$search = isset($_GET['search']) ? trim($_GET['search']) : "";

// Join orders to patrons, schedules, and movies so staff see readable details.
$purchaseSql = "SELECT t.TicketID, p.PatronName, p.Email, m.MovieName,
                       s.ShowDate, s.ShowTime, s.Cinema,
                       t.Quantity, t.TicketPrice, t.TotalAmount,
                       t.PaymentName, t.CardLast4, t.CreatedAt
                FROM ticket_order t
                INNER JOIN patron p ON t.PatronID = p.PatronID
                INNER JOIN schedule s ON t.ScheduleID = s.ScheduleID
                INNER JOIN movie m ON s.MovieID = m.MovieID";

if ($search !== "") {
    // The same search value can match a movie, patron name, or Purchase ID.
    $purchaseSql .= " WHERE m.MovieName LIKE ?
                       OR p.PatronName LIKE ?
                       OR CAST(t.TicketID AS CHAR) LIKE ?";
}

$purchaseSql .= " ORDER BY t.CreatedAt DESC, t.TicketID DESC";
$purchaseStmt = $conn->prepare($purchaseSql);

if ($search !== "") {
    // Binding the search text prevents it from becoming executable SQL.
    $searchLike = "%" . $search . "%";
    $purchaseStmt->bind_param("sss", $searchLike, $searchLike, $searchLike);
}

$purchaseStmt->execute();
$purchaseResult = $purchaseStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Purchases</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        :root {
            --ink: #10090b;
            --panel: #1f1a1c;
            --cream: #fff4d8;
            --gold: #ffd166;
            --red: #d90429;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 34px 20px 60px;
            background: #1f1a1c;
            color: var(--cream);
            font-family: Arial, Helvetica, sans-serif;
        }

        .purchase-page {
            width: min(1450px, 100%);
            margin: 0 auto;
        }

        .purchase-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            margin-bottom: 24px;
        }

        .purchase-header h1 {
            margin: 0 0 8px;
            color: #fff;
            font-size: clamp(36px, 5vw, 62px);
            font-weight: 900;
            text-transform: uppercase;
        }

        .purchase-header p {
            margin: 0;
            color: #d7c9b2;
        }

        .purchase-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .purchase-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 8px 18px;
            border-radius: 999px;
            background: var(--gold);
            color: var(--ink);
            font-weight: 900;
            text-decoration: none;
        }

        .purchase-card {
            padding: 24px;
            border: 1px solid rgba(255, 209, 102, 0.32);
            border-radius: 8px;
            background: rgba(16, 9, 11, 0.45);
        }

        .purchase-filter {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto auto;
            gap: 12px;
            margin-bottom: 22px;
        }

        .purchase-filter input {
            min-height: 46px;
            border: 1px solid rgba(255, 209, 102, 0.4);
            border-radius: 6px;
            padding: 0 14px;
            color: var(--ink);
        }

        .purchase-filter button,
        .purchase-filter a {
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 22px;
            border: 0;
            border-radius: 999px;
            font-weight: 900;
            text-transform: uppercase;
            text-decoration: none;
        }

        .purchase-filter button {
            background: var(--red);
            color: #fff;
        }

        .purchase-filter a {
            background: var(--gold);
            color: var(--ink);
        }

        .table-responsive {
            border: 0;
        }

        .purchase-table {
            margin: 0;
            background: #fff;
            color: #171113;
        }

        .purchase-table thead {
            background: #8f0718;
            color: #fff;
        }

        .purchase-table th,
        .purchase-table td {
            vertical-align: middle !important;
            padding: 12px !important;
        }

        .purchase-id {
            color: #8f0718;
            font-weight: 900;
        }

        .empty-state {
            margin: 0;
            padding: 26px;
            text-align: center;
            color: #fff;
        }

        @media (max-width: 760px) {
            .purchase-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .purchase-filter {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="purchase-page">
        <header class="purchase-header">
            <div>
                <h1>Ticket Purchases</h1>
                <p>Search purchases by movie, purchase ID, or customer name.</p>
            </div>

            <div class="purchase-actions">
                <a href="moviesphp.php">Home</a>
                <a href="updateschedule.php">Update Schedule</a>
            </div>
        </header>

        <section class="purchase-card">
            <form class="purchase-filter" method="get" action="ticketpurchases.php">
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Movie, purchase ID, or customer name">
                <button type="submit">Search</button>
                <?php if ($search !== ""): ?>
                    <a href="ticketpurchases.php">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($purchaseResult && $purchaseResult->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped purchase-table">
                        <thead>
                            <tr>
                                <th>Purchase ID</th>
                                <th>Customer</th>
                                <th>Movie</th>
                                <th>Showtime</th>
                                <th>Cinema</th>
                                <th>Tickets</th>
                                <th>Total</th>
                                <th>Purchased</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($purchase = $purchaseResult->fetch_assoc()): ?>
                                <tr>
                                    <td class="purchase-id">#<?php echo htmlspecialchars($purchase['TicketID']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($purchase['PatronName']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($purchase['Email']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($purchase['MovieName']); ?></td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($purchase['ShowDate'])); ?><br>
                                        <strong><?php echo date('h:i A', strtotime($purchase['ShowTime'])); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($purchase['Cinema']); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['Quantity']); ?></td>
                                    <td>J$<?php echo number_format((float)$purchase['TotalAmount'], 2); ?></td>
                                    <td><?php echo date('M j, Y h:i A', strtotime($purchase['CreatedAt'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">No ticket purchases matched your search.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
