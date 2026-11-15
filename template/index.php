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

$month = filter_input(INPUT_POST, "month");

$message = "";
$result = null;

if (!isset($_SESSION["saved_locations"]) || !is_array($_SESSION["saved_locations"])) {
    $_SESSION["saved_locations"] = [];
}

// Validate user input only after the form is submitted.
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "start_search") {
    if ($start_year === null || $start_year === false) {
        $message = "Start year cannot be empty.";
    } elseif ($start_year < 2003 || $start_year > $current_year) {
        $message = "Data is only available from 2003 to " . $current_year . ".";
    } elseif ($end_year === false) {
        $message = "End year must be a valid year.";
    } elseif ($end_year !== null && ($end_year < 2003 || $end_year > $current_year)) {
        $message = "Data is only available from 2003 to " . $current_year . ".";
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

if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "start_search" && $message === "") {
    $location_text = trim((string) ($location ?? ""));
    if (preg_match('/^\s*(-?(?:\d+(?:\.\d*)?|\.\d+))\s*,\s*(-?(?:\d+(?:\.\d*)?|\.\d+))\s*$/', $location_text, $matches)) {
        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];
    }

    if ($latitude === false || $longitude === false) {
        $message = "Enter valid latitude and longitude coordinates.";
    } elseif ($latitude === null || $longitude === null) {
        $message = "Use current location or enter coordinates like 34.05, -118.25. City-name lookup is not configured.";
    } elseif ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        $message = "Latitude must be between -90 and 90 and longitude between -180 and 180.";
    } else {
        if ($location_text === "") {
            $location = $latitude . ", " . $longitude;
        }

        $payload = json_encode([
            "latitude" => $latitude,
            "longitude" => $longitude,
            "year_start" => $start_year,
            "year_end" => $end_year,
            "month" => $month,
            "radius_km" => 50
        ]);
        if ($payload === false) {
            $message = "Could not encode the search request.";
        } elseif (!function_exists("curl_init")) {
            $message = "PHP cURL is not enabled. Enable the cURL extension to contact Flask.";
        } else {
            $api_url = getenv("FLASK_API_URL") ?: "http://127.0.0.1:5000/api/fire-search";
            $curl = curl_init($api_url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 30
            ]);
            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($curl);
            unset($curl);

            if ($response === false) {
                $message = "Could not connect to Flask: " . $curl_error;
            } else {
                $api_response = json_decode($response, true);
                if ($http_code !== 200 || !is_array($api_response)) {
                    $message = is_array($api_response) && isset($api_response["error"])
                        ? $api_response["error"]
                        : "Flask returned an invalid response (HTTP " . $http_code . ").";
                } else {
                    $result = $api_response;
                }
            }
        }
    }
}

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
                <h2>Fire Activity Calendar</h2>
                <h3>Harmonized MODIS and VIIRS Hot Spot Data</h3>
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
                                <input id="location-search-input" name="location" type="text" placeholder="Coordinates: latitude, longitude" value="<?php echo htmlspecialchars($location ?? "", ENT_QUOTES, "UTF-8"); ?>">
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
                            <input id="year-from" name="year_start" type="number" step="1" min="2003" max="<?php echo htmlspecialchars($current_year, ENT_QUOTES, "UTF-8"); ?>" placeholder="From" value="<?php echo $start_year ?>">
                            <input id="year-to" name="year_end" type="number" step="1" placeholder="To (Optional)" min="2003" max="<?php echo htmlspecialchars($current_year, ENT_QUOTES, "UTF-8"); ?>" value="<?php echo $end_year ?>">
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
                        <?php if ($result !== null): ?>
                            <div>
                                <p>
                                    Results within <?php echo (int) $result["location"]["radius_km"]; ?> km of
                                    <?php echo htmlspecialchars((string) $result["location"]["latitude"], ENT_QUOTES, "UTF-8"); ?>,
                                    <?php echo htmlspecialchars((string) $result["location"]["longitude"], ENT_QUOTES, "UTF-8"); ?>
                                </p>
                                <p>
                                    <?php echo (int) $result["summary"]["cells_matched"]; ?> grid cells matched;
                                    <?php echo number_format((float) $result["summary"]["total_count"], 1); ?> estimated detections.
                                </p>
                            </div>
                        <?php else: ?>
                            <p>Search by current location or latitude/longitude coordinates to see fire activity.</p>
                        <?php endif; ?>
                    </div>
                    <div class="panel data-panel">
                        <?php if ($result !== null): ?>
                            <?php $summary = $result["summary"]; ?>
                            <h4>Total estimated detections: <?php echo number_format((float) $summary["total_count"], 1); ?></h4>
                            <h4>Highest activity month: <?php echo htmlspecialchars($summary["highest_month"]["label"] ?? "No data", ENT_QUOTES, "UTF-8"); ?></h4>
                            <h4>Lowest activity month: <?php echo htmlspecialchars($summary["lowest_month"]["label"] ?? "No data", ENT_QUOTES, "UTF-8"); ?></h4>
                            <h4>Highest activity year: <?php echo htmlspecialchars((string) ($summary["highest_year"] ?? "No data"), ENT_QUOTES, "UTF-8"); ?></h4>
                            <h4>Lowest activity year: <?php echo htmlspecialchars((string) ($summary["lowest_year"] ?? "No data"), ENT_QUOTES, "UTF-8"); ?></h4>
                            <h4>Average per year with data: <?php echo number_format((float) $summary["average_per_year"], 1); ?></h4>
                            <?php if (empty($result["monthly"])): ?>
                                <p>No fire detections matched this location and date range.</p>
                            <?php else: ?>
                                <div class="results-table-wrap">
                                    <table class="results-table">
                                        <thead>
                                            <tr><th>Year</th><th>Month</th><th>Estimated detections</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($result["monthly"] as $row): ?>
                                                <tr>
                                                    <td><?php echo (int) $row["year"]; ?></td>
                                                    <td><?php echo htmlspecialchars($row["month_name"], ENT_QUOTES, "UTF-8"); ?></td>
                                                    <td><?php echo number_format((float) $row["count"], 1); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Submit a location and date range to view fire activity summaries.</p>
                        <?php endif; ?>
                        <button class="csv-download-button" type="button" <?php echo ($result !== null && !empty($result["monthly"])) ? "" : "disabled"; ?>>Download CSV</button>
                    </div>
                </div>
            </section>
        </main>


        <script>

            // Get current location
            const form = document.getElementById("form");
            const submitBtn = document.getElementById("submit");
            const locationLink = document.getElementById("current-location-link");
            const locationInput = document.getElementById("location-search-input");
            const latitudeInput = document.getElementById("latitude");
            const longitudeInput = document.getElementById("longitude");
            const locationStatus = document.getElementById("location-status");
            const csvDownloadButton = document.querySelector(".csv-download-button");

            locationInput.addEventListener("input", function() {
                latitudeInput.value = "";
                longitudeInput.value = "";
            });

            csvDownloadButton.addEventListener("click", function() {
                const table = document.querySelector(".results-table");
                if (!table) {
                    return;
                }
                const rows = Array.from(table.rows).map(function(row) {
                    return Array.from(row.cells).map(function(cell) {
                        return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                    }).join(",");
                });
                const file = new Blob([rows.join("\r\n")], { type: "text/csv;charset=utf-8" });
                const link = document.createElement("a");
                link.href = URL.createObjectURL(file);
                link.download = "fire-activity.csv";
                link.click();
                URL.revokeObjectURL(link.href);
            });

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