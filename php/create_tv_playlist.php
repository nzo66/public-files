<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
//https://github.com/gogetta69/TMDB-To-VOD-Playlist

$GLOBALS['DEBUG'] = false;
set_time_limit(0); // Remove PHP's time restriction
if ($GLOBALS['DEBUG'] !== true) {
    error_reporting(0);	
} else {
}	

//Set globals
$apiKey = getenv('SECRET_API_KEY');
$playVodUrl = "[[SERVER_URL]]/play.php";
$totalPages = 500;
$minYear = 1; // Skip older titles
$language = 'it-IT';
$series_with_origin_country = 'IT';
$num = 0;
$outputData = [];
$outputContent = "#EXTM3U\n";
$addedMovieIds = [];
$addedTimestamp = time();
fetchSeries($playVodUrl, $language, $apiKey, $totalPages);
function fetchSeries($playVodUrl, $language, $apiKey, $totalPages)
{
    global $listType, $outputData, $outputContent, $num;	

    $vixSeriesUrl = 'https://vixsrc.to/api/list/tv/?lang=it';
    echo "Recupero la lista delle serie TV da: $vixSeriesUrl<br>";
    $vixSeries = fetchAndHandleErrors($vixSeriesUrl, 'Request for Vix series list failed.');

    if ($vixSeries !== null && is_array($vixSeries)) {
        $seriesIds = array_map(function($item) {
            return $item['tmdb_id'];
        }, $vixSeries);

        echo "Trovate " . count($seriesIds) . " serie TV. Processo i dettagli...<br>";

        foreach ($seriesIds as $seriesId) {
            $seriesDetailUrl = "https://api.themoviedb.org/3/tv/$seriesId?api_key=$apiKey&language=$language";
            $series = fetchAndHandleErrors($seriesDetailUrl, "Request for series ID $seriesId failed.");
            if ($series !== null) {
                processSeriesData($series, $playVodUrl);
            }
        }
    }

    //Save the Json and M3U8 Data (commented out since its not good with tv series).
    //file_put_contents('tv_playlist.m3u8', $outputContent);
    file_put_contents('tv_playlist.json', json_encode($outputData));
    echo "Generazione completata. Trovate in totale $num serie TV.<br>";
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

function processSeriesData($show, $playVodUrl)
{
    global $outputData, $outputContent, $addedMovieIds, $num;

    if (!isValidSeries($show)) {
        return;
    }

    $id = $show['id'];
    if (isset($addedMovieIds[$id])) {
        return; // Skip if already added
    }

    // Extract data
    $timestamp = isset($show['first_air_date']) ? strtotime($show['first_air_date']) : strtotime('1970-01-01');
    $date = !empty($show['first_air_date']) ? $show['first_air_date'] : '1970-01-01';
    $year = substr($date, 0, 4);
    $title = $show['name'];
    $poster = 'https://image.tmdb.org/t/p/original' . $show['poster_path'];

    // Determine category from genre
    $genreName = 'Serie TV'; // Default
    $categoryId = '999999'; // Default
    if (!empty($show['genres'])) {
        $genreName = $show['genres'][0]['name'];
        $categoryId = $show['genres'][0]['id'];
    }

    // JSON data
    $showData = [
        "num" => ++$num,
        "name" => $title . ' (' . $year . ')',
        "series_id" => $id,
        "cover" => $poster,
        "plot" => $show['overview'],
        "cast" => "",
        "director" => "",
        "genre" => $genreName,
        "releaseDate" => $date,
        "last_modified" => $timestamp,
        "rating" => $show['vote_average'] ?? 0,
        "rating_5based" => isset($show['vote_average']) ? ($show['vote_average'] / 2) : 0,
        "backdrop_path" => ['https://image.tmdb.org/t/p/original' . $show['backdrop_path']],
        "youtube_trailer" => "",
        "episode_run_time" => "",
        "category_id" => $categoryId
    ];

    // Mark as added and store data
    $addedMovieIds[$id] = true;
    $outputData[] = $showData;

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
function isValidSeries($series) {
    global $minYear;
    // Check if series has a poster image
    if (empty($series['poster_path'])) {
        return false;
    }
    
    // Check first air date year
    $firstAirDate = $series['first_air_date'] ?? '1970-01-01';
    $year = (int)substr($firstAirDate, 0, 4);
    
    // Skip series older than minYear
    return $year >= $minYear;
}
?>