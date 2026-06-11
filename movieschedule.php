<?php
session_start();
require_once "conn.php";

$disp = "none";
if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'supervisor')) {
    $disp = "inline";
}

function fetch_json_url($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false || $response === "") {
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function get_working_poster($conn, $movieID, $movieName, $storedPoster) {
    $badAmazonStub = !empty($storedPoster) && preg_match('/m\.media-amazon\.com\/images\/M\/[^.]*@$/', $storedPoster);

    if (!empty($storedPoster) && strpos($storedPoster, 'placehold.co') === false && !$badAmazonStub) {
        return $storedPoster;
    }

    $poster = "";
    $omdbKey = "2ae6678a";
    $tmdbKey = "4bb1c5ba21c925f56751d5e98d17a83a";

    $omdbUrl = "https://www.omdbapi.com/?t=" . urlencode($movieName) . "&apikey=" . $omdbKey;
    $omdbData = fetch_json_url($omdbUrl);

    if (
        $omdbData &&
        isset($omdbData['Response']) &&
        $omdbData['Response'] === 'True' &&
        !empty($omdbData['Poster']) &&
        $omdbData['Poster'] !== 'N/A'
    ) {
        $poster = $omdbData['Poster'];
    }

    if ($poster === "") {
        $tmdbUrl = "https://api.themoviedb.org/3/search/movie?api_key=" . $tmdbKey . "&query=" . urlencode($movieName);
        $tmdbData = fetch_json_url($tmdbUrl);

        if (!empty($tmdbData['results'])) {
            foreach ($tmdbData['results'] as $movieResult) {
                if (!empty($movieResult['poster_path'])) {
                    $poster = "https://image.tmdb.org/t/p/w500" . $movieResult['poster_path'];
                    break;
                }
            }
        }
    }

    if ($poster !== "") {
        $updateSql = "UPDATE movie SET PosterURL = ? WHERE MovieID = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $poster, $movieID);
        $updateStmt->execute();
        return $poster;
    }

    return "https://placehold.co/220x330/160b0d/ffd166?text=" . urlencode($movieName);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Movie Schedule</title>

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

<link rel="stylesheet" href="style3.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">	
</head>

<body>

<nav class="navbar navbar-inverse custom-navbar">
  <div class="container-fluid">
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#scheduleNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <a class="navbar-brand brand-logo" href="moviesphp.php">
        <span class="popcorn-mark"><span></span></span>
        <span>Backyard Cinemas</span>
      </a>
    </div>

    <div class="collapse navbar-collapse" id="scheduleNavbar">
    <ul class="nav navbar-nav">
      <li><a href="moviesphp.php">Home</a></li>
      <li><a href="movieschedule.php">Schedule</a></li>

      <li class="dropdown" style="display:<?php echo $disp ?>"> 
        <a class="dropdown-toggle" data-toggle="dropdown" href="#">
          Maintenance<span class="caret"></span>
        </a>
        <ul class="dropdown-menu">
          <li><a href="manageemployees.php" >Maintain Employees</a></li>
          <li><a href="addmovie.php" >Add Movie</a></li>
          <li><a href="updateschedule.php" >Update Schedule</a></li>
          <li><a href="ticketpurchases.php">Ticket Purchases</a></li>
        </ul>
      </li>

      <li><a href="#">Contact Us</a></li>
    </ul>

    <ul class="nav navbar-nav navbar-right">
      <?php if (isset($_SESSION['role'])): ?>
        <li class="navbar-text">
          <?php
            $fullName = isset($_SESSION['emp_name']) ? $_SESSION['emp_name'] : $_SESSION['patron_name'];
            $firstName = strtok(trim($fullName), " ");
          ?>
          Welcome, <?php echo htmlspecialchars($firstName); ?>
        </li>
        <li>
          <a href="logout.php">
            <span class="glyphicon glyphicon-log-out"></span> Logout
          </a>
        </li>
      <?php else: ?>
        <li>
          <a href="login.php">
            <span class="glyphicon glyphicon-log-in"></span> Login
          </a>
        </li>
      <?php endif; ?>
    </ul>
    </div>
  </div>
</nav>

<header class="schedule-hero">
  <div>
    <p class="eyebrow">Backyard Cinemas</p>
    <h1 class="belownav">This Week's Schedule</h1>
    <p class="hero-copy">Pick your night, find your screen, and arrive hungry.</p>
  </div>
</header>

<?php
$scheduleDates = [];
$moviesFilter = [];
$todayDate = date('Y-m-d');
$datesSql = "SELECT DISTINCT ShowDate FROM schedule WHERE ShowDate >= ? ORDER BY ShowDate";
$datesStmt = $conn->prepare($datesSql);
$datesStmt->bind_param("s", $todayDate);
$datesStmt->execute();
$datesResult = $datesStmt->get_result();

if ($datesResult) {
    while ($dateRow = $datesResult->fetch_assoc()) {
        $scheduleDates[] = $dateRow['ShowDate'];
    }
}

$moviesFilterSql = "SELECT DISTINCT m.MovieID, m.MovieName
                    FROM schedule s
                    INNER JOIN movie m ON s.MovieID = m.MovieID
                    WHERE s.ShowDate >= ?
                    ORDER BY m.MovieName";
$moviesFilterStmt = $conn->prepare($moviesFilterSql);
$moviesFilterStmt->bind_param("s", $todayDate);
$moviesFilterStmt->execute();
$moviesFilterResult = $moviesFilterStmt->get_result();

if ($moviesFilterResult) {
    while ($movieFilterRow = $moviesFilterResult->fetch_assoc()) {
        $moviesFilter[] = $movieFilterRow;
    }
}

$selectedDate = !empty($scheduleDates) ? $scheduleDates[0] : date('Y-m-d');
$selectedMovieID = isset($_GET['movie_id']) ? (int)$_GET['movie_id'] : 0;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : "";

if (isset($_GET['date'])) {
    $requestedDate = DateTime::createFromFormat('Y-m-d', $_GET['date']);
    if (
        $requestedDate &&
        $requestedDate->format('Y-m-d') === $_GET['date'] &&
        (empty($scheduleDates) || in_array($_GET['date'], $scheduleDates))
    ) {
        $selectedDate = $_GET['date'];
    }
}

$filterActive = $selectedMovieID > 0 || $searchTerm !== "";
$sql_query = "SELECT s.ScheduleID, m.MovieID, m.MovieName, m.Rating, m.PosterURL, s.Cinema, s.ShowDate, s.ShowTime
              FROM schedule as s
              INNER JOIN movie m ON s.MovieID = m.MovieID
              WHERE ";
$params = [];
$types = "";

if ($filterActive) {
    $sql_query .= "s.ShowDate >= ?";
    $params[] = $todayDate;
    $types .= "s";

    if ($selectedMovieID > 0) {
        $sql_query .= " AND m.MovieID = ?";
        $params[] = $selectedMovieID;
        $types .= "i";
    }

    if ($searchTerm !== "") {
        $movieSearch = "%" . $searchTerm . "%";
        $sql_query .= " AND m.MovieName LIKE ?";
        $params[] = $movieSearch;
        $types .= "s";
    }

    $sql_query .= " ORDER BY s.ShowDate, s.ShowTime, s.Cinema";
} else {
    $sql_query .= "s.ShowDate = ? ORDER BY s.ShowTime, m.MovieName, s.Cinema";
    $params[] = $selectedDate;
    $types .= "s";
}

$stmt = $conn->prepare($sql_query);
$bindValues = [];
$bindValues[] = $types;
foreach ($params as $key => $value) {
    $bindValues[] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bindValues);
$stmt->execute();
$result = $stmt->get_result();

$scheduledMovies = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $scheduleKey = (int)$row['MovieID'] . '|' . $row['ShowDate'];

        if (!isset($scheduledMovies[$scheduleKey])) {
            $scheduledMovies[$scheduleKey] = [
                'MovieID' => (int)$row['MovieID'],
                'MovieName' => $row['MovieName'],
                'Rating' => $row['Rating'],
                'PosterURL' => $row['PosterURL'],
                'ShowDate' => $row['ShowDate'],
                'showtimes' => []
            ];
        }

        $scheduledMovies[$scheduleKey]['showtimes'][] = [
            'ScheduleID' => (int)$row['ScheduleID'],
            'ShowTime' => $row['ShowTime']
        ];
    }
}
?>

<main class="showtimes-page">
  <section class="showtimes-control">
    <div class="showtimes-heading">
      <h2>Showtimes</h2>
      <p>
        <?php if ($filterActive): ?>
          Filtered upcoming Backyard Cinemas showtimes
        <?php else: ?>
          All Backyard Cinemas showtimes for <?php echo date('l, F j, Y', strtotime($selectedDate)); ?>
        <?php endif; ?>
      </p>
    </div>

    <form class="schedule-filter" method="get" action="movieschedule.php">
      <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search movie title">

      <select name="movie_id">
        <option value="0">All movies</option>
        <?php foreach ($moviesFilter as $movieOption): ?>
          <option value="<?php echo htmlspecialchars($movieOption['MovieID']); ?>" <?php if ($selectedMovieID === (int)$movieOption['MovieID']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($movieOption['MovieName']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit">Filter</button>
      <?php if ($filterActive): ?>
        <a href="movieschedule.php" class="clear-filter">Clear</a>
      <?php endif; ?>
    </form>

    <div class="calendar-row">
      <span class="now-playing-label">Now playing</span>
      <div class="date-scroller" aria-label="Choose a show date">
        <?php foreach ($scheduleDates as $dateValue):
          $isActive = $dateValue === $selectedDate;
        ?>
          <a class="date-tile <?php echo (!$filterActive && $isActive) ? 'active' : ''; ?>" href="movieschedule.php?date=<?php echo $dateValue; ?>">
            <span><?php echo strtoupper(date('D', strtotime($dateValue))); ?></span>
            <strong><?php echo date('j', strtotime($dateValue)); ?></strong>
          </a>
        <?php endforeach; ?>

        <?php if (empty($scheduleDates)): ?>
          <span class="date-tile active">
            <span><?php echo strtoupper(date('D', strtotime($selectedDate))); ?></span>
            <strong><?php echo date('j', strtotime($selectedDate)); ?></strong>
          </span>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="movieschedule-section">
    <div class="schedule-list">
      <?php if (!empty($scheduledMovies)): ?>
        <?php foreach ($scheduledMovies as $scheduledMovie):
          $poster = get_working_poster($conn, $scheduledMovie["MovieID"], $scheduledMovie["MovieName"], $scheduledMovie["PosterURL"]);
          $posterFallback = "https://placehold.co/220x330/160b0d/ffd166?text=" . urlencode($scheduledMovie["MovieName"]);
          $rating = !empty($scheduledMovie["Rating"]) ? $scheduledMovie["Rating"] : "NR";
        ?>
          <article class="schedule-movie-card">
            <img src="<?php echo htmlspecialchars($poster); ?>" alt="<?php echo htmlspecialchars($scheduledMovie["MovieName"]); ?>" class="schedule-poster" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($posterFallback); ?>';">

            <div class="schedule-copy">
              <h3><?php echo htmlspecialchars($scheduledMovie["MovieName"]); ?></h3>

              <dl class="schedule-facts">
                <div>
                  <dt>Show Date:</dt>
                  <dd><?php echo date('l, F j, Y', strtotime($scheduledMovie["ShowDate"])); ?></dd>
                </div>
                <div>
                  <dt>Showtimes:</dt>
                  <dd class="schedule-showtime-links">
                    <?php foreach ($scheduledMovie['showtimes'] as $showtime): ?>
                      <a class="schedule-time" href="buyticket.php?movie_id=<?php echo urlencode($scheduledMovie['MovieID']); ?>&schedule_id=<?php echo urlencode($showtime['ScheduleID']); ?>">
                        <?php echo date('h:i A', strtotime($showtime['ShowTime'])); ?>
                      </a>
                    <?php endforeach; ?>
                  </dd>
                </div>
              </dl>

              <div class="movie-detail-box">
                <span class="rating-badge"><?php echo htmlspecialchars($rating); ?></span>
                <p>
                  This showing is rated <?php echo htmlspecialchars($rating); ?>.
                  Please check that the rating is suitable before booking tickets.
                </p>
              </div>

              <a class="btn buy-ticket-btn" href="buyticket.php?movie_id=<?php echo urlencode($scheduledMovie['MovieID']); ?>&schedule_id=<?php echo urlencode($scheduledMovie['showtimes'][0]['ScheduleID']); ?>">
                Buy Tickets
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="empty-state">No movies scheduled for <?php echo date('l, F j, Y', strtotime($selectedDate)); ?>.</p>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php $stmt->close(); ?>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const dateScroller = document.querySelector(".date-scroller");
  const activeDate = dateScroller ? dateScroller.querySelector(".date-tile.active") : null;

  if (!dateScroller || !activeDate) {
    return;
  }

  requestAnimationFrame(function () {
    dateScroller.scrollLeft = Math.max(0, activeDate.offsetLeft - dateScroller.offsetLeft);
  });
});
</script>

</body>
</html>
