<?php
require_once "conn.php";
require_once "staff_only.php";

$message = "";
$movieID = isset($_GET['movie_id']) ? (int)$_GET['movie_id'] : 0;

if ($movieID <= 0 && isset($_GET['movie'])) {
    $movieName = trim($_GET['movie']);
    $findSql = "SELECT MovieID FROM movie WHERE MovieName = ? LIMIT 1";
    $findStmt = $conn->prepare($findSql);
    $findStmt->bind_param("s", $movieName);
    $findStmt->execute();
    $findResult = $findStmt->get_result();

    if ($findRow = $findResult->fetch_assoc()) {
        $movieID = (int)$findRow['MovieID'];
    }
}

if ($movieID <= 0) {
    die("Movie not found.");
}

if (isset($_POST['update_movie'])) {
    $movieName = trim($_POST['movie_name']);

    if ($movieName === "") {
        $message = "Movie name is required.";
    } else {
        $updateSql = "UPDATE movie SET MovieName = ? WHERE MovieID = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $movieName, $movieID);

        if ($updateStmt->execute()) {
            $message = "Movie updated successfully.";
        } else {
            $message = "Error updating movie.";
        }
    }
}

$movieSql = "SELECT MovieID, MovieName, Rating, PosterURL FROM movie WHERE MovieID = ? LIMIT 1";
$movieStmt = $conn->prepare($movieSql);
$movieStmt->bind_param("i", $movieID);
$movieStmt->execute();
$movieResult = $movieStmt->get_result();
$movie = $movieResult->fetch_assoc();

if (!$movie) {
    die("Movie not found.");
}

$scheduledSql = "SELECT s.ScheduleID, m.MovieName, m.Rating, s.Cinema, s.ShowDate, s.ShowTime, s.IsFeatured
                 FROM schedule s
                 INNER JOIN movie m ON s.MovieID = m.MovieID
                 WHERE s.ShowDate >= CURDATE()
                 ORDER BY s.ShowDate, s.Cinema, s.ShowTime";

$scheduledResult = $conn->query($scheduledSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Movie</title>
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
            grid-template-columns: 0.85fr 1.35fr;
            gap: 24px;
            align-items: start;
        }

        .card-box {
            background: #ffffff;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 8px 18px rgba(0,0,0,0.08);
        }

        .movie-poster {
            width: 150px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .rating-pill {
            display: inline-block;
            background: #111;
            color: #fff;
            border-radius: 5px;
            padding: 5px 11px;
            font-weight: bold;
            margin-bottom: 14px;
        }

        .button-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
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
        <h2>Edit Movie</h2>
        <p>Use this page for title corrections. Ratings are imported automatically when movies are added.</p>

        <?php if (!empty($movie['PosterURL'])): ?>
            <img class="movie-poster" src="<?php echo htmlspecialchars($movie['PosterURL']); ?>" alt="<?php echo htmlspecialchars($movie['MovieName']); ?>">
        <?php endif; ?>

        <div>
            <span class="rating-pill"><?php echo htmlspecialchars($movie['Rating']); ?></span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="editmovie.php?movie_id=<?php echo urlencode($movieID); ?>">
            <div class="form-group">
                <label>Movie Name</label>
                <input type="text" class="form-control" name="movie_name"
                       value="<?php echo htmlspecialchars($movie['MovieName']); ?>" required>
            </div>

            <div class="button-row">
                <button type="submit" name="update_movie" class="btn btn-primary">Update Movie</button>
                <a href="updateschedule.php" class="btn btn-warning">Edit Schedule</a>
                <a href="moviesphp.php" class="btn btn-default">Back</a>
            </div>
        </form>
    </div>

    <div class="card-box">
        <h3>Current Scheduled Movies</h3>
        <p>Use Update Schedule to change cinema, date, time, or featured status.</p>

        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Movie</th>
                        <th>Rating</th>
                        <th>Cinema</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Featured</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($scheduledResult && $scheduledResult->num_rows > 0): ?>
                        <?php while ($scheduleRow = $scheduledResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($scheduleRow['MovieName']); ?></td>
                                <td><?php echo htmlspecialchars($scheduleRow['Rating']); ?></td>
                                <td><?php echo htmlspecialchars($scheduleRow['Cinema']); ?></td>
                                <td><?php echo htmlspecialchars($scheduleRow['ShowDate']); ?></td>
                                <td><?php echo date('h:i A', strtotime($scheduleRow['ShowTime'])); ?></td>
                                <td><?php echo $scheduleRow['IsFeatured'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">No schedule entries found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
