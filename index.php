<?php

session_start();

// Get current year
$now = new DateTime();
$current_year = $now->format("Y");

$action = filter_input(INPUT_POST, 'action');

// Get user input
$location = filter_input(INPUT_POST, "location");
$latitude = filter_input(INPUT_POST, "latitude", FILTER_VALIDATE_FLOAT);
$longitude = filter_input(INPUT_POST, "longitude", FILTER_VALIDATE_FLOAT);

$start_year = filter_input(INPUT_POST, "year_start", FILTER_VALIDATE_INT);
$end_year_input = filter_input(INPUT_POST, "year_end");
$end_year = ($end_year_input === null || $end_year_input === "") ? null : filter_var($end_year_input, FILTER_VALIDATE_INT);

$month = filter_input(INPUT_POST, "month") ?? 1;

$message = "";

if (!isset($_SESSION["saved_locations"]) || !is_array($_SESSION["saved_locations"])) {
    $_SESSION["saved_locations"] = [];
}

// Statistics
$high_month = "";
$low_month = "";
$high_year = "";
$low_year = "";
$low_diff = "";
$high_diff = "";
$year_average = "";
$anomaly = "";


// Get current location


// Validate user input only after the form is submitted.
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "start_search") {
    if ($start_year === null || $start_year === false) {
        $message = "Start year cannot be empty.";
    } elseif ($start_year < 2000 || $start_year > $current_year) {
        $message = "Data is only available from 2000 to " . $current_year . ".";
    } elseif ($end_year === false) {
        $message = "End year must be a valid year.";
    } elseif ($end_year !== null && ($end_year < 2000 || $end_year > $current_year)) {
        $message = "Data is only available from 2000 to " . $current_year . ".";
    } elseif ($end_year !== null && $start_year > $end_year) {
        $message = "Start year cannot be after end year.";
    }
}

// Save location
if ($action === "save_location") {
    if (empty($location)) {
        $message = "Enter a location before saving it.";
    } elseif (!in_array($location, $_SESSION["saved_locations"], true)) {
        $_SESSION["saved_locations"][] = $location;
    }
}

if (isset($_POST["remove_saved_location"])) {
    $saved_location_key = array_search($_POST["remove_saved_location"], $_SESSION["saved_locations"], true);
    if ($saved_location_key !== false) {
        unset($_SESSION["saved_locations"][$saved_location_key]);
    }
}

if (isset($_POST["use_saved_location"]) && in_array($_POST["use_saved_location"], $_SESSION["saved_locations"], true)) {
    $location = $_POST["use_saved_location"];
}


/*
// Connect to Flask
$url = "http://";

$data = json_encode([
    'location' => $location,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'month' => $month,
    'start-year' => $start_year,
    'end-year' => $end_year
]);

// Send request with cURL
$curl = curl_init($url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_PORT, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json', 
    'Content-Length: '.strlen($data)
]);

// Execute request and close connection
$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
// Closes automatically -> curl_close() is deprecated

// Decode and use json response from python
if($httpCode === 200 && $response){
    $result = json_decode($response, true);
    echo "Python response: ".htmlspecialchars($result['processed_text']);
}else{
    echo "Error connecting to Flask. (HTTP Status: $httpCode)";
}

*/

?>



<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fire Activity Data | FIRMS</title>
        <link rel="stylesheet" href="fire.css">
    </head>
    <body>
        <header>
            <div>
                <h2>FIRMS</h2>
                <h3>Fire Information for Resource Management System</h3>
            </div>
        </header>
        <main class="content">
            <form class="filter-container" method="post" action="" id="form">
                <fieldset class="filter location-filter">
                    <legend>Location</legend>
                    <input class="location-tab-input" type="radio" id="location-search" name="location_mode" value="search" <?php echo filter_input(INPUT_POST, "location_mode") !== "saved" || isset($_POST["use_saved_location"]) ? "checked" : ""; ?>>
                    <input class="location-tab-input" type="radio" id="location-saved" name="location_mode" value="saved" <?php echo filter_input(INPUT_POST, "location_mode") === "saved" && !isset($_POST["use_saved_location"]) ? "checked" : ""; ?>>

                    <div class="location-tabs">
                        <label for="location-search">Search</label>
                        <label for="location-saved">Saved</label>
                    </div>

                    <div class="location-panels">
                        <div class="location-panel search-panel">
                            <label for="location-search-input">Search for a location</label>
                            <div class="location-search-row">
                                <input id="location-search-input" name="location" type="text" placeholder="City, region, or coordinates" value="<?php echo htmlspecialchars($location ?? "", ENT_QUOTES, "UTF-8"); ?>">
                                <button class="save-location-button" type="submit" name="action" value="save_location">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M6 3.75h12a1 1 0 0 1 1 1v15l-7-4.5-7 4.5v-15a1 1 0 0 1 1-1Z"></path>
                                    </svg>
                                    <span class="visually-hidden">Save location</span>
                                </button>
                            </div>
                            <div>
                                <a class="current-location-link" href="#current-location" id="current-location-link">
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12Z"></path>
                                        <circle cx="12" cy="9" r="2.25"></circle>
                                    </svg>
                                    Use current location
                                </a>
                                <p class="location-status" id="location-status" aria-live="polite"></p>

                                <input type="hidden" name="latitude" id="latitude">
                                <input type="hidden" name="longitude" id="longitude">

                                
                            </div>
                        </div>
                        <div class="location-panel saved-panel">
                            <span class="filter-label" id="saved-location-label">Saved locations</span>
                            <div class="saved-location-list" id="saved-location-list" role="group" aria-labelledby="saved-location-label">
                                <?php foreach ($_SESSION["saved_locations"] as $saved_location): ?>
                                    <div class="saved-location-item">
                                        <button class="saved-location-option" type="submit" name="use_saved_location" value="<?php echo htmlspecialchars($saved_location, ENT_QUOTES, "UTF-8"); ?>">
                                            <?php echo htmlspecialchars($saved_location, ENT_QUOTES, "UTF-8"); ?>
                                        </button>
                                        <button class="remove-saved-location" type="submit" name="remove_saved_location" value="<?php echo htmlspecialchars($saved_location, ENT_QUOTES, "UTF-8"); ?>" aria-label="Remove <?php echo htmlspecialchars($saved_location, ENT_QUOTES, "UTF-8"); ?> from saved locations">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (empty($_SESSION["saved_locations"])): ?>
                                <p class="saved-hint">No saved locations yet. Save a location from the Search tab to see it here.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>
                <div class="date-filter-group">
                    <div class="filter year-range-filter">
                        <span class="filter-label">Year</span>
                        <div class="year-range-inputs">
                            <input id="year-from" name="year_start" type="number" step="1" placeholder="From" value="<?php echo $start_year ?>">
                            <input id="year-to" name="year_end" type="number" step="1" placeholder="To (Optional)" min="2001" max="2026" value="<?php echo $end_year ?>">
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
                    <div>
                        <button class="search-submit" type="submit" name="action" id="submit" value="start_search">Start search</button>
                        <p class="error-msg"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></p>
                    </div>
                </div>
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
                        <h4>Highest fire activity month: <?php echo $high_month?></h4>
                        <h4>Lowest fire activity month: <?php echo $low_month?></h4>
                        <h4>Highest fire activity year: <?php echo $high_year?></h4>
                        <h4>Lowest fire activity year: <?php echo $low_year?></h4>
                        <h4>Highest fire activity difference
                            <button class="stat-tooltip" type="button" aria-label="More information about highest fire activity difference" aria-describedby="tooltip-highest-difference">
                                ?
                                <span class="stat-tooltip-text" id="tooltip-highest-difference" role="tooltip">The month with the highest difference in fire activty between between years of selected range.</span>
                            </button>:
                            <?php echo $high_diff?>
                        </h4>
                        <h4>Lowest fire activity difference
                            <button class="stat-tooltip" type="button" aria-label="More information about lowest fire activity difference" aria-describedby="tooltip-lowest-difference">
                                ?
                                <span class="stat-tooltip-text" id="tooltip-lowest-difference" role="tooltip">The month with the lowest difference in fire activty between between years of selected range.</span>
                            </button>:
                            <?php echo $low_diff?>
                        </h4>
                        <h4>Year fire activity average: <?php echo $year_average?></h4>
                        <h4>Sensor anomaly
                            <button class="stat-tooltip" type="button" aria-label="More information about sensor anomaly" aria-describedby="tooltip-sensor-anomaly">
                                ?
                                <span class="stat-tooltip-text" id="tooltip-sensor-anomaly" role="tooltip">The percentage of mismatch between VIIRS and MODIS activity detection.</span>
                            </button>:
                            <?php echo $anomaly?>
                        </h4>
                        <button class="csv-download-button" type="button">Download CSV</button>
                    </div>
                </div>
            </section>
        </main>


        <script>

            // Get current location
            const form = document.getElementById("form");
            const submitBtn = document.getElementById("submit");
            const locationLink = document.getElementById("current-location-link");
            const latitudeInput = document.getElementById("latitude");
            const longitudeInput = document.getElementById("longitude");
            const locationStatus = document.getElementById("location-status");

            locationLink.addEventListener("click", function(event) {
                event.preventDefault();

                if (!navigator.geolocation) {
                    locationStatus.textContent = "Location is not supported by this browser.";
                    return;
                }

                locationStatus.textContent = "Requesting your location...";
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        latitudeInput.value = position.coords.latitude;
                        longitudeInput.value = position.coords.longitude;
                        form.requestSubmit(submitBtn);
                    },
                    function(error) {
                        if (error.code === error.PERMISSION_DENIED) {
                            locationStatus.textContent = "Location permission was denied.";
                        } else if (error.code === error.TIMEOUT) {
                            locationStatus.textContent = "The location request timed out. Please try again.";
                        } else {
                            locationStatus.textContent = "Your location could not be determined.";
                        }
                    },
                    { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
                );
            });
        </script>


    </body>
</html>