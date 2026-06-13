<?php
require_once "conn.php";
require_once "staff_only.php";

$movieID = 0;

if (isset($_GET['movie_id'])) {
    $movieID = (int)$_GET['movie_id'];
} elseif (isset($_GET['movie'])) {
    $movie = trim($_GET['movie']);
    $findSql = "SELECT MovieID FROM movie WHERE MovieName = ? LIMIT 1";
    $findStmt = $conn->prepare($findSql);
    $findStmt->bind_param("s", $movie);
    $findStmt->execute();
    $findResult = $findStmt->get_result();
    $row = $findResult->fetch_assoc();

    if ($row) {
        $movieID = (int)$row['MovieID'];
    }
}

if ($movieID <= 0) {
    die("Movie not found.");
}

// A transaction makes both deletes succeed together or fail together.
$conn->begin_transaction();

try {
    // Child schedule rows must be removed before their parent movie row.
    $deleteScheduleSql = "DELETE FROM schedule WHERE MovieID = ?";
    $deleteScheduleStmt = $conn->prepare($deleteScheduleSql);
    $deleteScheduleStmt->bind_param("i", $movieID);
    $deleteScheduleStmt->execute();

    $deleteMovieSql = "DELETE FROM movie WHERE MovieID = ?";
    $deleteMovieStmt = $conn->prepare($deleteMovieSql);
    $deleteMovieStmt->bind_param("i", $movieID);
    $deleteMovieStmt->execute();

    if ($deleteMovieStmt->affected_rows < 1) {
        throw new Exception("Movie not found.");
    }

    $conn->commit();
    header("Location: moviesphp.php");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    echo "Error deleting movie.";
}
?>
