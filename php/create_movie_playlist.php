<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
// https://github.com/gogetta69/TMDB-To-VOD-Playlist

$GLOBALS['DEBUG'] = false;
set_time_limit(0); // Remove PHP's time restriction

if ($GLOBALS['DEBUG'] !== true) {
    error_reporting(0);	
} else {

}	


//Set globals
$apiKey = getenv('SECRET_API_KEY');
$playVodUrl = "[[SERVER_URL]]/play.php";
$totalPages = 500; // Added more pages due to strict filters.
$minYear = 1; // Skip older titles
$minRuntime = 0; // In Minutes. Works with /discover only.
$language = 'it-IT';
$movies_with_origin_country = 'IT';
$num = 0;
$outputData = [];
$outputContent = "#EXTM3U\n";
$addedMovieIds = [];
$addedTimestamp = time();

fetchMovies($playVodUrl, $language, $apiKey, $totalPages);

function fetchMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $listType, $outputData, $outputContent, $num;
	
	$vixMoviesUrl = 'https://vixsrc.to/api/list/movie/?lang=it';
    echo "Recupero la lista dei film da: $vixMoviesUrl<br>";
    $vixMovies = fetchAndHandleErrors($vixMoviesUrl, 'Request for Vix movie list failed.');

    if ($vixMovies !== null && is_array($vixMovies)) {
        $movieIds = array_map(function($item) {
            // Assicurati che tmdb_id sia un intero
            return (int)$item['tmdb_id'];
        }, $vixMovies);

        // Rimuovi eventuali ID duplicati o non validi
        $movieIds = array_unique(array_filter($movieIds));

        $movieIds = array_map(function($item) {
            return $item['tmdb_id'];
        }, $vixMovies);

        echo "Trovati " . count($movieIds) . " film. Processo i dettagli...<br>";

        foreach ($movieIds as $movieId) {
            $movieDetailUrl = "https://api.themoviedb.org/3/movie/$movieId?api_key=$apiKey&language=$language";
            $movie = fetchAndHandleErrors($movieDetailUrl, "Request for movie ID $movieId failed.");
            if ($movie !== null) {
                processMovieData($movie, $playVodUrl);
            }
        }
    }

    // Aggiungi film da TMDB: Popolari e Adesso in onda
    echo "Recupero film popolari da TMDB...<br>";
    fetchFromTmdbEndpoint("movie/popular", $apiKey, $language, $totalPages, $playVodUrl);
    echo "Recupero film 'Adesso in onda' da TMDB...<br>";
    fetchFromTmdbEndpoint("movie/now_playing", $apiKey, $language, $totalPages, $playVodUrl);

    //Save the Json and M3U8 Data
    file_put_contents('playlist.m3u8', $outputContent);

    file_put_contents('playlist.json', json_encode($outputData));

    echo "Generazione completata. Trovati in totale $num film.<br>";
	return;
}

// Function to fetch and handle errors for a URL
function fetchAndHandleErrors($url, $errorMessage)
{
    try {
        $response = file_get_contents($url);

        if ($response !== false) {
            $data = json_decode($response, true);
            if ($data !== null) {
                return $data;
            } else {
                error_log($errorMessage . ' Invalid JSON format');
            }
        } else {
            error_log($errorMessage . ' Request failed');
        }
    }
    catch (exception $error) {
        error_log($errorMessage . ' ' . $error->getMessage());
    }
    return null;
}

function fetchFromTmdbEndpoint($endpoint, $apiKey, $language, $totalPages, $playVodUrl)
{
    for ($page = 1; $page <= $totalPages; $page++) {
        $url = "https://api.themoviedb.org/3/{$endpoint}?api_key={$apiKey}&language={$language}&page={$page}";
        $data = fetchAndHandleErrors($url, "Request for {$endpoint} page {$page} failed.");

        if ($data !== null && isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $movie) {
                processMovieData($movie, $playVodUrl);
            }
        } else {
            // Se non ci sono risultati o si verifica un errore, interrompi il ciclo per questo endpoint
            echo "Nessun altro risultato per {$endpoint} a pagina {$page}. Interruzione.<br>";
            break;
        }
    }
}

function processMovieData($movie, $playVodUrl) {
    global $outputData, $outputContent, $addedMovieIds, $num;

    if (!isValidMovie($movie)) {
        return;
    }

    $id = $movie['id'];
    if (isset($addedMovieIds[$id])) {
        return; // Skip if already added
    }

    // Extract data
    $timestamp = isset($movie['release_date']) ? strtotime($movie['release_date']) : strtotime('1970-01-01');
    $date = !empty($movie['release_date']) ? $movie['release_date'] : '1970-01-01';
    $year = substr($date, 0, 4);
    $title = $movie['title'];
    $poster = 'https://image.tmdb.org/t/p/original' . $movie['poster_path'];

    // Determine category from genre
    $genreName = 'Film'; // Default
    $categoryId = '999990'; // Default
    if (!empty($movie['genres'])) {
        $genreName = $movie['genres'][0]['name'];
        $categoryId = $movie['genres'][0]['id'];
    }

    // JSON data
    $movieData = [
        "num" => ++$num,
        "name" => $title . ' (' . $year . ')',
        "stream_type" => "movie",
        "stream_id" => $id,
        "stream_icon" => $poster,
        "rating" => $movie['vote_average'] ?? 0,
        "rating_5based" => isset($movie['vote_average']) ? ($movie['vote_average'] / 2) : 0,
        "added" => $timestamp,
        "category_id" => $categoryId,
        "container_extension" => "mp4",
        "custom_sid" => null,
        "direct_source" => $playVodUrl . '?movieId=' . $id,
        "plot" => $movie['overview'],
        "backdrop_path" => 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'],
        "group" => $genreName
    ];

    // Mark as added and store data
    $addedMovieIds[$id] = true;
    $outputData[] = $movieData;

    // M3U8 data
    $outputContent .= "#EXTINF:-1 group-title=\"$genreName\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";
}

function measureExecutionTime($func, ...$params) {
    $start = microtime(true);

    call_user_func($func, ...$params);

    $end = microtime(true);
    $elapsedTime = $end - $start;

    $minutes = (int) ($elapsedTime / 60);
    $seconds = $elapsedTime % 60;
    $milliseconds = ($seconds - floor($seconds)) * 1000;

    echo "Total Execution Time for $func: " . $minutes . " minute(s) and " . floor($seconds) . "." . sprintf('%03d', $milliseconds) . " second(s)</br>";
}

function isValidMovie($movie) {
		global $minYear;
    // Check if movie has a poster image
    if (empty($movie['poster_path'])) {
        return false;
    }
    
    // Check release year
    $releaseDate = $movie['release_date'] ?? '1970-01-01';
    $year = (int)substr($releaseDate, 0, 4);
    
    // Skip movies older than 1970
    return $year >= $minYear;
}
?>
