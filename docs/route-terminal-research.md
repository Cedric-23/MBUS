# Route terminal reference points

This note records the public sources used for the Morong–SBMA route endpoints. The Harbor Point terminal coordinate is a landmark-level pin, not a surveyed curb coordinate.

## Morong endpoint

Use **Morong Bus Terminal, Morong, Bataan** at `14.6798552, 120.2728757`. OpenStreetMap node `800079742` is mapped as a bus station and Mapcarta places it in Morong, Bataan; the Provincial Government of Bataan's Libreng Sakay page independently names “Morong Bus Terminal” as the pickup point for its Morong service.

Sources: [Mapcarta / OSM Morong Bus Terminal](https://mapcarta.com/N800079742); [Bataan Provincial Government Libreng Sakay](https://libreng-sakay.bataan.gov.ph/).

## SBMA endpoint

Use the public-facing label **SBMA E-bus stop — Harbor Point, in front of National Bookstore**. The best-supported landmark pin is `14.8243652, 120.2801434`, matching the mapped National Book Store location at Harbor Point. Ayala Malls lists the venue address as Subic Bay, Rizal Highway, Subic Bay Freeport Zone, Zambales. An SBMA Labor Department public notice states that the terminals are at Kalaklan Parking Area and Harbor Point Ayala Mall “in front of National Bookstore.”

The point is for the stated storefront/terminal frontage. Public sources reviewed do not give a surveyed curb coordinate, so the UI and code must not claim centimeter-level curb accuracy. Confirm the exact curbside boarding point locally before using it for turn-by-turn pickup guidance.

Sources: [SBMA Labor Department E-bus notice](https://www.facebook.com/100064992811876/posts/lkthe-sbma-e-bus-will-commence-with-its-dry-run-30-to-ply-the-following-routes-c/1248005727375843/); [Ayala Malls Harbor Point official venue page](https://www.ayalamalls.com/main/malls/ayala-harbor-point); [Waze National Book Store - Subic Harbor listing](https://www.waze.com/live-map/directions/ph/central-luzon/subic-bay-freeport-zone/national-book-store-subic-harbor?to=place.ChIJ2_TvhhBxljMRyIEffA74goQ); [Maptons mapped storefront pin](https://mz.maptons.com/p/10676731785).

## Route-estimate interpretation

A driving route request between those two points to the OSRM public demo endpoint returned approximately **31.0 km** and **1,832 seconds (about 31 minutes)**. This is a road-network estimate, not a live-traffic or scheduled bus-arrival prediction; boarding time, stops, traffic, temporary closures, and the bus's actual path can change it.

OSRM documents route geometry, distance in meters, and estimated duration in seconds in its [Route service API](https://project-osrm.org/docs/v5.24.0/api/). The public demo is best-effort, has no quality/availability guarantees, and can withdraw access; requests must be restrained and maps/routes must carry OpenStreetMap/OSRM attribution. See the [OSRM API usage policy](https://github.com/Project-OSRM/osrm-backend/wiki/Api-usage-policy). For sustained production traffic, configure a managed or self-hosted OSRM-compatible router rather than relying on the public demo.
