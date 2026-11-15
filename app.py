import calendar
from functools import lru_cache
from pathlib import Path

import numpy as np
import pandas as pd
from flask import Flask, jsonify, request


DATA = Path(__file__).parent / "lisa data"
CELLS_FILE = DATA / "cells.parquet"
CELL_DEGREES = 0.25
EARTH_RADIUS_KM = 6371.0088
MIN_YEAR = 2003
RADII = (25, 50, 100, 250, 500)

app = Flask(__name__)


class SearchInputError(Exception):
    pass


@lru_cache(maxsize=1)
def load_cells():
    """Load only the columns needed by the search API."""
    return pd.read_parquet(
        CELLS_FILE,
        columns=["cell_lat", "cell_lon", "year", "month", "count", "count_mtd"],
    )


def number_value(data, name):
    try:
        value = float(data[name])
    except (KeyError, TypeError, ValueError):
        raise SearchInputError(f"{name} must be a number.") from None
    if not np.isfinite(value):
        raise SearchInputError(f"{name} must be a finite number.")
    return value


def year_value(data, name, default):
    value = data.get(name, default)
    if value in (None, ""):
        value = default
    try:
        year = int(value)
    except (TypeError, ValueError):
        raise SearchInputError(f"{name} must be a year.") from None
    if str(value).strip() != str(year):
        raise SearchInputError(f"{name} must be a whole year.")
    return year


def search_fire_data(data):
    latitude = number_value(data, "latitude")
    longitude = number_value(data, "longitude")
    if not -90 <= latitude <= 90:
        raise SearchInputError("latitude must be between -90 and 90.")
    if not -180 <= longitude <= 180:
        raise SearchInputError("longitude must be between -180 and 180.")

    current_year = pd.Timestamp.now().year
    start_year = year_value(data, "year_start", None)
    end_year = year_value(data, "year_end", current_year)
    if not MIN_YEAR <= start_year <= current_year:
        raise SearchInputError(
            f"year_start must be between {MIN_YEAR} and {current_year}."
        )
    if not start_year <= end_year <= current_year:
        raise SearchInputError(
            f"year_end must be between year_start and {current_year}."
        )

    month_value = data.get("month")
    if month_value in (None, ""):
        month = None
    else:
        try:
            month = int(month_value)
        except (TypeError, ValueError):
            raise SearchInputError("month must be a number from 1 to 12.") from None
        if str(month_value).strip() != str(month) or not 1 <= month <= 12:
            raise SearchInputError("month must be a whole number from 1 to 12.")

    radius_value = data.get("radius_km", 50)
    try:
        radius_km = int(radius_value)
    except (TypeError, ValueError):
        raise SearchInputError(f"radius_km must be one of {list(RADII)}.") from None
    if str(radius_value).strip() != str(radius_km) or radius_km not in RADII:
        raise SearchInputError(f"radius_km must be one of {list(RADII)}.")

    cells = load_cells()
    # Stored coordinates are the southwest corner of each 0.25-degree cell.
    cell_lat = cells["cell_lat"].to_numpy(dtype=np.float64) + CELL_DEGREES / 2
    cell_lon = cells["cell_lon"].to_numpy(dtype=np.float64) + CELL_DEGREES / 2
    lat_delta = radius_km / 110.574
    lon_scale = max(abs(np.cos(np.radians(latitude))), 1e-6)
    lon_delta = min(180.0, radius_km / (111.320 * lon_scale))
    nearby = (
        (np.abs(cell_lat - latitude) <= lat_delta + CELL_DEGREES)
        & (np.abs(((cell_lon - longitude + 180) % 360) - 180)
           <= lon_delta + CELL_DEGREES)
    )

    lat1 = np.radians(latitude)
    lat2 = np.radians(cell_lat[nearby])
    delta_lat = lat2 - lat1
    delta_lon = np.radians(((cell_lon[nearby] - longitude + 180) % 360) - 180)
    haversine = (
        np.sin(delta_lat / 2) ** 2
        + np.cos(lat1) * np.cos(lat2) * np.sin(delta_lon / 2) ** 2
    )
    distance_km = 2 * EARTH_RADIUS_KM * np.arcsin(
        np.sqrt(np.clip(haversine, 0, 1))
    )

    selected = cells.loc[nearby].copy()
    selected = selected.loc[distance_km <= radius_km]
    selected = selected.loc[
        selected["year"].between(start_year, end_year)
    ]
    if month is not None:
        selected = selected.loc[selected["month"] == month]

    monthly = (
        selected.groupby(["year", "month"], as_index=False)[["count", "count_mtd"]]
        .sum()
        .sort_values(["year", "month"])
    )
    yearly = monthly.groupby("year")["count"].sum()
    total_count = float(monthly["count"].sum())
    highest_month = monthly.loc[monthly["count"].idxmax()] if not monthly.empty else None
    lowest_month = monthly.loc[monthly["count"].idxmin()] if not monthly.empty else None

    def month_summary(row):
        if row is None:
            return None
        return {
            "year": int(row["year"]),
            "month": int(row["month"]),
            "label": f"{calendar.month_name[int(row['month'])]} {int(row['year'])}",
            "count": float(row["count"]),
        }

    highest_year = int(yearly.idxmax()) if not yearly.empty else None
    lowest_year = int(yearly.idxmin()) if not yearly.empty else None
    return {
        "location": {
            "latitude": latitude,
            "longitude": longitude,
            "radius_km": radius_km,
        },
        "period": {
            "year_start": start_year,
            "year_end": end_year,
            "month": month,
        },
        "summary": {
            "total_count": total_count,
            "cells_matched": int(
                selected[["cell_lat", "cell_lon"]].drop_duplicates().shape[0]
            ),
            "highest_month": month_summary(highest_month),
            "lowest_month": month_summary(lowest_month),
            "highest_year": highest_year,
            "lowest_year": lowest_year,
            "average_per_year": (
                float(yearly.mean()) if not yearly.empty else 0.0
            ),
        },
        "monthly": [
            {
                "year": int(row.year),
                "month": int(row.month),
                "month_name": calendar.month_name[int(row.month)],
                "count": float(row.count),
                "count_mtd": float(row.count_mtd),
            }
            for row in monthly.itertuples(index=False)
        ],
    }


@app.route("/api/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "data_file_exists": CELLS_FILE.is_file()})


@app.route("/api/fire-search", methods=["POST"])
def fire_search():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify({"error": "Send search parameters as a JSON object."}), 400
    if not CELLS_FILE.is_file():
        app.logger.error("Fire data file is missing: %s", CELLS_FILE)
        return jsonify({"error": "Fire data is not available on the server."}), 503
    try:
        return jsonify(search_fire_data(data))
    except SearchInputError as exc:
        return jsonify({"error": str(exc)}), 400
    except Exception:
        app.logger.exception("Fire data search failed")
        return jsonify({"error": "The fire data search failed on the server."}), 500


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=True)
