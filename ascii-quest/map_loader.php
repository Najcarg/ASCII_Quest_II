<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASCII Quest - Map Loader
|--------------------------------------------------------------------------
| Purpose:
|   Loads map JSON files from /maps.
|
| Current map folder:
|   /var/www/html/ascii-quest/maps/
|
| Later public-release folder:
|   /etc/ascii-quest/maps/
*/

function loadMapFromFile(string $mapFile): array
{
    /*
    |--------------------------------------------------------------------------
    | Safety: only allow file name, not folders
    |--------------------------------------------------------------------------
    */
    $safeFileName = basename($mapFile);

    /*
    |--------------------------------------------------------------------------
    | Map folder
    |--------------------------------------------------------------------------
    | Later we can change this one line to /etc/ascii-quest/maps.
    */
    $mapDirectory = __DIR__ . "/maps";
    $mapPath = $mapDirectory . "/" . $safeFileName;

    if (!file_exists($mapPath)) {
        throw new RuntimeException("Map file not found: " . $safeFileName);
    }

    $json = file_get_contents($mapPath);

    if ($json === false) {
        throw new RuntimeException("Could not read map file: " . $safeFileName);
    }

    $mapData = json_decode($json, true);

    if (!is_array($mapData)) {
        throw new RuntimeException("Invalid map JSON: " . $safeFileName);
    }

    /*
    |--------------------------------------------------------------------------
    | Required map fields
    |--------------------------------------------------------------------------
    */
    $requiredFields = [
        "map_key",
        "map_name",
        "width",
        "height",
        "start_x",
        "start_y",
        "layout",
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $mapData)) {
            throw new RuntimeException("Map file missing field: " . $field);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validate layout
    |--------------------------------------------------------------------------
    */
    if (!is_array($mapData["layout"])) {
        throw new RuntimeException("Map layout must be an array of strings.");
    }

    if (count($mapData["layout"]) !== (int) $mapData["height"]) {
        throw new RuntimeException(
            "Map height does not match layout row count.",
        );
    }

    foreach ($mapData["layout"] as $rowIndex => $row) {
        if (!is_string($row)) {
            throw new RuntimeException(
                "Map row " . $rowIndex . " is not text.",
            );
        }

        if (strlen($row) !== (int) $mapData["width"]) {
            throw new RuntimeException(
                "Map row " . $rowIndex . " width is wrong.",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Optional transitions
    |--------------------------------------------------------------------------
    | If map has no transitions, create empty array.
    |
    | Example transition:
    |   x/y/glyph says where player stands
    |   target_map_key says where it takes player
    */
    if (!array_key_exists("transitions", $mapData)) {
        $mapData["transitions"] = [];
    }

    if (!is_array($mapData["transitions"])) {
        throw new RuntimeException("Map transitions must be an array.");
    }

    foreach ($mapData["transitions"] as $index => $transition) {
        $requiredTransitionFields = [
            "type",
            "x",
            "y",
            "glyph",
            "target_map_key",
            "target_x",
            "target_y",
            "message",
        ];

        foreach ($requiredTransitionFields as $field) {
            if (!array_key_exists($field, $transition)) {
                throw new RuntimeException(
                    "Transition " . $index . " missing field: " . $field,
                );
            }
        }
    }

    return $mapData;
}
