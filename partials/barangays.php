<?php
declare(strict_types=1);

/**
 * Bayawan City barangay reference data — SINGLE SOURCE OF TRUTH.
 *
 * Previously this list was duplicated in map.php and event_form.php with
 * conflicting coordinates. Never re-duplicate it; require this file instead.
 *
 * The 28 barangays are the official PSGC set for the City of Bayawan
 * (PSGC 074606000). Coordinates are the barangay centroids published by
 * PhilAtlas / PSA, rounded to 4 decimal places (~11 m).
 *
 * Note the real extent of the city: it runs from Villareal on the coast
 * (9.3567) up to Mandu-ao in the hinterland (9.6772) — roughly 36 km
 * north to south. Any coordinate table that fits inside a 0.15-degree box
 * is wrong.
 */

const BAYAWAN_CENTER = ['lat' => 9.3654, 'lng' => 122.8051]; // Poblacion

/**
 * name => [lat, lng, zone]
 * zone: 'urban' (poblacion cluster), 'coastal', 'upland'
 */
const BAYAWAN_BARANGAYS = [
    'Ali-is'      => ['lat' => 9.5324, 'lng' => 122.8938, 'zone' => 'upland'],
    'Banaybanay'  => ['lat' => 9.5538, 'lng' => 122.8229, 'zone' => 'upland'],
    'Banga'       => ['lat' => 9.3708, 'lng' => 122.7966, 'zone' => 'coastal'],
    'Boyco'       => ['lat' => 9.3615, 'lng' => 122.8022, 'zone' => 'urban'],
    'Bugay'       => ['lat' => 9.6304, 'lng' => 122.8344, 'zone' => 'upland'],
    'Cansumalig'  => ['lat' => 9.4355, 'lng' => 122.9335, 'zone' => 'upland'],
    'Dawis'       => ['lat' => 9.5757, 'lng' => 122.8814, 'zone' => 'upland'],
    'Kalamtukan'  => ['lat' => 9.5769, 'lng' => 122.7773, 'zone' => 'upland'],
    'Kalumboyan'  => ['lat' => 9.5053, 'lng' => 122.8103, 'zone' => 'upland'],
    'Malabugas'   => ['lat' => 9.3708, 'lng' => 122.7638, 'zone' => 'coastal'],
    'Mandu-ao'    => ['lat' => 9.6772, 'lng' => 122.7989, 'zone' => 'upland'],
    'Maninihon'   => ['lat' => 9.3980, 'lng' => 122.8667, 'zone' => 'coastal'],
    'Minaba'      => ['lat' => 9.4261, 'lng' => 122.7484, 'zone' => 'upland'],
    'Nangka'      => ['lat' => 9.4320, 'lng' => 122.8274, 'zone' => 'upland'],
    'Narra'       => ['lat' => 9.4968, 'lng' => 122.8914, 'zone' => 'upland'],
    'Pagatban'    => ['lat' => 9.3810, 'lng' => 122.7339, 'zone' => 'coastal'],
    'Poblacion'   => ['lat' => 9.3654, 'lng' => 122.8051, 'zone' => 'urban'],
    'San Isidro'  => ['lat' => 9.4254, 'lng' => 122.9077, 'zone' => 'upland'],
    'San Jose'    => ['lat' => 9.6609, 'lng' => 122.8306, 'zone' => 'upland'],
    'San Miguel'  => ['lat' => 9.4078, 'lng' => 122.7346, 'zone' => 'upland'],
    'San Roque'   => ['lat' => 9.4018, 'lng' => 122.7696, 'zone' => 'upland'],
    'Suba'        => ['lat' => 9.3646, 'lng' => 122.8014, 'zone' => 'urban'],
    'Tabuan'      => ['lat' => 9.5194, 'lng' => 122.8452, 'zone' => 'upland'],
    'Tayawan'     => ['lat' => 9.4993, 'lng' => 122.7388, 'zone' => 'upland'],
    'Tinago'      => ['lat' => 9.3619, 'lng' => 122.8065, 'zone' => 'urban'],
    'Ubos'        => ['lat' => 9.3689, 'lng' => 122.8040, 'zone' => 'urban'],
    'Villareal'   => ['lat' => 9.3567, 'lng' => 122.8275, 'zone' => 'coastal'],
    'Villasol'    => ['lat' => 9.6101, 'lng' => 122.7775, 'zone' => 'upland'],
];

/**
 * Named sites that are not barangays but are frequent venues.
 * City Hall resolves to the Poblacion centroid until someone surveys the
 * actual building pin — it is deliberately not given invented precision.
 */
const BAYAWAN_SITES = [
    'Bayawan City Hall' => ['lat' => 9.3654, 'lng' => 122.8051, 'zone' => 'urban'],
];

/**
 * Historic / misspelled names that exist in already-saved event rows.
 * These map old stored strings onto the correct barangay so existing
 * records keep resolving after the data fix.
 *
 * 'Nabalbagan' is deliberately absent: it is not a Bayawan barangay and
 * there is no defensible target, so those rows resolve as unmatched and
 * are flagged in the UI rather than silently moved somewhere plausible.
 */
const BAYAWAN_ALIASES = [
    'banago'      => 'Banaybanay',
    'san miniato' => 'San Miguel',
    'bato'        => 'Villasol',   // Villasol's former name
    'balabag'     => 'Villareal',  // Villareal's former name
];

/** Every selectable location, barangays first then named sites. */
function bayawan_locations(): array
{
    return array_merge(BAYAWAN_BARANGAYS, BAYAWAN_SITES);
}

/** Canonical stored form, e.g. "Brgy. Poblacion, Bayawan City". */
function bayawan_label(string $name): string
{
    return isset(BAYAWAN_SITES[$name]) ? $name : 'Brgy. ' . $name . ', Bayawan City';
}

/** Lowercase, strip "Brgy."/"Barangay" prefixes, punctuation and city suffix. */
function bayawan_normalize(string $raw): string
{
    $s = mb_strtolower(trim($raw));
    $s = preg_replace('/\s*\(poblacion\)\s*/u', ' ', $s) ?? $s;
    // Strip the ", Bayawan City ..." suffix. The comma (or an exact trailing
    // match) is required — a bare /bayawan city.*/ would swallow the whole of
    // "Bayawan City Hall" and leave nothing to match on.
    $s = preg_replace('/\s*,\s*bayawan(\s+city)?\b.*$/u', '', $s) ?? $s;
    $s = preg_replace('/(?<=.)\s+bayawan\s+city$/u', '', $s) ?? $s;
    $s = preg_replace('/^(brgy\.?|barangay|bgy\.?)\s+/u', '', $s) ?? $s;
    $s = str_replace(['–', '—', '‑'], '-', $s);
    $s = preg_replace('/[^a-z0-9\- ]/u', '', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * Resolve a stored location string to coordinates.
 *
 * Returns ['lat','lng','name','matched'] — matched=false means we fell back
 * to the city centre and the caller MUST surface that as approximate rather
 * than presenting it as a real pin.
 */
function bayawan_resolve(string $location): array
{
    $needle = bayawan_normalize($location);

    if ($needle !== '') {
        foreach (bayawan_locations() as $name => $geo) {
            if (bayawan_normalize($name) === $needle) {
                return ['lat' => $geo['lat'], 'lng' => $geo['lng'], 'name' => $name, 'matched' => true];
            }
        }
        if (isset(BAYAWAN_ALIASES[$needle])) {
            $name = BAYAWAN_ALIASES[$needle];
            $geo  = BAYAWAN_BARANGAYS[$name];
            return ['lat' => $geo['lat'], 'lng' => $geo['lng'], 'name' => $name, 'matched' => true];
        }
        // Longest-substring pass, so "poblacion proper" still lands on Poblacion
        // and "san jose" never gets swallowed by a shorter key.
        $best = null;
        $bestLen = 0;
        foreach (bayawan_locations() as $name => $geo) {
            $key = bayawan_normalize($name);
            if ($key !== '' && str_contains($needle, $key) && mb_strlen($key) > $bestLen) {
                $best = ['lat' => $geo['lat'], 'lng' => $geo['lng'], 'name' => $name, 'matched' => true];
                $bestLen = mb_strlen($key);
            }
        }
        if ($best) {
            return $best;
        }
    }

    return [
        'lat'     => BAYAWAN_CENTER['lat'],
        'lng'     => BAYAWAN_CENTER['lng'],
        'name'    => '',
        'matched' => false,
    ];
}

/** Split "Brgy. X, Bayawan City — Venue" into [barangay, venue]. */
function bayawan_split_location(string $location): array
{
    $location = str_replace([' – ', ' -- '], ' — ', $location);
    if (str_contains($location, ' — ')) {
        [$brgy, $venue] = explode(' — ', $location, 2);
        return [trim($brgy), trim($venue)];
    }
    return [trim($location), ''];
}
