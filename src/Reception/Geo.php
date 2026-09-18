<?php
// src/Reception/Geo.php
// Geodesy helpers: OSGB36 National Grid -> WGS84, distance, bearing.
// Grid conversion follows the Ordnance Survey "Guide to coordinate systems in
// Great Britain" (Transverse Mercator + 7-parameter Helmert), accurate to ~5 m.

declare(strict_types=1);

namespace Reception;

class Geo
{
    public const EARTH_RADIUS_KM = 6371.0;

    // OSGB36 easting/northing (m) -> [lat, lon] WGS84 degrees.
    public static function gridToWgs84(float $E, float $N): array
    {
        [$lat, $lon] = self::gridToOsgb36($E, $N);
        return self::osgb36ToWgs84($lat, $lon);
    }

    // OSGB36 easting/northing (m) -> [lat, lon] on the Airy 1830 ellipsoid.
    public static function gridToOsgb36(float $E, float $N): array
    {
        $a = 6377563.396; $b = 6356256.909; $F0 = 0.9996012717;
        $lat0 = deg2rad(49.0); $lon0 = deg2rad(-2.0);
        $N0 = -100000.0; $E0 = 400000.0;
        $e2 = 1 - ($b * $b) / ($a * $a);
        $n = ($a - $b) / ($a + $b);

        $lat = $lat0; $M = 0.0;
        do {
            $lat = ($N - $N0 - $M) / ($a * $F0) + $lat;
            $Ma = (1 + $n + (5 / 4) * $n ** 2 + (5 / 4) * $n ** 3) * ($lat - $lat0);
            $Mb = (3 * $n + 3 * $n ** 2 + (21 / 8) * $n ** 3) * sin($lat - $lat0) * cos($lat + $lat0);
            $Mc = ((15 / 8) * $n ** 2 + (15 / 8) * $n ** 3) * sin(2 * ($lat - $lat0)) * cos(2 * ($lat + $lat0));
            $Md = (35 / 24) * $n ** 3 * sin(3 * ($lat - $lat0)) * cos(3 * ($lat + $lat0));
            $M = $b * $F0 * ($Ma - $Mb + $Mc - $Md);
        } while (abs($N - $N0 - $M) >= 0.00001);

        $sin = sin($lat); $cos = cos($lat); $tan = tan($lat);
        $nu = $a * $F0 / sqrt(1 - $e2 * $sin * $sin);
        $rho = $a * $F0 * (1 - $e2) / pow(1 - $e2 * $sin * $sin, 1.5);
        $eta2 = $nu / $rho - 1;

        $VII = $tan / (2 * $rho * $nu);
        $VIII = $tan / (24 * $rho * $nu ** 3) * (5 + 3 * $tan ** 2 + $eta2 - 9 * $tan ** 2 * $eta2);
        $IX = $tan / (720 * $rho * $nu ** 5) * (61 + 90 * $tan ** 2 + 45 * $tan ** 4);
        $X = 1 / ($cos * $nu);
        $XI = 1 / (6 * $cos * $nu ** 3) * ($nu / $rho + 2 * $tan ** 2);
        $XII = 1 / (120 * $cos * $nu ** 5) * (5 + 28 * $tan ** 2 + 24 * $tan ** 4);
        $XIIA = 1 / (5040 * $cos * $nu ** 7) * (61 + 662 * $tan ** 2 + 1320 * $tan ** 4 + 720 * $tan ** 6);

        $dE = $E - $E0;
        $latR = $lat - $VII * $dE ** 2 + $VIII * $dE ** 4 - $IX * $dE ** 6;
        $lonR = $lon0 + $X * $dE - $XI * $dE ** 3 + $XII * $dE ** 5 - $XIIA * $dE ** 7;
        return [rad2deg($latR), rad2deg($lonR)];
    }

    // OSGB36 lat/lon -> WGS84 lat/lon (Helmert transform).
    public static function osgb36ToWgs84(float $latD, float $lonD): array
    {
        $lat = deg2rad($latD); $lon = deg2rad($lonD);
        $a = 6377563.396; $b = 6356256.909;
        $e2 = 1 - ($b * $b) / ($a * $a);
        $nu = $a / sqrt(1 - $e2 * sin($lat) ** 2);
        $x = $nu * cos($lat) * cos($lon);
        $y = $nu * cos($lat) * sin($lon);
        $z = (1 - $e2) * $nu * sin($lat);

        $tx = 446.448; $ty = -125.157; $tz = 542.060;
        $s = -20.4894e-6;
        $rx = deg2rad(0.1502 / 3600); $ry = deg2rad(0.2470 / 3600); $rz = deg2rad(0.8421 / 3600);
        $x2 = $tx + (1 + $s) * $x - $rz * $y + $ry * $z;
        $y2 = $ty + $rz * $x + (1 + $s) * $y - $rx * $z;
        $z2 = $tz - $ry * $x + $rx * $y + (1 + $s) * $z;

        $a = 6378137.0; $b = 6356752.3142;
        $e2 = 1 - ($b * $b) / ($a * $a);
        $p = sqrt($x2 ** 2 + $y2 ** 2);
        $lat = atan2($z2, $p * (1 - $e2));
        for ($i = 0; $i < 10; $i++) {
            $nu = $a / sqrt(1 - $e2 * sin($lat) ** 2);
            $lat = atan2($z2 + $e2 * $nu * sin($lat), $p);
        }
        return [rad2deg($lat), rad2deg(atan2($y2, $x2))];
    }

    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }

    // Initial bearing from point 1 to point 2, degrees true (0-360).
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2); $dl = deg2rad($lon2 - $lon1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    public static function compass(float $deg): string
    {
        $pts = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        return $pts[(int)round($deg / 22.5) % 16];
    }
}
