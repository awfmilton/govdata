import csv
import pandas as pd
import geopandas as gpd


def build_postal_riding_mapping():
    print("Loading postal code points...")
    columns = [
        'country', 'postal_code', 'city', 'prov', 'prov_code',
        'county', 'county_code', 'community', 'community_code',
        'latitude', 'longitude', 'accuracy',
    ]
    df_postal = pd.read_csv(
        'PCBFR2023/CA_full.csv/CA_full.txt',
        sep='\t', names=columns, dtype=str,
    )
    df_postal['latitude'] = df_postal['latitude'].astype(float)
    df_postal['longitude'] = df_postal['longitude'].astype(float)
    # Strip space so format matches existing pcbfr (A1B0R7 not A1B 0R7)
    df_postal['postal_code'] = df_postal['postal_code'].str.replace(' ', '', regex=False)
    # One representative point per postal code (GeoNames has one row per community)
    df_postal = df_postal.drop_duplicates(subset='postal_code', keep='first')
    print(f"  {len(df_postal):,} unique postal codes loaded.")

    gdf_postal = gpd.GeoDataFrame(
        df_postal,
        geometry=gpd.points_from_xy(df_postal.longitude, df_postal.latitude),
        crs='EPSG:4326',
    )

    print("Loading 2023 FED boundary polygons...")
    gdf_boundaries = gpd.read_file(
        'PCBFR2023/FED_CA_2023_EN-SHP/FED_CA_2023_EN.shp'
    ).to_crs('EPSG:4326')
    print(f"  {len(gdf_boundaries):,} riding polygons loaded.")

    print("Performing spatial join (point-in-polygon)...")
    joined = gpd.sjoin(gdf_postal, gdf_boundaries, how='left', predicate='within')

    unmatched = joined['FED_NUM'].isna().sum()
    if unmatched:
        print(f"  Warning: {unmatched:,} postal codes fell outside all riding polygons (dropped).")

    joined = joined.dropna(subset=['FED_NUM'])
    print(f"  {len(joined):,} postal codes matched to a riding.")

    print("Building output dataframe...")
    result = pd.DataFrame({
        'postal_code':        joined['postal_code'].values,
        'riding_number':      joined['FED_NUM'].astype(int).astype(str).str.zfill(5).values,
        'riding_name_en':     joined['ED_NAMEE'].values,
        'riding_name_fr':     joined['ED_NAMEF'].values,
        'unique_FED':         '1',
        'pop_weight':         1.0,
        'weight_information': 'S',
    })

    out_path = 'PCBFR2023/pcbfr_2023.csv'
    result.to_csv(out_path, sep='|', index=False, quoting=csv.QUOTE_ALL)
    print(f"Done. {len(result):,} rows written to {out_path}")
    print(f"  Unique ridings covered: {result['riding_number'].nunique()}")


if __name__ == '__main__':
    build_postal_riding_mapping()
