<?php
session_start();
require_once "conn.php";

$requestedScheduleID = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$requestedMovieID = isset($_GET['movie_id']) ? (int)$_GET['movie_id'] : 0;

if ($requestedScheduleID <= 0 && $requestedMovieID <= 0) {
    die("Missing movie or showtime.");
}

$nextUrl = "buyticket.php";
$queryParts = [];
if ($requestedMovieID > 0) {
    $queryParts[] = "movie_id=" . $requestedMovieID;
}
if ($requestedScheduleID > 0) {
    $queryParts[] = "schedule_id=" . $requestedScheduleID;
}
if (!empty($queryParts)) {
    $nextUrl .= "?" . implode("&", $queryParts);
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'patron' || !isset($_SESSION['patron_id'])) {
    header("Location: login.php?next=" . urlencode($nextUrl));
    exit();
}

$todayDate = date('Y-m-d');

if ($requestedScheduleID > 0) {
    $findMovieSql = "SELECT MovieID FROM schedule WHERE ScheduleID = ? LIMIT 1";
    $findMovieStmt = $conn->prepare($findMovieSql);
    $findMovieStmt->bind_param("i", $requestedScheduleID);
    $findMovieStmt->execute();
    $findMovieResult = $findMovieStmt->get_result();

    if ($findMovieRow = $findMovieResult->fetch_assoc()) {
        $requestedMovieID = (int)$findMovieRow['MovieID'];
    }
}

if ($requestedMovieID <= 0) {
    die("Movie not found.");
}

$movieSql = "SELECT MovieID, MovieName, Rating, PosterURL FROM movie WHERE MovieID = ? LIMIT 1";
$movieStmt = $conn->prepare($movieSql);
$movieStmt->bind_param("i", $requestedMovieID);
$movieStmt->execute();
$movieResult = $movieStmt->get_result();
$movie = $movieResult->fetch_assoc();

if (!$movie) {
    die("Movie not found.");
}

$scheduleSql = "SELECT ScheduleID, Cinema, ShowDate, ShowTime
                FROM schedule
                WHERE MovieID = ?
                  AND ShowDate >= ?
                ORDER BY ShowDate, ShowTime, Cinema";
$scheduleStmt = $conn->prepare($scheduleSql);
$scheduleStmt->bind_param("is", $requestedMovieID, $todayDate);
$scheduleStmt->execute();
$scheduleResult = $scheduleStmt->get_result();

$movieSchedules = [];
while ($scheduleRow = $scheduleResult->fetch_assoc()) {
    $movieSchedules[] = $scheduleRow;
}

if (empty($movieSchedules)) {
    die("No upcoming showtimes found for this movie.");
}

$scheduleID = $requestedScheduleID;
$validScheduleIDs = array_map(function ($row) {
    return (int)$row['ScheduleID'];
}, $movieSchedules);

if (isset($_POST['selected_schedule_id'])) {
    $postedScheduleID = (int)$_POST['selected_schedule_id'];
    if (in_array($postedScheduleID, $validScheduleIDs, true)) {
        $scheduleID = $postedScheduleID;
    }
}

if ($scheduleID <= 0 || !in_array($scheduleID, $validScheduleIDs, true)) {
    $scheduleID = (int)$movieSchedules[0]['ScheduleID'];
}

$show = null;
foreach ($movieSchedules as $scheduleRow) {
    if ((int)$scheduleRow['ScheduleID'] === $scheduleID) {
        $show = $scheduleRow;
        break;
    }
}

$message = "";
$messageClass = "";
$ticketPrice = 1500.00;

if (isset($_POST['purchase_ticket'])) {
    $quantity = (int)$_POST['quantity'];
    $cardName = trim($_POST['card_name']);
    $cardNumber = preg_replace('/\D/', '', $_POST['card_number']);
    $expiry = trim($_POST['expiry']);
    $cvv = trim($_POST['cvv']);

    if ($quantity < 1 || $quantity > 10) {
        $message = "Please choose between 1 and 10 tickets.";
        $messageClass = "alert-danger";
    } elseif ($cardName === "" || strlen($cardNumber) < 12 || $expiry === "" || strlen($cvv) < 3) {
        $message = "Please complete the fake payment details.";
        $messageClass = "alert-danger";
    } else {
        $totalAmount = $quantity * $ticketPrice;
        $last4 = substr($cardNumber, -4);
        $patronID = (int)$_SESSION['patron_id'];

        $insertSql = "INSERT INTO ticket_order
                      (PatronID, ScheduleID, Quantity, TicketPrice, TotalAmount, PaymentName, CardLast4)
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertSql);
        $insertStmt->bind_param("iiiddss", $patronID, $scheduleID, $quantity, $ticketPrice, $totalAmount, $cardName, $last4);

        if ($insertStmt->execute()) {
            $message = "Ticket purchase complete. Your Purchase ID is #" . $conn->insert_id . ".";
            $messageClass = "alert-success";
        } else {
            $message = "Unable to save ticket purchase.";
            $messageClass = "alert-danger";
        }
    }
}

$poster = !empty($movie["PosterURL"]) ? $movie["PosterURL"] : "https://placehold.co/220x330/160b0d/ffd166?text=" . urlencode($movie["MovieName"]);
$posterFallback = "https://placehold.co/220x330/160b0d/ffd166?text=" . urlencode($movie["MovieName"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buy Tickets</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="style2.css">
</head>
<body>

<div class="login-page">
    <div class="ticket-shell">
        <div class="login-intro">
            <div class="popcorn-mark"><span></span></div>
            <h1>Buy Tickets</h1>
            <p>Select your preferred showtime, then complete a fake payment to reserve your Backyard Cinemas tickets.</p>
        </div>

        <div class="ticket-layout">
            <div class="ticket-card">
                <div class="ticket-summary">
                    <img src="<?php echo htmlspecialchars($poster); ?>" alt="<?php echo htmlspecialchars($movie['MovieName']); ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($posterFallback); ?>';">
                    <div>
                        <span class="rating-badge"><?php echo htmlspecialchars($movie['Rating']); ?></span>
                        <h2><?php echo htmlspecialchars($movie['MovieName']); ?></h2>
                        <p><strong>Selected Date:</strong> <?php echo date('l, F j, Y', strtotime($show['ShowDate'])); ?></p>
                        <p><strong>Selected Time:</strong> <?php echo date('h:i A', strtotime($show['ShowTime'])); ?></p>
                        <p><strong>Cinema:</strong> <?php echo htmlspecialchars($show['Cinema']); ?></p>
                        <p><strong>Price:</strong> J$<?php echo number_format($ticketPrice, 2); ?> each</p>
                    </div>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert <?php echo $messageClass; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <form method="post" action="buyticket.php?movie_id=<?php echo urlencode($requestedMovieID); ?>&schedule_id=<?php echo urlencode($scheduleID); ?>" class="payment-form">
                    <div class="form-group">
                        <label for="selected_schedule_id">Choose Date and Time</label>
                        <select class="form-control" id="selected_schedule_id" name="selected_schedule_id" required>
                            <?php foreach ($movieSchedules as $scheduleOption): ?>
                                <option value="<?php echo htmlspecialchars($scheduleOption['ScheduleID']); ?>" <?php if ((int)$scheduleOption['ScheduleID'] === $scheduleID) echo 'selected'; ?>>
                                    <?php echo date('D, M j', strtotime($scheduleOption['ShowDate'])); ?> at <?php echo date('h:i A', strtotime($scheduleOption['ShowTime'])); ?> - <?php echo htmlspecialchars($scheduleOption['Cinema']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Tickets</label>
                        <input type="number" min="1" max="10" value="1" class="form-control" id="quantity" name="quantity" required>
                    </div>

                    <div class="form-group">
                        <label for="card_name">Name on Card</label>
                        <input type="text" class="form-control" id="card_name" name="card_name" placeholder="Fake payment name" required>
                    </div>

                    <div class="form-group">
                        <label for="card_number">Card Number</label>
                        <input type="text" class="form-control" id="card_number" name="card_number" placeholder="4242 4242 4242 4242" required>
                    </div>

                    <div class="payment-row">
                        <div class="form-group">
                            <label for="expiry">Expiry</label>
                            <input type="text" class="form-control" id="expiry" name="expiry" placeholder="12/30" required>
                        </div>

                        <div class="form-group">
                            <label for="cvv">CVV</label>
                            <input type="text" class="form-control" id="cvv" name="cvv" placeholder="123" required>
                        </div>
                    </div>

                    <button type="submit" name="purchase_ticket" class="btn btn-login btn-block">Pay and Reserve</button>
                    <a href="movieschedule.php?movie_id=<?php echo urlencode($requestedMovieID); ?>" class="btn btn-create btn-block">Back to Showtimes</a>
                </form>
            </div>

            <aside class="ticket-reference">
                <h2>Available Showtimes</h2>
                <p>Use this list to compare dates, times, and cinemas for <?php echo htmlspecialchars($movie['MovieName']); ?>.</p>

                <div class="ticket-showtime-list">
                    <?php foreach ($movieSchedules as $scheduleOption): ?>
                        <a class="ticket-showtime <?php echo ((int)$scheduleOption['ScheduleID'] === $scheduleID) ? 'active' : ''; ?>" href="buyticket.php?movie_id=<?php echo urlencode($requestedMovieID); ?>&schedule_id=<?php echo urlencode($scheduleOption['ScheduleID']); ?>">
                            <span><?php echo date('D, M j', strtotime($scheduleOption['ShowDate'])); ?></span>
                            <strong><?php echo date('h:i A', strtotime($scheduleOption['ShowTime'])); ?></strong>
                            <em><?php echo htmlspecialchars($scheduleOption['Cinema']); ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    </div>
</div>

</body>
</html>
