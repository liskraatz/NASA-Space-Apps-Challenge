
import argparse
import json
import zipfile
from pathlib import Path

import numpy as np
import pandas as pd
import pyarrow as pa
import pyarrow.compute as pc
import pyarrow.parquet as pq


CELL = 0.25              # grid cell size in degrees (~25 km)
BLOCK = 8                # a "block" is 8 x 8 cells (2 degrees), used as a fallback
MIN_CELL = 30            # hotspots a cell needs before it gets its own ratio
MIN_BLOCK = 100          # hotspots a block needs before it gets its own ratio
MODIS_MIN_CONF = 30      # MODIS confidence is 0-100; under 30 counts as "low"
START_YM = 2003 * 12     # January 2003: first full year with both Terra and Aqua
VIIRS_YM = 2012 * 12 + 1   # February 2012: first full month of VIIRS S-NPP
NOAA20_YM = 2018 * 12 + 3  # April 2018: first month of VIIRS NOAA-20
GAP_SHARE = 0.85         # S-NPP below 85% of its usual share of NOAA-20 = outage month
CHUNK = 1_000_000        # rows read at a time, so this fits in laptop memory

SENSOR_CODES = {"M-C61": "MODIS", "SV-C2": "SNPP", "J1V-C2": "NOAA20"}
SOURCES = ["MODIS rescaled", "VIIRS S-NPP", "VIIRS NOAA-20 calibrated"]
USECOLS = {"latitude", "longitude", "acq_date", "acq_time", "confidence",
           "frp", "daynight", "type"}


def ym_text(ym):
    return f"{ym // 12}-{ym % 12 + 1:02d}"



def find_inputs(folder):
    """Every MODIS / S-NPP / NOAA-20 CSV in the folder, inside zips or loose."""
    found = []
    for path in sorted(Path(folder).rglob("*")):
        if path.suffix.lower() == ".zip":
            with zipfile.ZipFile(path) as z:
                found += [(path, n) for n in z.namelist() if n.lower().endswith(".csv")]
        elif path.suffix.lower() == ".csv":
            found.append((path, None))
    inputs = []
    for path, member in found:
        name = member or path.name
        sensor = next((s for code, s in SENSOR_CODES.items() if code in name), None)
        if sensor:                     # Landsat and NOAA-21 files are skipped
            kind = "nrt" if "nrt" in name.lower() else "archive"
            inputs.append({"sensor": sensor, "kind": kind, "path": path,
                           "member": member, "name": name})
    return inputs


def open_csv(item):
    if item["member"]:
        return zipfile.ZipFile(item["path"]).open(item["member"])
    return open(item["path"], "rb")


def last_dates(inputs):
    """Last acquisition date per sensor."""
    last = {}
    for kind in ("nrt", "archive"):
        for item in inputs:
            if item["kind"] != kind or (kind == "archive" and item["sensor"] in last_nrt):
                continue
            with open_csv(item) as f:
                d = pd.read_csv(f, usecols=["acq_date"])["acq_date"].max()
            last[item["sensor"]] = max(last.get(item["sensor"], d), d)
        if kind == "nrt":
            last_nrt = set(last)
    return {s: pd.Timestamp(d) for s, d in last.items()}



def clean_and_count(inputs, cutoff, snpp_end_ym, hotspot_path):
    """Stream every file once. Returns monthly counts per cell per sensor and
    writes the cleaned detections to a temporary parquet file."""
    counts, static_parts, stats = [], [], {}
    static = np.array([], dtype=np.int64)
    writer = None

    for kind in ("archive", "nrt"):          # archives first: they teach us where
        if kind == "nrt" and static_parts:   # the static heat sources are
            static = np.unique(np.concatenate(static_parts))
        for item in [i for i in inputs if i["kind"] == kind]:
            sensor = item["sensor"]
            st = stats.setdefault(sensor, {"read": 0, "not_fire": 0,
                                           "low_confidence": 0, "kept": 0})
            print(f"  reading {item['name']}")
            with open_csv(item) as f:
                for df in pd.read_csv(f, usecols=lambda c: c.lower() in USECOLS,
                                      dtype={"confidence": str}, chunksize=CHUNK):
                    df.columns = df.columns.str.lower()
                    st["read"] += len(df)
                    lat = df["latitude"].to_numpy()
                    lon = df["longitude"].to_numpy()

                    # a ~1 km grid key, used to remember static heat sources
                    fine = (np.floor(lat * 100).astype(np.int64) * 100_000
                            + np.floor(lon * 100).astype(np.int64) + 18_000)
                    if "type" in df:         # 0 fire, 1 volcano, 2 static, 3 offshore
                        kind_of = df["type"].fillna(0).to_numpy()
                        static_parts.append(np.unique(fine[kind_of != 0]))
                        is_fire = kind_of == 0
                    else:                    # near-real-time files have no type
                        is_fire = ~np.isin(fine, static)
                    st["not_fire"] += int((~is_fire).sum())

                    if sensor == "MODIS":
                        conf = pd.to_numeric(df["confidence"], errors="coerce")
                        confident = (conf >= MODIS_MIN_CONF).to_numpy()
                    else:                    # VIIRS uses l / n / h
                        confident = ~df["confidence"].str.lower().str.startswith(
                            "l").fillna(False).to_numpy(dtype=bool)
                    st["low_confidence"] += int((is_fire & ~confident).sum())

                    date = pd.to_datetime(df["acq_date"], format="%Y-%m-%d")
                    keep = is_fire & confident & (date <= cutoff).to_numpy()
                    df, date = df[keep], date[keep]
                    st["kept"] += len(df)
                    if df.empty:
                        continue

                    ym = (date.dt.year * 12 + date.dt.month - 1).to_numpy()
                    to_date = ((date.dt.month == cutoff.month)
                               & (date.dt.day <= cutoff.day)).to_numpy()
                    part = pd.DataFrame({
                        "ci": np.floor(df["latitude"].to_numpy() / CELL).astype(np.int32),
                        "cj": np.floor(df["longitude"].to_numpy() / CELL).astype(np.int32),
                        "ym": ym.astype(np.int32),
                        "mtd": to_date.astype(np.int32),
                    })
                    part = (part.groupby(["ci", "cj", "ym"])["mtd"]
                            .agg(n="size", mtd="sum").reset_index())
                    part["sensor"] = sensor
                    counts.append(part)

                    # detections that can end up in the harmonized record
                    if sensor == "MODIS":
                        used = (ym >= START_YM) & (ym < VIIRS_YM)
                    elif sensor == "SNPP":
                        used = (ym >= VIIRS_YM) & (ym <= snpp_end_ym)
                    else:
                        used = ym >= NOAA20_YM
                    if used.any():
                        h = df[used]
                        table = pa.table({
                            "latitude": pa.array(h["latitude"].to_numpy(), pa.float32()),
                            "longitude": pa.array(h["longitude"].to_numpy(), pa.float32()),
                            "acq_date": pa.array(date[used].dt.date.to_numpy(), pa.date32()),
                            "acq_time": pa.array(h["acq_time"].to_numpy(), pa.int16()),
                            "sensor": pa.array([sensor] * len(h)).dictionary_encode(),
                            "confidence": pa.array(h["confidence"].astype(str).to_numpy(),
                                                   pa.string()).dictionary_encode(),
                            "frp": pa.array(h["frp"].to_numpy(dtype="float64"), pa.float32()),
                            "daynight": pa.array(h["daynight"].astype(str).to_numpy(),
                                                 pa.string()).dictionary_encode(),
                            "ym": pa.array(ym[used], pa.int32()),
                        })
                        if writer is None:
                            writer = pq.ParquetWriter(hotspot_path, table.schema)
                        writer.write_table(table.cast(writer.schema))
    if writer:
        writer.close()

    counts = (pd.concat(counts).groupby(["sensor", "ci", "cj", "ym"])[["n", "mtd"]]
              .sum().reset_index())
    wide = counts.pivot_table(index=["ci", "cj", "ym"], columns="sensor",
                              values=["n", "mtd"], fill_value=0)
    out = pd.DataFrame(index=wide.index)
    for sensor in ("MODIS", "SNPP", "NOAA20"):
        out[sensor.lower()] = wide[("n", sensor)].astype(np.int64)
        out[sensor.lower() + "_mtd"] = wide[("mtd", sensor)].astype(np.int64)
    return out.reset_index(), stats, len(static)


# harmonization
def fit_ratio(w, src, dst, ym0, ym1, skip=(), local=True):
    """dst/src ratio per cell, per 2-degree block, and for the whole region,
    learned from months ym0..ym1 when both sensors were flying.

    skip   months to leave out (sensor outages)
    local  False = one ratio for the whole region (used for NOAA-20 -> S-NPP,
           which are identical instruments)
    """
    o = w[(w["ym"] >= ym0) & (w["ym"] <= ym1) & ~w["ym"].isin(list(skip))]
    cell = o.groupby(["ci", "cj"])[[src, dst]].sum()
    if cell[src].sum() == 0:
        raise SystemExit(f"No {src} hotspots in the overlap period - check your files.")
    block = cell.groupby([cell.index.get_level_values("ci") // BLOCK,
                          cell.index.get_level_values("cj") // BLOCK])[[src, dst]].sum()
    need_cell, need_block = (MIN_CELL, MIN_BLOCK) if local else (np.inf, np.inf)
    return {
        "cell": (cell[dst] / cell[src])[cell[src] >= need_cell],
        "block": (block[dst] / block[src])[block[src] >= need_block],
        "regional": float(cell[dst].sum() / cell[src].sum()),
    }


def ratio_for(w, r):
    """The ratio to use for each row: its cell's own if the cell had enough
    hotspots to trust, else its block's, else the regional one."""
    cell = r["cell"].reindex(pd.MultiIndex.from_arrays([w["ci"], w["cj"]])).to_numpy()
    block = r["block"].reindex(
        pd.MultiIndex.from_arrays([w["ci"] // BLOCK, w["cj"] // BLOCK])).to_numpy()
    return np.where(np.isnan(cell), np.where(np.isnan(block), r["regional"], block), cell)


def find_outages(w, snpp_end_ym):
    """Months where S-NPP saw far fewer hotspots than its twin NOAA-20 says it
    should have: the satellite was down for part of the month."""
    both = w[(w["ym"] >= NOAA20_YM) & (w["ym"] <= snpp_end_ym)]
    monthly = both.groupby("ym")[["snpp", "noaa20"]].sum()
    monthly = monthly[monthly["noaa20"] >= 500]
    share = monthly["snpp"] / monthly["noaa20"]
    return sorted(int(m) for m in share.index[share < GAP_SHARE * share.median()])


def harmonize(w, snpp_end_ym):
    """Add the harmonized count (in VIIRS S-NPP units) to every cell-month."""
    gaps = find_outages(w, snpp_end_ym)
    r_modis = fit_ratio(w, "modis", "snpp", VIIRS_YM, snpp_end_ym, skip=gaps)
    r_noaa = fit_ratio(w, "noaa20", "snpp", NOAA20_YM, snpp_end_ym, skip=gaps, local=False)
    k_modis, k_noaa = ratio_for(w, r_modis), ratio_for(w, r_noaa)

    ym = w["ym"].to_numpy()
    use_noaa = (ym > snpp_end_ym) | np.isin(ym, gaps)
    use_modis = ym < VIIRS_YM
    w["modis_scaled"] = w["modis"] * k_modis
    w["count"] = np.select([use_modis, use_noaa],
                           [w["modis_scaled"], w["noaa20"] * k_noaa], w["snpp"])
    w["count_mtd"] = np.select([use_modis, use_noaa],
                               [w["modis_mtd"] * k_modis, w["noaa20_mtd"] * k_noaa],
                               w["snpp_mtd"])
    w["source"] = np.select([use_modis, use_noaa], [0, 2], 1).astype(np.int8)
    info = {
        "modis_to_snpp": r_modis["regional"], "noaa20_to_snpp": r_noaa["regional"],
        "cells_with_own_ratio": int(len(r_modis["cell"])),
        "blocks_with_own_ratio": int(len(r_modis["block"])),
        "cell_ratio_p10": float(r_modis["cell"].quantile(0.10)),
        "cell_ratio_p90": float(r_modis["cell"].quantile(0.90)),
        "snpp_outage_months": [ym_text(g) for g in gaps],
    }
    return w, info, gaps


#validation 
def validate(w, src, dst, ym0, ym1, skip=(), local=True):
    """Learn ratios on the first half of the overlap, then see how well the
    rescaled src record predicts the real dst record in the second half."""
    mid = (ym0 + ym1) // 2
    r = fit_ratio(w, src, dst, ym0, mid, skip=skip, local=local)
    t = w[(w["ym"] > mid) & (w["ym"] <= ym1) & ~w["ym"].isin(list(skip))].copy()
    t["pred"] = t[src] * ratio_for(t, r)
    t["one_ratio"] = t[src] * r["regional"]

    def pct_err(frame, col):
        return float(((frame[col] - frame[dst]).abs() / frame[dst]).median() * 100)

    monthly = t.groupby("ym")[[src, "pred", "one_ratio", dst]].sum()
    busy = monthly[monthly[dst] >= monthly[dst].median()]
    t["block"] = (t["ci"] // BLOCK).astype(str) + "_" + (t["cj"] // BLOCK).astype(str)
    local = t.groupby(["block", t["ym"] // 12])[[src, "pred", "one_ratio", dst]].sum()
    local = local[local[dst] >= 200]
    return {
        "trained_on": f"{ym_text(ym0)} to {ym_text(mid)}",
        "tested_on": f"{ym_text(mid + 1)} to {ym_text(ym1)}",
        "months_tested": int(len(monthly)),
        "correlation": round(float(monthly["pred"].corr(monthly[dst])), 3),
        "total_predicted_vs_actual": round(float(monthly["pred"].sum() / monthly[dst].sum()), 3),
        "monthly_error_pct": {
            "unharmonized": round(pct_err(busy, src), 1),
            "harmonized": round(pct_err(busy, "pred"), 1),
        },
        "local_yearly_error_pct": {      # 2-degree blocks, one year at a time
            "unharmonized": round(pct_err(local, src), 1),
            "one_ratio_everywhere": round(pct_err(local, "one_ratio"), 1),
            "ratio_per_grid_cell": round(pct_err(local, "pred"), 1),
            "block_years_tested": int(len(local)),
        },
    }


#saving the cells
def save_cells(w, path):
    keep = w[(w["ym"] >= START_YM)]
    keep = keep[(keep[["modis", "snpp", "noaa20"]].sum(axis=1) > 0)]
    cells = pd.DataFrame({
        "cell_lat": (keep["ci"] * CELL).astype(np.float32),
        "cell_lon": (keep["cj"] * CELL).astype(np.float32),
        "year": (keep["ym"] // 12).astype(np.int16),
        "month": (keep["ym"] % 12 + 1).astype(np.int8),
        "count": keep["count"].astype(np.float32),
        "count_mtd": keep["count_mtd"].astype(np.float32),
        "modis": keep["modis"].astype(np.int32),
        "modis_scaled": keep["modis_scaled"].astype(np.float32),
        "snpp": keep["snpp"].astype(np.int32),
        "noaa20": keep["noaa20"].astype(np.int32),
        "source": keep["source"],
    }).sort_values(["cell_lat", "cell_lon", "year", "month"])
    cells.to_parquet(path, index=False)
    return cells


def save_hotspots(tmp_path, path, gaps, snpp_end_ym):
    """Keep only the detections the harmonized record is built from, sorted by
    latitude so the app can pull one area out quickly."""
    t = pq.read_table(tmp_path)
    ym, sensor = t["ym"], t["sensor"].cast(pa.string())
    gap = pc.is_in(ym, value_set=pa.array(gaps, pa.int32()))
    noaa_months = pc.or_(pc.greater(ym, snpp_end_ym), gap)
    is_noaa = pc.equal(sensor, "NOAA20")
    keep = pc.or_(pc.and_(is_noaa, noaa_months),
                  pc.and_(pc.invert(is_noaa),
                          pc.invert(pc.and_(pc.equal(sensor, "SNPP"), gap))))
    t = t.filter(keep).drop_columns(["ym"]).sort_by("latitude")
    pq.write_table(t, path, row_group_size=100_000, compression="zstd")
    return t.num_rows


def main():
    p = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    p.add_argument("folder", help="folder holding the FIRMS .zip (or .csv) files")
    p.add_argument("--out", default="data", help="output folder (default: data)")
    args = p.parse_args()

    inputs = find_inputs(args.folder)
    have = {i["sensor"] for i in inputs}
    missing = set(SENSOR_CODES.values()) - have
    if missing:
        raise SystemExit(f"Missing files for: {sorted(missing)}. Need MODIS (M-C61), "
                         "VIIRS S-NPP (SV-C2) and VIIRS NOAA-20 (J1V-C2).")
    out = Path(args.out)
    out.mkdir(exist_ok=True)

    last = last_dates(inputs)
    # the final day of a download is usually only partly there, so stop a day early
    cutoff = max(last["MODIS"], last["NOAA20"]) - pd.Timedelta(days=1)
    snpp_end_ym = ((last["SNPP"] + pd.Timedelta(days=1)).to_period("M") - 1)
    snpp_end_ym = snpp_end_ym.year * 12 + snpp_end_ym.month - 1
    print(f"Data through {cutoff.date()}; S-NPP's last full month is {ym_text(snpp_end_ym)}")

    tmp = out / "_hotspots_tmp.parquet"
    w, stats, n_static = clean_and_count(inputs, cutoff, snpp_end_ym, tmp)
    w, info, gaps = harmonize(w, snpp_end_ym)
    checks = {
        "modis_to_snpp": validate(w, "modis", "snpp", VIIRS_YM, snpp_end_ym, skip=gaps),
        "noaa20_to_snpp": validate(w, "noaa20", "snpp", NOAA20_YM, snpp_end_ym,
                                   skip=gaps, local=False),
    }
    cells = save_cells(w, out / "cells.parquet")
    n_hot = save_hotspots(tmp, out / "hotspots.parquet", gaps, snpp_end_ym)
    tmp.unlink()

    report = {
        "data_through": str(cutoff.date()),
        "cell_degrees": CELL,
        "first_year": START_YM // 12,
        "viirs_start": ym_text(VIIRS_YM),
        "snpp_last_full_month": ym_text(snpp_end_ym),
        "detections": stats,
        "static_source_locations": n_static,
        "grid_cells_with_fire": int(cells.groupby(["cell_lat", "cell_lon"]).ngroups),
        "hotspots_in_download_file": n_hot,
        "harmonization": info,
        "validation": checks,
    }
    (out / "report.json").write_text(json.dumps(report, indent=2))
    print(json.dumps(report, indent=2))
    print(f"\nDone. Files are in {out}/")


if __name__ == "__main__":
    main()
