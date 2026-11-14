<?php

// Get user input
$location = filter_input(INPUT_POST, "location") ?? $current_location;

$start_year = filter_input(INPUT_POST, "year_start", FILTER_VALIDATE_INT);
$end_year = filter_input(INPUT_POST, "year_end", FILTER_VALIDATE_INT);

$month = filter_input(INPUT_POST, "month") ?? 1;

// Get current year
$now = new DateTime();
$current_year = $now->format("Y");

// Get current location 


// Error Message
function ErrorMsg($error){
    $message = $error;
}

// Validate user input
if($start_year = ""){
    ErrorMsg("Start year cannot be empty.");
}elseif($start_year < 2000 || $start_year > 2026){
    ErrorMsg("Data is only available from 2000 to 2026.");
}elseif($start_year > $end_year){
    ErrorMsg("Start year cannot be after end year.");
}else{
    ErrorMsg("");
}






?>



<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fire Activity Data | FIRMS</title>
        <link rel="stylesheet" href="map.css">
    </head>
    <body>
        <header>
            <div>
                <h2>FIRMS</h2>
                <h3>Fire Information for Resource Management System</h3>
            </div>
        </header>
        <main class="content">
            <form class="filter-container" method="post" action="">
                <fieldset class="filter location-filter">
                    <legend>Location</legend>
                    <input class="location-tab-input" type="radio" id="location-search" name="location_mode" value="search" checked>
                    <input class="location-tab-input" type="radio" id="location-saved" name="location_mode" value="saved">

                    <div class="location-tabs">
                        <label for="location-search">Search</label>
                        <label for="location-saved">Saved</label>
                    </div>

                    <div class="location-panels">
                        <div class="location-panel search-panel">
                            <label for="location-search-input">Search for a location</label>
                            <div class="location-search-row">
                                <input id="location-search-input" name="location" type="text" placeholder="City, region, or coordinates">
                            </div>
                            <div>
                                <button class="current-location-link" type="submit" name="action" value="use_current_location">
                                <svg aria-hidden="true" viewBox="0 0 24 24">
                                    <path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12Z"></path>
                                    <circle cx="12" cy="9" r="2.25"></circle>
                                </svg>
                                Use current location
                                </button>
                                <button class="save-location-button" type="submit" name="action" value="save_location">
                                <svg aria-hidden="true" viewBox="0 0 24 24">
                                    <path d="M6 3.75h12a1 1 0 0 1 1 1v15l-7-4.5-7 4.5v-15a1 1 0 0 1 1-1Z"></path>
                                </svg>
                                <span class="visually-hidden">Save location</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <div class="date-filter-group">
                    <div class="filter year-range-filter">
                        <span class="filter-label">Year</span>
                        <div class="year-range-inputs">
                            <input id="year-from" name="year_from" type="number" step="1" placeholder="From" min="2000" max="2026">
                            <input id="year-to" name="year_to" type="number" step="1" placeholder="To" min="2000" max="2026">
                        </div>
                    </div>
                    <div class="filter">
                        <label for="month">Month (optional)</label>
                        <select id="month" name="month">
                            <option value="" selected disabled>Select a month</option>
                            <option value="1">January</option>
                            <option value="2">February</option>
                            <option value="3">March</option>
                            <option value="4">April</option>
                            <option value="5">May</option>
                            <option value="6">June</option>
                            <option value="7">July</option>
                            <option value="8">August</option>
                            <option value="9">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                    </div>
                    <p><?php $message ?></p>
                </div>
                <button class="search-submit" type="submit" name="action" value="start_search">Start search</button>
            </form>
            <section class="result-container">
                <input class="view-tab-input" type="radio" id="view-map" name="view_mode" value="map" checked>
                <input class="view-tab-input" type="radio" id="view-data" name="view_mode" value="data">

                <div class="tabs">
                    <label for="view-map">Map</label>
                    <label for="view-data">Data</label>
                </div>

                <div class="panels">
                    <div class="panel map-panel">
                        <p>Fire activity map will appear here</p>
                    </div>
                    <div class="panel data-panel">
                        <p>Statistics</p>
                    </div>
                </div>
            </section>

            
        </main>
    </body>
</html>