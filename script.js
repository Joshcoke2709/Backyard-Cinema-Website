const key = "2ae6678a";

const movieNameRef = document.getElementById("movie-name");
const searchBtn = document.getElementById("search-btn");
const result = document.getElementById("result");

function getMovie() {
  let movieName = movieNameRef.value.trim();
  let url = `https://www.omdbapi.com/?t=${encodeURIComponent(movieName)}&apikey=${key}`;

  if (movieName.length <= 0) {
    result.innerHTML = `<h3 class="msg">Please Enter A Movie Name</h3>`;
    return;
  }

  fetch(url)
    .then((resp) => resp.json())
    .then((data) => {
      if (data.Response === "True") {
        result.innerHTML = `
          <div class="info">
            <div class="poster-frame">
              <img src="${data.Poster !== "N/A" ? data.Poster : "https://via.placeholder.com/220x320?text=No+Image"}" class="poster">
            </div>
            <div class="popup-copy">
              <span class="rating-badge">${data.Rated}</span>
              <h2>${data.Title}</h2>
              <div class="details">
                <span>${data.Year}</span>
                <span>${data.Runtime}</span>
              </div>
              <div class="genre">
                <div>${data.Genre.split(",").join("</div><div>")}</div>
              </div>
              <div class="popup-text-block">
                <h3>Plot</h3>
                <p>${data.Plot}</p>
              </div>
              <div class="popup-text-block">
                <h3>Cast</h3>
                <p>${data.Actors}</p>
              </div>
              <div class="trailer-section">
                <button class="btn view-btn btn-sm" id="trailer-btn">View Trailer</button>
                <div id="trailer-container" style="display:none; margin-top:15px;"></div>
              </div>
            </div>
          </div>
        `;
        
          const trailerBtn = document.getElementById("trailer-btn");
          if (trailerBtn) {
            trailerBtn.addEventListener("click", () => {
              loadTrailer(data.Title, data.Year);
            });
          }
      } else {
        result.innerHTML = `<h3 class='msg'>${data.Error}</h3>`;
      }
    })
    .catch((error) => {
      console.log("Popup error:", error);
      result.innerHTML = `<h3 class="msg">Error Occurred</h3>`;
    });
}

const tmdbKey = "4bb1c5ba21c925f56751d5e98d17a83a";

async function loadTrailer(movieTitle, year = "") {
  const trailerContainer = document.getElementById("trailer-container");
  const trailerBtn = document.getElementById("trailer-btn");

  if (!trailerContainer || !trailerBtn) return;

  trailerBtn.disabled = true;
  trailerBtn.textContent = "Loading trailer...";

  try {
    // 1) Search movie on TMDb
    const searchUrl = `https://api.themoviedb.org/3/search/movie?api_key=${tmdbKey}&query=${encodeURIComponent(movieTitle)}`;
    const searchResp = await fetch(searchUrl);
    const searchData = await searchResp.json();

    if (!searchData.results || searchData.results.length === 0) {
      trailerContainer.style.display = "block";
      trailerContainer.innerHTML = `<p>No trailer found.</p>`;
      trailerBtn.textContent = "View Trailer";
      trailerBtn.disabled = false;
      return;
    }

    const movieId = searchData.results[0].id;

    // 2) Get videos for that movie
    const videosUrl = `https://api.themoviedb.org/3/movie/${movieId}/videos?api_key=${tmdbKey}`;
    const videosResp = await fetch(videosUrl);
    const videosData = await videosResp.json();

    if (!videosData.results || videosData.results.length === 0) {
      trailerContainer.style.display = "block";
      trailerContainer.innerHTML = `<p>No trailer found.</p>`;
      trailerBtn.textContent = "View Trailer";
      trailerBtn.disabled = false;
      return;
    }

    // Prefer YouTube trailer
    const trailer = videosData.results.find(
      (video) =>
        video.site === "YouTube" &&
        video.type === "Trailer"
    ) || videosData.results.find(
      (video) => video.site === "YouTube"
    );

    if (!trailer) {
      trailerContainer.style.display = "block";
      trailerContainer.innerHTML = `<p>No trailer found.</p>`;
      trailerBtn.textContent = "View Trailer";
      trailerBtn.disabled = false;
      return;
    }

    const youtubeEmbed = `https://www.youtube.com/embed/${trailer.key}?autoplay=1&rel=0`;

    trailerContainer.style.display = "block";
    trailerContainer.innerHTML = `
      <iframe
        width="100%"
        height="315"
        src="${youtubeEmbed}"
        title="Movie Trailer"
        frameborder="0"
        allow="autoplay; encrypted-media; fullscreen"
        allowfullscreen>
      </iframe>
    `;

    trailerBtn.style.display = "none";
  } catch (error) {
    console.log("Trailer error:", error);
    trailerContainer.style.display = "block";
    trailerContainer.innerHTML = `<p>Unable to load trailer.</p>`;
    trailerBtn.textContent = "View Trailer";
    trailerBtn.disabled = false;
  }
}

function fillMovieData(container, movieName, isFeatured = false) {
  if (!movieName) return;

  const poster = isFeatured
    ? container.querySelector(".featured-poster")
    : container.querySelector(".movie-poster");

  const fallback = isFeatured
    ? "https://placehold.co/420x560?text=No+Image"
    : "https://placehold.co/220x330?text=No+Image";

  const url = `https://www.omdbapi.com/?t=${encodeURIComponent(movieName)}&apikey=${key}`;

  fetch(url)
    .then((resp) => resp.json())
    .then((data) => {
      if (data.Response === "True") {
        if (poster) {
          poster.src =
            data.Poster && data.Poster !== "N/A"
              ? data.Poster
              : fallback;
          poster.alt = data.Title;
        }

      } else {
        if (poster) poster.src = fallback;
      }
    })
    .catch((error) => {
      console.log("Fetch error for", movieName, error);
      if (poster) poster.src = fallback;
    });
}

function loadMovieCards() {
  const cards = document.querySelectorAll(".movie-card[data-movie]");
  cards.forEach((card) => {
    fillMovieData(card, card.dataset.movie, false);
  });

  const featuredBanners = document.querySelectorAll(".featured-banner[data-movie]");
  featuredBanners.forEach((banner) => {
    fillMovieData(banner, banner.dataset.movie, true);
  });
}


window.addEventListener("load", loadMovieCards);
;

if (searchBtn) {
  searchBtn.addEventListener("click", getMovie);
}
