<!DOCTYPE html>
<html lang="en">
<?php
session_start();
require_once "conn.php";

  $disp="none";
  $isStaff = false;
  $isAdmin = false;
  $dispopt="";

  if (isset($_SESSION['role'])) {
      if ($_SESSION['role'] === 'supervisor' || $_SESSION['role'] === 'admin') {
          $disp = "inline";
          $isStaff = true;
      }

      if ($_SESSION['role'] === 'admin') {
          $isAdmin = true;
      }
  }

  if (!function_exists('fetch_json_url')) {
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
  }

  if (!function_exists('get_working_poster')) {
      function get_working_poster($conn, $movieID, $movieName, $storedPoster, $width = 220, $height = 330) {
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

          return "https://placehold.co/" . $width . "x" . $height . "/160b0d/ffd166?text=" . urlencode($movieName);
      }
  }

  if (!function_exists('get_movie_plot')) {
      function get_movie_plot($movieName) {
          static $plotCache = [];

          if (isset($plotCache[$movieName])) {
              return $plotCache[$movieName];
          }

          $omdbKey = "2ae6678a";
          $tmdbKey = "4bb1c5ba21c925f56751d5e98d17a83a";
          $plot = "";

          $omdbUrl = "https://www.omdbapi.com/?t=" . urlencode($movieName) . "&plot=full&apikey=" . $omdbKey;
          $omdbData = fetch_json_url($omdbUrl);

          if (
              $omdbData &&
              isset($omdbData['Response']) &&
              $omdbData['Response'] === 'True' &&
              !empty($omdbData['Plot']) &&
              $omdbData['Plot'] !== 'N/A'
          ) {
              $plot = $omdbData['Plot'];
          }

          if ($plot === "") {
              $tmdbUrl = "https://api.themoviedb.org/3/search/movie?api_key=" . $tmdbKey . "&query=" . urlencode($movieName);
              $tmdbData = fetch_json_url($tmdbUrl);

              if (!empty($tmdbData['results'])) {
                  foreach ($tmdbData['results'] as $movieResult) {
                      if (!empty($movieResult['overview'])) {
                          $plot = $movieResult['overview'];
                          break;
                      }
                  }
              }
          }

          if ($plot === "") {
              $plot = "Movie details coming soon.";
          }

          $plotCache[$movieName] = $plot;
          return $plot;
      }
  }

  $quickSchedules = [];
  $quickToday = date('Y-m-d');
  $quickSql = "SELECT s.ScheduleID, m.MovieID, m.MovieName, s.Cinema, s.ShowDate, s.ShowTime
               FROM schedule s
               INNER JOIN movie m ON s.MovieID = m.MovieID
               WHERE s.ShowDate >= ?
               ORDER BY s.Cinema, m.MovieName, s.ShowDate, s.ShowTime";
  $quickStmt = $conn->prepare($quickSql);
  $quickStmt->bind_param("s", $quickToday);
  $quickStmt->execute();
  $quickResult = $quickStmt->get_result();

  if ($quickResult) {
      while ($quickRow = $quickResult->fetch_assoc()) {
          $quickSchedules[] = [
              "scheduleID" => (int)$quickRow["ScheduleID"],
              "movieID" => (int)$quickRow["MovieID"],
              "movieName" => $quickRow["MovieName"],
              "cinema" => $quickRow["Cinema"],
              "showDate" => $quickRow["ShowDate"],
              "showDateLabel" => date('D, M j', strtotime($quickRow["ShowDate"])),
              "showTime" => $quickRow["ShowTime"],
              "showTimeLabel" => date('h:i A', strtotime($quickRow["ShowTime"]))
          ];
      }
  }

  $quickStmt->close();
 ?>

  <head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Backyard Cinemas App</title>
	 <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
   <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
   <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <!-- Stylesheet -->
    <link rel="stylesheet" href="style1m.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">	
  </head>

  <body>  
  <script>
  function openForm(movie) {
    //assign movie name
	document.getElementById("movie-name").value = movie;
	//click button programmatically
	document.getElementById('search-btn').click();
	//display pop-up
	document.getElementById("moviedetails").style.display = "block";
	document.getElementById("result").style.display = "inline";

  document.body.style.overflow = "hidden";
  }
  function closeForm() {
	  document.getElementById("moviedetails").style.display = "none";
    document.getElementById("result").innerHTML = "none";
    document.body.style.overflow = "auto";
  }
  function scrollMovies(direction, carouselId) {
    const carousel = document.getElementById(carouselId);

    if(!carousel) return;

    const firstCard = carousel.firstElementChild;
    const gap = parseFloat(window.getComputedStyle(carousel).gap) || 0;
    const scrollAmount = carouselId === "nowShowingCarousel"
      ? carousel.clientWidth
      : (firstCard ? firstCard.getBoundingClientRect().width + gap : carousel.clientWidth);
    const maxScroll = carousel.scrollWidth - carousel.clientWidth;
    let nextLeft;

    if (direction > 0) {
      nextLeft = carousel.scrollLeft >= maxScroll - 4
        ? 0
        : Math.min(carousel.scrollLeft + scrollAmount, maxScroll);
    } else {
      nextLeft = carousel.scrollLeft <= 4
        ? maxScroll
        : Math.max(carousel.scrollLeft - scrollAmount, 0);
    }

    carousel.scrollTo({
      left: nextLeft,
      behavior: "smooth"
    });
  }

  let featuredAutoplayTimer;

  function startFeaturedAutoplay() {
    const carousel = document.getElementById("featuredCarousel");
    if (!carousel) return;

    clearInterval(featuredAutoplayTimer);
    featuredAutoplayTimer = setInterval(() => {
      scrollMovies(1, "featuredCarousel");
    }, 5500);
  }

  window.addEventListener("load", startFeaturedAutoplay);
  window.addEventListener("resize", startFeaturedAutoplay);
 </script>  
  
  <nav class="navbar navbar-inverse custom-navbar">
  <div class="container-fluid">
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <a class="navbar-brand brand-logo" href="moviesphp.php">
        <span class="popcorn-mark"><span></span></span>
        <span class="brand-copy">
          <span class="brand-name">Backyard Cinemas</span>
          <span class="brand-tagline">Experience the magic of the big screen</span>
        </span>
      </a>
    </div>

    <div class="collapse navbar-collapse" id="mainNavbar">
    <ul class="nav navbar-nav">

      <li><a href="moviesphp.php">Home</a></li>
      <li><a href="movieschedule.php">Schedule</a></li>
      <!--<li><a href="#"></a></li> -->

      <li class="dropdown" style="display:<?php echo $disp ?>"> 
        <a class="dropdown-toggle" data-toggle="dropdown" href="manageemployees.php">Maintenance<span class="caret"></span></a>

        <ul class="dropdown-menu">
          <?php if ($isAdmin): ?>
          <li><a href="manageemployees.php" >Maintain Employees</a></li>
          <?php endif; ?>

          <?php if ($isStaff): ?>
          <li><a href="addmovie.php" >Add Movie</a></li>
          <?php endif; ?>
          
          <li><a href="updateschedule.php" >Update Schedule</a></li>
          <li><a href="ticketpurchases.php">Ticket Purchases</a></li>
        </ul>

      </li>
      <li><a href="#contact">Contact Us</a></li>
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

      <li> <a href="logout.php"><span class="glyphicon glyphicon-log-out"></span> Logout</a></li>

    <?php else: ?>

      <li><a href="login.php"><span class="glyphicon glyphicon-log-in"></span> Login</a></li>

    <?php endif; ?>
    </ul>
    </div>
  </div>
  </nav>
 
<!-- Featured Movies Section -->

<section class="featured-section">
  <h2 class="section-heading">Featured Movies</h2>

  <div class="carousel-wrapper">
    <button class="carousel-btn left-btn" onclick="scrollMovies(-1, 'featuredCarousel'); startFeaturedAutoplay();">&#10094;</button>

    <div class="movie-carousel featured-carousel" id="featuredCarousel">
      <?php
        $featuredToday = date('Y-m-d');
        $sql_query = "SELECT s.ScheduleID, m.MovieID, m.MovieName, m.Rating, m.PosterURL, s.Cinema, s.ShowDate, s.ShowTime
                      FROM schedule s
                      INNER JOIN movie m ON s.MovieID = m.MovieID
                      WHERE s.IsFeatured = 1
                        AND s.ShowDate >= ?
                      ORDER BY s.ShowDate, s.ShowTime";
        $featuredStmt = $conn->prepare($sql_query);
        $featuredStmt->bind_param("s", $featuredToday);
        $featuredStmt->execute();
        $result = $featuredStmt->get_result();
        $shownFeaturedMovies = [];

        if ($result) {
          while ($row = $result->fetch_assoc()) {
            $movie = $row["MovieName"];
            $scheduleID = $row["ScheduleID"];
            $movieID = $row["MovieID"];

            if (isset($shownFeaturedMovies[$movieID])) {
              continue;
            }

            $shownFeaturedMovies[$movieID] = true;

            $cinema = $row["Cinema"];
            $showdate = $row["ShowDate"];
            $showtime = $row["ShowTime"];
            $rating = $row["Rating"];
            $poster = get_working_poster($conn, (int)$movieID, $movie, $row["PosterURL"], 520, 760);
            $plot = get_movie_plot($movie);
            $posterFallback = "https://placehold.co/520x760/160b0d/ffd166?text=" . urlencode($movie);
      ?>
        <div class="featured-banner" data-movie="<?php echo htmlspecialchars($movie); ?>">
          <div class="featured-text">
            <h1 class="featured-title"><?php echo htmlspecialchars($movie); ?></h1>

            <div class="featured-age-rating">
              <?php echo htmlspecialchars($rating); ?>
            </div>

            <p class="featured-meta"><?php echo htmlspecialchars($plot); ?></p>

            <div class="featured-actions">
              <button class="btn featured-btn"
                onclick='openForm("<?php echo htmlspecialchars($movie, ENT_QUOTES); ?>")'>
                View Details
              </button>

              <a class="btn buy-ticket-btn" href="buyticket.php?movie_id=<?php echo urlencode($movieID); ?>&schedule_id=<?php echo urlencode($scheduleID); ?>">
                Buy Tickets
              </a>

              <?php if ($isStaff): ?>
                <a class="btn btn-warning"
                   href="updateschedule.php">
                   Edit Schedule
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="featured-image-wrap">
            <img
              src="<?php echo htmlspecialchars($poster); ?>"
              alt="<?php echo htmlspecialchars($movie); ?>"
              class="featured-poster"
              onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($posterFallback); ?>';"
            >
          </div>
        </div>
      <?php
          }
          $featuredStmt->close();
        }
      ?>
    </div>

    <button class="carousel-btn right-btn" onclick="scrollMovies(1, 'featuredCarousel'); startFeaturedAutoplay();">&#10095;</button>
  </div>
</section>

<section class="quick-booking-section">
  <div class="quick-booking-panel">
    <h2><i class="fa-solid fa-ticket"></i> Quick Booking</h2>

    <div class="quick-booking-form">
      <select id="quickMovie" aria-label="Select movie">
        <option value="">Select Movie*</option>
      </select>

      <select id="quickDate" aria-label="Select date" disabled>
        <option value="">Select Date*</option>
      </select>

      <select id="quickShowtime" aria-label="Select showtime" disabled>
        <option value="">Select Showtime*</option>
      </select>

      <div class="quick-cinema-result" id="quickCinemaResult">Cinema will appear here</div>

      <button type="button" id="quickBookBtn" class="quick-book-btn" disabled>Buy Ticket</button>
    </div>
  </div>
</section>

<script>
  const quickSchedules = <?php echo json_encode($quickSchedules); ?>;

  function uniqueBy(items, keyFn) {
    const seen = new Set();
    return items.filter((item) => {
      const key = keyFn(item);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function resetQuickSelect(select, placeholder) {
    select.innerHTML = `<option value="">${placeholder}</option>`;
    select.disabled = true;
  }

  function fillQuickSelect(select, items, valueFn, labelFn, placeholder) {
    select.innerHTML = `<option value="">${placeholder}</option>`;
    items.forEach((item) => {
      const option = document.createElement("option");
      option.value = valueFn(item);
      option.textContent = labelFn(item);
      select.appendChild(option);
    });
    select.disabled = items.length === 0;
  }

  window.addEventListener("DOMContentLoaded", () => {
    const movieSelect = document.getElementById("quickMovie");
    const dateSelect = document.getElementById("quickDate");
    const showtimeSelect = document.getElementById("quickShowtime");
    const cinemaResult = document.getElementById("quickCinemaResult");
    const bookBtn = document.getElementById("quickBookBtn");

    if (!movieSelect || !quickSchedules.length) return;

    fillQuickSelect(
      movieSelect,
      uniqueBy(quickSchedules, (item) => item.movieID),
      (item) => item.movieID,
      (item) => item.movieName,
      "Select Movie*"
    );

    movieSelect.addEventListener("change", () => {
      resetQuickSelect(dateSelect, "Select Date*");
      resetQuickSelect(showtimeSelect, "Select Showtime*");
      cinemaResult.textContent = "Cinema will appear here";
      bookBtn.disabled = true;

      const dates = uniqueBy(
        quickSchedules.filter((item) => String(item.movieID) === movieSelect.value),
        (item) => item.showDate
      );

      fillQuickSelect(dateSelect, dates, (item) => item.showDate, (item) => item.showDateLabel, "Select Date*");
    });

    dateSelect.addEventListener("change", () => {
      resetQuickSelect(showtimeSelect, "Select Showtime*");
      cinemaResult.textContent = "Cinema will appear here";
      bookBtn.disabled = true;

      const showtimes = quickSchedules.filter((item) =>
        String(item.movieID) === movieSelect.value &&
        item.showDate === dateSelect.value
      );

      fillQuickSelect(
        showtimeSelect,
        showtimes,
        (item) => item.scheduleID,
        (item) => item.showTimeLabel,
        "Select Showtime*"
      );
    });

    showtimeSelect.addEventListener("change", () => {
      bookBtn.disabled = showtimeSelect.value === "";
      const selectedSchedule = quickSchedules.find((item) => String(item.scheduleID) === showtimeSelect.value);
      cinemaResult.textContent = selectedSchedule ? selectedSchedule.cinema : "Cinema will appear here";
    });

    bookBtn.addEventListener("click", () => {
      if (showtimeSelect.value !== "") {
        const selectedSchedule = quickSchedules.find((item) => String(item.scheduleID) === showtimeSelect.value);
        const moviePart = selectedSchedule ? `movie_id=${encodeURIComponent(selectedSchedule.movieID)}&` : "";
        window.location.href = `buyticket.php?${moviePart}schedule_id=${encodeURIComponent(showtimeSelect.value)}`;
      }
    });
  });
</script>

  <!-- Now Showing Section -->

  <section class="hero-section text-center">
  <div class="hero-overlay">
    <h1 class="now-showing-title">Now Showing</h1>

      <div class="carousel-wrapper">
    <button class="carousel-btn left-btn" onclick="scrollMovies(-1, 'nowShowingCarousel')">&#10094;</button>

    <div class="movie-carousel" id="nowShowingCarousel">

    <?php if ($isStaff): ?>
        <a href="addmovie.php" class="movie-card add-movie-card">
          <div class="add-card-inner">
            <i class="fa-solid fa-circle-plus add-icon"></i>
            <h3 class="movie-title">Add Movie</h3>
          </div>
        </a>
      <?php endif; ?>

      <?php 
        require_once "conn.php";
        $todayDate = date('Y-m-d');

        if ($isStaff) {
          $sql_query = "SELECT MovieID, MovieName, Rating, PosterURL
                        FROM movie
                        ORDER BY MovieName";
          $result = $conn->query($sql_query);
        } else {
          $sql_query = "SELECT m.MovieID, m.MovieName, m.Rating, m.PosterURL
                        FROM movie m
                        WHERE EXISTS (
                          SELECT 1
                          FROM schedule s
                          WHERE s.MovieID = m.MovieID
                            AND s.ShowDate >= ?
                        )
                        ORDER BY m.MovieName";
          $nowShowingStmt = $conn->prepare($sql_query);
          $nowShowingStmt->bind_param("s", $todayDate);
          $nowShowingStmt->execute();
          $result = $nowShowingStmt->get_result();
        }

            if ($result) {
              while ($row = $result->fetch_assoc()) {
                $movie = $row["MovieName"];
                $movieID = $row["MovieID"];
                $rating = $row["Rating"];
                $poster = get_working_poster($conn, (int)$movieID, $movie, $row["PosterURL"], 345, 330);
                $posterFallback = "https://placehold.co/345x330/160b0d/ffd166?text=" . urlencode($movie);
                $nextScheduleID = null;
                $nextScheduleSql = "SELECT ScheduleID FROM schedule WHERE MovieID = ? AND ShowDate >= ? ORDER BY ShowDate, ShowTime LIMIT 1";
                $nextScheduleStmt = $conn->prepare($nextScheduleSql);
                $nextScheduleStmt->bind_param("is", $movieID, $todayDate);
                $nextScheduleStmt->execute();
                $nextScheduleResult = $nextScheduleStmt->get_result();

                if ($nextScheduleRow = $nextScheduleResult->fetch_assoc()) {
                  $nextScheduleID = $nextScheduleRow["ScheduleID"];
                }
        ?>
        <div class="movie-card" data-movie="<?php echo htmlspecialchars($movie); ?>" >
        <img src="<?php echo htmlspecialchars($poster); ?>" alt="<?php echo htmlspecialchars($movie); ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($posterFallback); ?>';">

        <div class="movie-card-body">
            <div class="movie-title-row">
              <h3 class="movie-title"><?php echo htmlspecialchars($movie); ?></h3>
              <div class="movie-age-rating">
                <?php echo htmlspecialchars($rating); ?>
              </div>
            </div>

            <div class="movie-card-actions">
              <button class="btn view-btn btn-sm" onclick='openForm("<?php echo htmlspecialchars($movie, ENT_QUOTES); ?>")'>
                View Details
              </button>
              <?php if ($nextScheduleID !== null): ?>
                <a class="btn buy-ticket-btn btn-sm" href="buyticket.php?movie_id=<?php echo urlencode($movieID); ?><?php if ($nextScheduleID !== null): ?>&schedule_id=<?php echo urlencode($nextScheduleID); ?><?php endif; ?>">
                  Buy Tickets
                </a>
              <?php endif; ?>
            </div>

            <?php if ($isStaff) : ?>
              <div class="staff-actions">
                <a class="btn btn-warning btn-sm" href="editmovie.php?movie_id=<?php echo urlencode($movieID); ?>">Edit Movie</a>

                <a class="btn btn-warning btn-sm" href="updateschedule.php?schedule_movie_id=<?php echo urlencode($movieID); ?>">Edit Schedule</a>

                <a class="btn btn-danger btn-sm" href="deletemovie.php?movie_id=<?php echo urlencode($movieID); ?>" onclick="return confirm('Delete this movie and all of its scheduled showtimes?');">Delete</a>
              </div>
              <?php endif; ?>
          </div>
        </div>
      <?php 
          }
        }
      ?>
    </div>

    <button class="carousel-btn right-btn" onclick="scrollMovies(1, 'nowShowingCarousel')">&#10095;</button>
  </div>
  </div>
  
</section>

  <!-- Today's Schedule Section -->

  <?php 
    $homeScheduleDate = date('Y-m-d');
    $sql_query = "SELECT s.ScheduleID, m.MovieID, m.MovieName, m.Rating, m.PosterURL, s.Cinema, s.ShowDate, s.ShowTime
                  FROM schedule s
                  INNER JOIN movie m ON s.MovieID = m.MovieID
                  WHERE s.ShowDate = ?
                  ORDER BY s.ShowTime";

    $stmt = $conn->prepare($sql_query);
    $stmt->bind_param("s", $homeScheduleDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $todayMovies = [];
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $movieKey = (int)$row['MovieID'];

        if (!isset($todayMovies[$movieKey])) {
          $todayMovies[$movieKey] = [
            'MovieID' => $movieKey,
            'MovieName' => $row['MovieName'],
            'Rating' => $row['Rating'],
            'PosterURL' => $row['PosterURL'],
            'ShowDate' => $row['ShowDate'],
            'showtimes' => []
          ];
        }

        $todayMovies[$movieKey]['showtimes'][] = [
          'ScheduleID' => (int)$row['ScheduleID'],
          'Cinema' => $row['Cinema'],
          'ShowTime' => $row['ShowTime']
        ];
      }
    }
  ?>

  <?php if (!empty($todayMovies)): ?>
  <section class="schedule-section">
  <div class="today-showtimes-panel">
    <div class="today-showtimes-heading">
      <h2 class="section-title">Today's Schedule</h2>
      <p>Showtimes for <?php echo date('l, F j, Y', strtotime($homeScheduleDate)); ?></p>
    </div>

    <div class="today-showtimes-list">
          <?php 
                foreach ($todayMovies as $todayMovie) {
                  $movie = $todayMovie["MovieName"];
                  $movieID = $todayMovie["MovieID"];
                  $showdate = $todayMovie["ShowDate"];
                  $rating = $todayMovie["Rating"];
                  $poster = get_working_poster($conn, (int)$movieID, $movie, $todayMovie["PosterURL"], 220, 330);
                  $posterFallback = "https://placehold.co/220x330/160b0d/ffd166?text=" . urlencode($movie);
          ?>
          <article class="today-movie-card">
            <img src="<?php echo htmlspecialchars($poster); ?>" alt="<?php echo htmlspecialchars($movie); ?>" class="today-poster" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($posterFallback); ?>';">

            <div class="today-copy">
              <h3><?php echo htmlspecialchars($movie); ?></h3>

              <dl class="today-facts">
                <div>
                  <dt>Show Date:</dt>
                  <dd><?php echo date('l, F j, Y', strtotime($showdate)); ?></dd>
                </div>
                <div>
                  <dt>Showtimes:</dt>
                  <dd class="today-showtime-links">
                    <?php foreach ($todayMovie['showtimes'] as $showtime): ?>
                      <a class="time-pill" href="buyticket.php?movie_id=<?php echo urlencode($movieID); ?>&schedule_id=<?php echo urlencode($showtime['ScheduleID']); ?>">
                        <?php echo date('h:i A', strtotime($showtime['ShowTime'])); ?>
                      </a>
                    <?php endforeach; ?>
                  </dd>
                </div>
              </dl>

              <div class="today-detail-box">
                <span class="movie-age-rating"><?php echo htmlspecialchars($rating); ?></span>
                <p>This showing is rated <?php echo htmlspecialchars($rating); ?>. Please check that the rating is suitable before booking tickets.</p>
              </div>

              <button class="btn view-btn btn-sm" onclick='openForm("<?php echo htmlspecialchars($movie, ENT_QUOTES); ?>")'>
                View Details
              </button>

              <a class="btn buy-ticket-btn" href="buyticket.php?movie_id=<?php echo urlencode($movieID); ?>&schedule_id=<?php echo urlencode($todayMovie['showtimes'][0]['ScheduleID']); ?>">
                Buy Tickets
              </a>

              <?php if ($isStaff) : ?>
                <div class="staff-actions">
                <a class="btn btn-warning btn-sm" href="updateschedule.php">Edit Schedule</a>

                <a class="btn btn-danger btn-sm" href="deletemovie.php?movie_id=<?php echo urlencode($movieID); ?>" onclick="return confirm('Delete this movie and all of its scheduled showtimes?');">Delete</a>
                </div>
              <?php endif; ?>
            </div>
          </article>
          <?php 
                }
            $stmt->close();
          ?>
    </div>
  </div>
</section>
  <?php else: ?>
    <?php $stmt->close(); ?>
  <?php endif; ?>
  	
    <div class="movie-popup" id="moviedetails" style="display:none">
	  <button id="closewin" class="btn btn-danger btn-sm" onclick="closeForm()">Close</button> 

      <div class="search-container" style="display:none">
        <input
          type="text"
          id="movie-name"
          value="Dark Knight"
        />
        <button id="search-btn"></button>
      </div>

      <div id="result" style="display:none"></div>
    </div>
    <footer id="contact" class="site-footer">
  <div class="footer-content">
    <div class="footer-section">
      <h3>Backyard Cinemas</h3>
      <p>Experience the magic of the big screen in Portmore, Jamaica.</p>
    </div>

    <div class="footer-section">
      <h4>Contact Us</h4>
      <p><strong>Location:</strong> Portmore, St. Catherine, Jamaica</p>
      <p><strong>Email:</strong> info@backyardcinemas.com</p>
      <p><strong>Phone:</strong> (876) 555-1234</p>
    </div>

    <div class="footer-section">
      <h4>Opening Hours</h4>
      <p>Monday - Friday: 5:00 PM - 11:30 PM</p>
      <p>Saturday - Sunday: 12:00 PM - 11:30 PM</p>
    </div>
  </div>

  <div class="footer-bottom">
    <p>&copy; <?php echo date('Y'); ?> Backyard Cinemas. All rights reserved.</p>
  </div>
</footer>
    <script src="script.js"></script>
  </body>
</html>   
