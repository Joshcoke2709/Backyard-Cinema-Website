<?php
require_once "staff_only.php";
require_once "conn.php";

$message = "";
$messageClass = "";

$timeSlots = [
    "17:00:00" => "5:00 PM - 7:30 PM",
    "18:00:00" => "6:00 PM - 8:30 PM",
    "19:00:00" => "7:00 PM - 9:30 PM",
    "20:00:00" => "8:00 PM - 10:30 PM",
    "21:00:00" => "9:00 PM - 11:30 PM"
];

$movieBlock = "03:00:00";
$todayDate = date('Y-m-d');
$editSchedule = null;

if (isset($_POST['delete_schedule'])) {
    // Delete only the selected showing, not the movie itself.
    $scheduleID = (int)$_POST['schedule_id'];

    if ($scheduleID > 0) {
        $deleteSql = "DELETE FROM schedule WHERE ScheduleID = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $scheduleID);

        if ($deleteStmt->execute()) {
            $message = "Schedule entry deleted successfully.";
            $messageClass = "alert-success";
        } else {
            $message = "Error deleting schedule entry.";
            $messageClass = "alert-danger";
        }
    }
}

if (isset($_POST['update_schedule'])) {
    // Update an existing schedule row after checking its new time is available.
    $scheduleID = (int)$_POST['schedule_id'];
    $movieID = (int)$_POST['movie_id'];
    $cinema = trim($_POST['edit_cinema']);
    $showDate = $_POST['edit_show_date'];
    $showTime = $_POST['edit_show_time'];
    $isFeatured = isset($_POST['edit_is_featured']) ? 1 : 0;

    if ($scheduleID <= 0 || $movieID <= 0 || $cinema === "" || $showDate === "" || $showTime === "") {
        $message = "Please complete all edit schedule fields.";
        $messageClass = "alert-danger";
    } else {
        // Exclude the row being edited so it does not conflict with itself.
        $checkSql = "
            SELECT ScheduleID
            FROM schedule
            WHERE Cinema = ?
              AND ShowDate = ?
              AND ? < ADDTIME(ShowTime, ?)
              AND ADDTIME(?, ?) > ShowTime
              AND ScheduleID <> ?
        ";

        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("ssssssi", $cinema, $showDate, $showTime, $movieBlock, $showTime, $movieBlock, $scheduleID);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->fetch_assoc()) {
            $message = "That time slot is already occupied for this cinema and date.";
            $messageClass = "alert-danger";
        } else {
            if ($isFeatured == 1) {
                $resetFeaturedSql = "UPDATE schedule SET IsFeatured = 0 WHERE Cinema = ? AND ShowTime = ? AND ScheduleID <> ?";
                $resetStmt = $conn->prepare($resetFeaturedSql);
                $resetStmt->bind_param("ssi", $cinema, $showTime, $scheduleID);
                $resetStmt->execute();
            }

            $updateSql = "UPDATE schedule
                          SET MovieID = ?, Cinema = ?, ShowDate = ?, ShowTime = ?, IsFeatured = ?
                          WHERE ScheduleID = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("isssii", $movieID, $cinema, $showDate, $showTime, $isFeatured, $scheduleID);

            if ($updateStmt->execute()) {
                $message = "Schedule entry updated successfully.";
                $messageClass = "alert-success";
            } else {
                $message = "Error updating schedule entry.";
                $messageClass = "alert-danger";
            }
        }
    }
}

if (isset($_POST['add_schedule'])) {
    // Add one or more new showings for a movie already in the database.
    $movieID = (int)$_POST['movie_id'];
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $cinemas = $_POST['cinema'] ?? [];
    $showDates = $_POST['show_date'] ?? [];
    $showTimes = $_POST['show_time'] ?? [];

    if ($movieID <= 0 || empty($cinemas)) {
        $message = "Please select a movie and add at least one schedule row.";
        $messageClass = "alert-danger";
    } else {
        $successCount = 0;
        $conflicts = [];
        $missingRows = 0;

        for ($i = 0; $i < count($cinemas); $i++) {
            $cinema = trim($cinemas[$i]);
            $showDate = $showDates[$i] ?? "";
            $showTime = $showTimes[$i] ?? "";

            if ($cinema === "" || $showDate === "" || $showTime === "") {
                $missingRows++;
                continue;
            }

            // Reject any three-hour block that overlaps in the same cinema and date.
            $checkSql = "
                SELECT ScheduleID
                FROM schedule
                WHERE Cinema = ?
                  AND ShowDate = ?
                  AND ? < ADDTIME(ShowTime, ?)
                  AND ADDTIME(?, ?) > ShowTime
            ";

            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ssssss", $cinema, $showDate, $showTime, $movieBlock, $showTime, $movieBlock);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->fetch_assoc()) {
                $conflicts[] = $cinema . " on " . $showDate . " at " . $showTime;
                continue;
            }

            // If featured, clear featured from same cinema/time slot
            if ($isFeatured == 1) {
                $resetFeaturedSql = "UPDATE schedule SET IsFeatured = 0 WHERE Cinema = ? AND ShowTime = ?";
                $resetStmt = $conn->prepare($resetFeaturedSql);
                $resetStmt->bind_param("ss", $cinema, $showTime);
                $resetStmt->execute();
            }

            $insertSql = "INSERT INTO schedule (MovieID, Cinema, ShowDate, ShowTime, IsFeatured)
                          VALUES (?, ?, ?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("isssi", $movieID, $cinema, $showDate, $showTime, $isFeatured);

            if ($insertStmt->execute()) {
                $successCount++;
            }
        }

        if ($successCount > 0 && empty($conflicts) && $missingRows === 0) {
            $message = "Schedule updated successfully with {$successCount} new entry/entries.";
            $messageClass = "alert-success";
        } elseif ($successCount > 0) {
            $message = "Added {$successCount} schedule entry/entries. Some rows were skipped due to conflicts or missing values.";
            $messageClass = "alert-warning";
        } else {
            $message = "No schedule entries were added due to conflicts or missing values.";
            $messageClass = "alert-danger";
        }
    }
}

// Load movies for the form, filters, and reference list.
$moviesSql = "SELECT MovieID, MovieName, Rating FROM movie ORDER BY MovieName";
$moviesResult = $conn->query($moviesSql);
$scheduleFilterMovieID = isset($_GET['schedule_movie_id']) ? (int)$_GET['schedule_movie_id'] : 0;
$selectedMovieID = isset($_POST['movie_id']) ? (int)$_POST['movie_id'] : $scheduleFilterMovieID;
$scheduleSearchTerm = isset($_GET['schedule_search']) ? trim($_GET['schedule_search']) : "";

// separate query for sidebar so pointer doesn't get consumed
$moviesListResult = $conn->query($moviesSql);
$scheduleFilterMoviesResult = $conn->query($moviesSql);

$scheduledSql = "SELECT s.ScheduleID, m.MovieID, m.MovieName, m.Rating, s.Cinema, s.ShowDate, s.ShowTime, s.IsFeatured
                 FROM schedule s
                 INNER JOIN movie m ON s.MovieID = m.MovieID
                 WHERE s.ShowDate >= ?
                 ORDER BY s.ShowDate, s.Cinema, s.ShowTime";

$scheduledStmt = $conn->prepare($scheduledSql);
$scheduledStmt->bind_param("s", $todayDate);
$scheduledStmt->execute();
$scheduledResult = $scheduledStmt->get_result();

$scheduledRows = [];
$occupiedSlots = [];
$scheduledByWeek = [];

if ($scheduledResult) {
    while ($scheduledRow = $scheduledResult->fetch_assoc()) {
        $scheduledRows[] = $scheduledRow;

        $occupiedSlots[] = [
            "id" => (int)$scheduledRow["ScheduleID"],
            "cinema" => $scheduledRow["Cinema"],
            "date" => $scheduledRow["ShowDate"],
            "time" => $scheduledRow["ShowTime"]
        ];

        $matchesMovieFilter = $scheduleFilterMovieID <= 0 || (int)$scheduledRow['MovieID'] === $scheduleFilterMovieID;
        $matchesSearchFilter = $scheduleSearchTerm === "" || stripos($scheduledRow['MovieName'], $scheduleSearchTerm) !== false;

        if (!$matchesMovieFilter || !$matchesSearchFilter) {
            continue;
        }

        // Organize the current schedule first by week, then by cinema.
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($scheduledRow['ShowDate'])));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $weekKey = $weekStart . "|" . $weekEnd;

        if (!isset($scheduledByWeek[$weekKey])) {
            $scheduledByWeek[$weekKey] = [];
        }

        if (!isset($scheduledByWeek[$weekKey][$scheduledRow['Cinema']])) {
            $scheduledByWeek[$weekKey][$scheduledRow['Cinema']] = [];
        }

        $scheduledByWeek[$weekKey][$scheduledRow['Cinema']][] = $scheduledRow;
    }
}

if (isset($_GET['edit_schedule'])) {
    // Load the chosen row into the edit form.
    $editScheduleID = (int)$_GET['edit_schedule'];
    $editSql = "SELECT s.ScheduleID, s.MovieID, m.MovieName, m.Rating, s.Cinema, s.ShowDate, s.ShowTime, s.IsFeatured
                FROM schedule s
                INNER JOIN movie m ON s.MovieID = m.MovieID
                WHERE s.ScheduleID = ?
                LIMIT 1";
    $editStmt = $conn->prepare($editSql);
    $editStmt->bind_param("i", $editScheduleID);
    $editStmt->execute();
    $editResult = $editStmt->get_result();
    $editSchedule = $editResult->fetch_assoc();
}

$editMoviesResult = $conn->query($moviesSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Schedule</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        body {
            background: #f5f5f5;
            padding: 30px;
        }

        .page-wrap {
            max-width: 1300px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
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

        .button-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .scheduled-box {
            max-width: 1300px;
            margin: 24px auto 0;
        }

        .week-box {
            margin-top: 22px;
        }

        .week-title {
            margin: 0 0 14px;
            color: #8f0718;
            font-weight: 800;
        }

        .cinema-box {
            border: 1px solid #e3d1aa;
            border-radius: 10px;
            margin: 16px 0 22px;
            overflow: hidden;
            background: #fffdf8;
        }

        .cinema-title {
            margin: 0;
            padding: 12px 16px;
            background: #8f0718;
            color: #fff;
            font-size: 17px;
            font-weight: 800;
        }

        .cinema-box .table-responsive {
            margin: 0;
        }

        .cinema-box .table {
            margin-bottom: 0;
        }

        .schedule-filter {
            display: grid;
            grid-template-columns: minmax(180px, 1.2fr) minmax(180px, 1fr) auto auto;
            gap: 12px;
            align-items: center;
            margin: 18px 0 22px;
            padding: 15px;
            border: 1px solid #e3d1aa;
            border-radius: 10px;
            background: #fffdf8;
        }

        .schedule-filter input,
        .schedule-filter select {
            min-height: 40px;
        }

        .schedule-filter .clear-filter {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 6px 14px;
            border-radius: 4px;
            background: #ffd166;
            color: #111;
            font-weight: 700;
            text-decoration: none;
        }

        .schedule-row {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fafafa;
        }

        .form-control option:disabled {
            color: #999;
            background: #e5e5e5;
        }

        @media (max-width: 900px) {
            .page-wrap {
                grid-template-columns: 1fr;
            }

            .schedule-filter {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script>
        const occupiedSlots = <?php echo json_encode($occupiedSlots); ?>;

        function updateTimeAvailability(row, currentScheduleId = 0) {
            const cinema = row.querySelector("[data-cinema-field]")?.value;
            const date = row.querySelector("[data-date-field]")?.value;
            const timeSelect = row.querySelector("[data-time-field]");

            if (!timeSelect) return;

            const toMinutes = (time) => {
                const parts = time.split(":").map(Number);
                return (parts[0] * 60) + parts[1];
            };

            Array.from(timeSelect.options).forEach((option) => {
                if (!option.value) return;

                const candidateStart = toMinutes(option.value);
                const candidateEnd = candidateStart + 180;

                const isOccupied = occupiedSlots.some((slot) =>
                    slot.cinema === cinema &&
                    slot.date === date &&
                    candidateStart < (toMinutes(slot.time) + 180) &&
                    candidateEnd > toMinutes(slot.time) &&
                    Number(slot.id) !== Number(currentScheduleId)
                );

                option.disabled = isOccupied;
                option.textContent = option.dataset.label + (isOccupied ? " - Booked" : "");

                if (isOccupied && option.selected) {
                    timeSelect.value = "";
                }
            });
        }

        function bindScheduleRow(row, currentScheduleId = 0) {
            row.querySelectorAll("[data-cinema-field], [data-date-field]").forEach((field) => {
                field.addEventListener("change", () => updateTimeAvailability(row, currentScheduleId));
            });

            updateTimeAvailability(row, currentScheduleId);
        }

        function addScheduleRow() {
            const container = document.getElementById("schedule-rows");
            const row = document.createElement("div");
            row.className = "schedule-row";
            row.innerHTML = `
                <div class="form-group">
                    <label>Cinema</label>
                    <select class="form-control" name="cinema[]" data-cinema-field required>
                        <option value="">Select Cinema</option>
                        <option value="Cinema 1">Cinema 1</option>
                        <option value="Cinema 2">Cinema 2</option>
                        <option value="Cinema 3">Cinema 3</option>
                        <option value="Cinema 4">Cinema 4</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Show Date</label>
                    <input type="date" class="form-control" name="show_date[]" data-date-field required>
                </div>

                <div class="form-group">
                    <label>Time Slot</label>
                    <select class="form-control" name="show_time[]" data-time-field required>
                        <option value="">Select Time Slot</option>
                        <option value="17:00:00" data-label="5:00 PM - 7:30 PM">5:00 PM - 7:30 PM</option>
                        <option value="18:00:00" data-label="6:00 PM - 8:30 PM">6:00 PM - 8:30 PM</option>
                        <option value="19:00:00" data-label="7:00 PM - 9:30 PM">7:00 PM - 9:30 PM</option>
                        <option value="20:00:00" data-label="8:00 PM - 10:30 PM">8:00 PM - 10:30 PM</option>
                        <option value="21:00:00" data-label="9:00 PM - 11:30 PM">9:00 PM - 11:30 PM</option>
                    </select>
                </div>

                <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">Remove Row</button>
            `;
            container.appendChild(row);
            bindScheduleRow(row);
        }

        window.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll(".schedule-row:not(.edit-schedule-row)").forEach((row) => bindScheduleRow(row));
            document.querySelectorAll(".edit-schedule-row").forEach((row) => {
                bindScheduleRow(row, row.dataset.scheduleId || 0);
            });

            setTimeout(() => {
                document.querySelectorAll(".auto-dismiss").forEach((alert) => {
                    alert.style.transition = "opacity 0.25s ease";
                    alert.style.opacity = "0";
                    setTimeout(() => alert.remove(), 300);
                });
            }, 900);
        });
    </script>
</head>
<body>

<div class="page-wrap">
    <div class="card-box">
        <h2>Update Schedule</h2>
        <p>Select an existing movie and assign it to a cinema, date, and time slot.</p>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo $messageClass; ?> auto-dismiss">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($editSchedule): ?>
            <div class="schedule-row edit-schedule-row" data-schedule-id="<?php echo htmlspecialchars($editSchedule['ScheduleID']); ?>">
                <h4>Edit Scheduled Showtime</h4>
                <form method="post" action="updateschedule.php">
                    <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($editSchedule['ScheduleID']); ?>">

                    <div class="form-group">
                        <label>Movie</label>
                        <select class="form-control" name="movie_id" required>
                            <option value="">Select Movie</option>
                            <?php if ($editMoviesResult && $editMoviesResult->num_rows > 0): ?>
                                <?php while ($movieRow = $editMoviesResult->fetch_assoc()): ?>
                                    <option value="<?php echo $movieRow['MovieID']; ?>"
                                        <?php if ((int)$editSchedule['MovieID'] === (int)$movieRow['MovieID']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($movieRow['MovieName']); ?> (<?php echo htmlspecialchars($movieRow['Rating']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cinema</label>
                        <select class="form-control" name="edit_cinema" data-cinema-field required>
                            <option value="">Select Cinema</option>
                            <option value="Cinema 1" <?php if ($editSchedule['Cinema'] === 'Cinema 1') echo 'selected'; ?>>Cinema 1</option>
                            <option value="Cinema 2" <?php if ($editSchedule['Cinema'] === 'Cinema 2') echo 'selected'; ?>>Cinema 2</option>
                            <option value="Cinema 3" <?php if ($editSchedule['Cinema'] === 'Cinema 3') echo 'selected'; ?>>Cinema 3</option>
                            <option value="Cinema 4" <?php if ($editSchedule['Cinema'] === 'Cinema 4') echo 'selected'; ?>>Cinema 4</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Show Date</label>
                        <input type="date" class="form-control" name="edit_show_date" data-date-field value="<?php echo htmlspecialchars($editSchedule['ShowDate']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Time Slot</label>
                        <select class="form-control" name="edit_show_time" data-time-field required>
                            <option value="">Select Time Slot</option>
                            <?php foreach ($timeSlots as $value => $label): ?>
                                <option value="<?php echo $value; ?>" data-label="<?php echo htmlspecialchars($label); ?>" <?php if ($editSchedule['ShowTime'] === $value) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="edit_is_featured" <?php if ((int)$editSchedule['IsFeatured'] === 1) echo 'checked'; ?>>
                            Set as featured
                        </label>
                    </div>

                    <div class="button-row">
                        <button type="submit" name="update_schedule" class="btn btn-primary">Save Schedule Changes</button>
                        <a href="updateschedule.php" class="btn btn-default">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="movie_id">Movie</label>
                <select class="form-control" id="movie_id" name="movie_id" required>
                    <option value="">Select Movie</option>
                    <?php if ($moviesResult && $moviesResult->num_rows > 0): ?>
                        <?php while ($movieRow = $moviesResult->fetch_assoc()): ?>
                            <option value="<?php echo $movieRow['MovieID']; ?>"
                                <?php if ($selectedMovieID === (int)$movieRow['MovieID']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($movieRow['MovieName']); ?> (<?php echo htmlspecialchars($movieRow['Rating']); ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <h4>Schedule Entries</h4>
            <div id="schedule-rows">
                <div class="schedule-row">
                    <div class="form-group">
                        <label>Cinema</label>
                        <select class="form-control" name="cinema[]" data-cinema-field required>
                            <option value="">Select Cinema</option>
                            <option value="Cinema 1">Cinema 1</option>
                            <option value="Cinema 2">Cinema 2</option>
                            <option value="Cinema 3">Cinema 3</option>
                            <option value="Cinema 4">Cinema 4</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Show Date</label>
                        <input type="date" class="form-control" name="show_date[]" data-date-field required>
                    </div>

                    <div class="form-group">
                        <label>Time Slot</label>
                        <select class="form-control" name="show_time[]" data-time-field required>
                            <option value="">Select Time Slot</option>
                            <?php foreach ($timeSlots as $value => $label): ?>
                                <option value="<?php echo $value; ?>" data-label="<?php echo htmlspecialchars($label); ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <button type="button" class="btn btn-info" onclick="addScheduleRow()">+ Add Another Schedule Row</button>

            <div class="checkbox">
                <label>
                    <input type="checkbox" name="is_featured" <?php if (isset($_POST['is_featured'])) echo 'checked'; ?>>
                    Set as featured
                </label>
            </div>

            <div class="button-row">
                <button type="submit" name="add_schedule" class="btn btn-primary">Add to Schedule</button>
                <a href="moviesphp.php" class="btn btn-default">Back</a>
            </div>
        </form>
    </div>

    <div class="card-box">
        <h3>Movies in Database</h3>

        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Movie ID</th>
                        <th>Movie Name</th>
                        <th>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($moviesListResult && $moviesListResult->num_rows > 0): ?>
                        <?php while ($row = $moviesListResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['MovieID']); ?></td>
                                <td><?php echo htmlspecialchars($row['MovieName']); ?></td>
                                <td><?php echo htmlspecialchars($row['Rating']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3">No movies found in the database.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="scheduled-box">
    <div class="card-box">
        <h3>Current Scheduled Movies</h3>

        <form class="schedule-filter" method="get" action="updateschedule.php">
            <input type="text" class="form-control" name="schedule_search" value="<?php echo htmlspecialchars($scheduleSearchTerm); ?>" placeholder="Search scheduled movie">

            <select class="form-control" name="schedule_movie_id">
                <option value="0">All movies</option>
                <?php if ($scheduleFilterMoviesResult && $scheduleFilterMoviesResult->num_rows > 0): ?>
                    <?php while ($movieOption = $scheduleFilterMoviesResult->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($movieOption['MovieID']); ?>" <?php if ($scheduleFilterMovieID === (int)$movieOption['MovieID']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($movieOption['MovieName']); ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($scheduleFilterMovieID > 0 || $scheduleSearchTerm !== ""): ?>
                <a class="clear-filter" href="updateschedule.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($scheduledByWeek)): ?>
            <?php foreach ($scheduledByWeek as $weekKey => $cinemaGroups): ?>
                <?php
                    [$weekStart, $weekEnd] = explode('|', $weekKey);
                    $weekTitle = date('M j', strtotime($weekStart)) . ' - ' . date('M j', strtotime($weekEnd));
                ?>
                <div class="week-box">
                    <h4 class="week-title"><?php echo htmlspecialchars($weekTitle); ?></h4>
                    <?php foreach ($cinemaGroups as $cinemaName => $weekRows): ?>
                        <div class="cinema-box">
                            <h5 class="cinema-title"><?php echo htmlspecialchars($cinemaName); ?></h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Movie</th>
                                            <th>Rating</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Featured</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($weekRows as $scheduleRow): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($scheduleRow['MovieName']); ?></td>
                                                <td><?php echo htmlspecialchars($scheduleRow['Rating']); ?></td>
                                                <td><?php echo htmlspecialchars($scheduleRow['ShowDate']); ?></td>
                                                <td><?php echo date('h:i A', strtotime($scheduleRow['ShowTime'])); ?></td>
                                                <td><?php echo $scheduleRow['IsFeatured'] ? 'Yes' : 'No'; ?></td>
                                                <td>
                                                    <a href="updateschedule.php?edit_schedule=<?php echo urlencode($scheduleRow['ScheduleID']); ?>" class="btn btn-warning btn-sm">Edit</a>

                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this schedule entry?');">
                                                        <input type="hidden" name="schedule_id" value="<?php echo $scheduleRow['ScheduleID']; ?>">
                                                        <button type="submit" name="delete_schedule" class="btn btn-danger btn-sm">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <tbody>
                        <tr>
                            <td colspan="6">No schedule entries found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
