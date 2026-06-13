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

if (isset($_POST['save_movie'])) {
    // Read one movie and any number of schedule rows submitted with it.
    $movieName = trim($_POST['movie_name']);
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

    $cinemas = $_POST['cinema'] ?? [];
    $showDates = $_POST['show_date'] ?? [];
    $showTimes = $_POST['show_time'] ?? [];

    $posterURL = "";
    $rating = "";

    // Ask OMDb for the official poster and age rating.
    $apiKey = "2ae6678a";
    $omdbUrl = "https://www.omdbapi.com/?t=" . urlencode($movieName) . "&apikey=" . $apiKey;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $omdbUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response !== false) {
        $movieData = json_decode($response, true);

        if (isset($movieData['Response']) && $movieData['Response'] === 'True') {
            $posterURL = ($movieData['Poster'] !== 'N/A') ? $movieData['Poster'] : "";
            $rating = ($movieData['Rated'] !== 'N/A') ? $movieData['Rated'] : "";
        }
    }

    // Reuse an existing movie record or insert a new one to avoid duplicates.
    $findMovieSql = "SELECT MovieID FROM movie WHERE MovieName = ? LIMIT 1";
    $findMovieStmt = $conn->prepare($findMovieSql);
    $findMovieStmt->bind_param("s", $movieName);
    $findMovieStmt->execute();
    $findMovieResult = $findMovieStmt->get_result();

    if ($findMovieRow = $findMovieResult->fetch_assoc()) {
        $movieID = (int)$findMovieRow['MovieID'];

        $updateMovieSql = "UPDATE movie SET Rating = ?, PosterURL = ? WHERE MovieID = ?";
        $updateMovieStmt = $conn->prepare($updateMovieSql);
        $updateMovieStmt->bind_param("ssi", $rating, $posterURL, $movieID);
        $updateMovieStmt->execute();
    } else {
        $insertMovieSql = "INSERT INTO movie (MovieName, PosterURL, Rating)
                           VALUES (?, ?, ?)";
        $insertMovieStmt = $conn->prepare($insertMovieSql);
        $insertMovieStmt->bind_param("sss", $movieName, $posterURL, $rating);

        if ($insertMovieStmt->execute()) {
            $movieID = $conn->insert_id;
        } else {
            $message = "Error adding movie details.";
            $messageClass = "alert-danger";
            $movieID = 0;
        }
    }

    if (!empty($movieID)) {
        $successCount = 0;
        $conflicts = [];

        for ($i = 0; $i < count($cinemas); $i++) {
            $cinema = trim($cinemas[$i]);
            $showDate = $showDates[$i] ?? "";
            $showTime = $showTimes[$i] ?? "";

            if ($cinema === "" || $showDate === "" || $showTime === "") {
                continue;
            }

            // Treat each showing as a three-hour block and reject cinema overlaps.
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

            // Keep only one featured selection for the same cinema and time slot.
            if ($isFeatured == 1) {
                $resetFeaturedSql = "UPDATE schedule 
                                     SET IsFeatured = 0 
                                     WHERE Cinema = ? AND ShowTime = ?";
                $resetStmt = $conn->prepare($resetFeaturedSql);
                $resetStmt->bind_param("ss", $cinema, $showTime);
                $resetStmt->execute();
            }

            $insertScheduleSql = "INSERT INTO schedule (MovieID, Cinema, ShowDate, ShowTime, IsFeatured)
                                  VALUES (?, ?, ?, ?, ?)";
            $insertScheduleStmt = $conn->prepare($insertScheduleSql);
            $insertScheduleStmt->bind_param("isssi", $movieID, $cinema, $showDate, $showTime, $isFeatured);

            if ($insertScheduleStmt->execute()) {
                $successCount++;
            }
        }

        if ($successCount > 0 && empty($conflicts)) {
            $message = "Movie added successfully with {$successCount} schedule entry/entries.";
            $messageClass = "alert-success";
        } elseif ($successCount > 0 && !empty($conflicts)) {
            $message = "Added {$successCount} schedule entry/entries. Some conflicts were skipped.";
            $messageClass = "alert-warning";
        } else {
            $message = "No schedule entries were added due to conflicts or missing values.";
            $messageClass = "alert-danger";
        }
    }
}

$moviesSql = "SELECT MovieID, MovieName, Rating FROM movie ORDER BY MovieName";
$moviesListResult = $conn->query($moviesSql);
$scheduleFilterMoviesResult = $conn->query($moviesSql);
$scheduleFilterMovieID = isset($_GET['schedule_movie_id']) ? (int)$_GET['schedule_movie_id'] : 0;
$scheduleSearchTerm = isset($_GET['schedule_search']) ? trim($_GET['schedule_search']) : "";

// Load upcoming schedules for the reference tables and occupied-time JavaScript.
$scheduleSql = "SELECT s.ScheduleID, m.MovieID, m.MovieName, m.Rating, s.Cinema, s.ShowDate, s.ShowTime, s.IsFeatured
                FROM schedule s
                INNER JOIN movie m ON s.MovieID = m.MovieID
                WHERE s.ShowDate >= ?
                ORDER BY s.ShowDate, s.Cinema, s.ShowTime";

$scheduleStmt = $conn->prepare($scheduleSql);
$scheduleStmt->bind_param("s", $todayDate);
$scheduleStmt->execute();
$scheduleResult = $scheduleStmt->get_result();

$occupiedSlots = [];
$scheduledByWeek = [];

if ($scheduleResult) {
    while ($scheduleRow = $scheduleResult->fetch_assoc()) {
        $occupiedSlots[] = [
            "id" => (int)$scheduleRow["ScheduleID"],
            "cinema" => $scheduleRow["Cinema"],
            "date" => $scheduleRow["ShowDate"],
            "time" => $scheduleRow["ShowTime"]
        ];

        $matchesMovieFilter = $scheduleFilterMovieID <= 0 || (int)$scheduleRow['MovieID'] === $scheduleFilterMovieID;
        $matchesSearchFilter = $scheduleSearchTerm === "" || stripos($scheduleRow['MovieName'], $scheduleSearchTerm) !== false;

        if (!$matchesMovieFilter || !$matchesSearchFilter) {
            continue;
        }

        // Group rows by week and cinema to make the maintenance list easier to scan.
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($scheduleRow['ShowDate'])));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $weekKey = $weekStart . "|" . $weekEnd;

        if (!isset($scheduledByWeek[$weekKey])) {
            $scheduledByWeek[$weekKey] = [];
        }

        if (!isset($scheduledByWeek[$weekKey][$scheduleRow['Cinema']])) {
            $scheduledByWeek[$weekKey][$scheduleRow['Cinema']] = [];
        }

        $scheduledByWeek[$weekKey][$scheduleRow['Cinema']][] = $scheduleRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Movie</title>
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

        function updateTimeAvailability(row) {
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
                    candidateEnd > toMinutes(slot.time)
                );

                option.disabled = isOccupied;
                option.textContent = option.dataset.label + (isOccupied ? " - Booked" : "");

                if (isOccupied && option.selected) {
                    timeSelect.value = "";
                }
            });
        }

        function bindScheduleRow(row) {
            row.querySelectorAll("[data-cinema-field], [data-date-field]").forEach((field) => {
                field.addEventListener("change", () => updateTimeAvailability(row));
            });

            updateTimeAvailability(row);
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
            document.querySelectorAll(".schedule-row").forEach((row) => bindScheduleRow(row));

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
        <h2>Add Movie</h2>
        <p>Enter the movie title, then add one or more schedule rows.</p>

        <form method="post" action="">
            <div class="form-group">
                <label for="movie_name">Movie Name</label>
                <input type="text" class="form-control" id="movie_name" name="movie_name" required
                       value="<?php echo isset($_POST['movie_name']) ? htmlspecialchars($_POST['movie_name']) : ''; ?>">
            </div>

            <div class="checkbox">
                <label>
                    <input type="checkbox" name="is_featured" <?php if (isset($_POST['is_featured'])) echo 'checked'; ?>>
                    Set as featured
                </label>
            </div>

            <hr>

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

            <?php if (!empty($message)): ?>
                <div class="alert <?php echo $messageClass; ?> auto-dismiss" style="margin-top:15px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="button-row">
                <button type="submit" name="save_movie" class="btn btn-success">Save Movie</button>
                <a href="moviesphp.php" class="btn btn-default">Return Home</a>
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

        <form class="schedule-filter" method="get" action="addmovie.php">
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
                <a class="clear-filter" href="addmovie.php">Clear</a>
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
