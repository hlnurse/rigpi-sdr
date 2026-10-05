<?php
/** Authenticated frequency/activity/license intelligence for Ask Elmer. */

session_start();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

$root = "/var/www/html";

if (empty($_SESSION["myUsername"])) {
    http_response_code(401);
    echo json_encode(["error" => "Please sign in to RigPi."]);
    exit();
}

require_once $root . "/programs/sqldata.php";
require_once $root . "/programs/GetUserFieldFunc.php";
require_once $root . "/classes/MysqliDb.php";

ini_set("display_errors", "0");
ini_set("log_errors", "1");

try {
    $request = json_decode(
        file_get_contents("php://input"),
        true,
        8,
        JSON_THROW_ON_ERROR
    );

    $username = (string) $_SESSION["myUsername"];
    $user = (int) getUserField($username, "uID");

    if ($user < 1) {
        throw new RuntimeException(
            "The signed-in RigPi account was not found."
        );
    }

    $db = new MysqliDb(
        "localhost",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database
    );

    /*
     * Get only the user information needed for this lookup.
     */
    $db->where("uID", $user);
    $u = $db->getOne("Users", "uID,SelectedRadio,MyCountry,My_LicenseClass");

    if (!$u) {
        throw new RuntimeException("RigPi user record was not found.");
    }

    $country = strtoupper(trim((string) ($u["MyCountry"] ?? "")));
    $signedInLicenseClass = trim((string) ($u["My_LicenseClass"] ?? ""));
    $requestedLicenseClass = trim((string) ($request["license_class"] ?? ""));

    if (strlen($requestedLicenseClass) > 40) {
        throw new InvalidArgumentException("The requested license class is too long.");
    }

    $licenseClass = $requestedLicenseClass !== ""
        ? $requestedLicenseClass
        : $signedInLicenseClass;

    $licenseClassSource = $requestedLicenseClass !== ""
        ? "question"
        : "signed_in_user";

    $selectedRadio = (int) ($u["SelectedRadio"] ?? 1);
    $queryMode = trim((string) ($request["query_mode"] ?? "frequency"));
    $activityQuery = trim((string) ($request["activity_query"] ?? ""));
    $bandQuery = trim((string) ($request["band_query"] ?? ""));

    if (
        !in_array(
            $queryMode,
            ["frequency", "activity_directory", "activity_on_band"],
            true
        )
    ) {
        throw new InvalidArgumentException("The frequency query mode is invalid.");
    }

    if ($queryMode !== "frequency") {
        if (
            $activityQuery === "" ||
            strlen($activityQuery) > 40 ||
            !preg_match('/^[A-Za-z0-9 +.\/-]+$/', $activityQuery)
        ) {
            throw new InvalidArgumentException("A valid activity name is required.");
        }
    }

    $validBands = [
        "160m", "80m", "60m", "40m", "30m", "20m",
        "17m", "15m", "12m", "10m", "6m", "2m",
        "1.25m", "70cm", "33cm", "23cm"
    ];

    if (
        $queryMode === "activity_on_band" &&
        !in_array($bandQuery, $validBands, true)
    ) {
        throw new InvalidArgumentException("A valid amateur band is required.");
    }

    /*
     * Frequency may be supplied explicitly by Elmer.
     * Otherwise use the user's currently selected RigPi radio.
     */
    $freqHz = 0;
    $frequencySource = "question";

    if ($queryMode === "frequency") {
        $freqHz = (int) ($request["frequency_hz"] ?? 0);

        if ($freqHz <= 0) {
            $db->where("Radio", $selectedRadio);
            $radio = $db->getOne("RadioInterface", "Radio,MainIn");

            $freqHz = (int) ($radio["MainIn"] ?? 0);
            $frequencySource = "selected_radio";
        }

        if ($freqHz <= 0 || $freqHz > 100000000000) {
            throw new InvalidArgumentException(
                "A valid amateur-radio frequency is required."
            );
        }
    }

    /*
     * Resolve country -> IARU Region.
     */
    $db->where("Country", $country);
    $cr = $db->getOne("CountryRegions", "Country,IARURegion,CountryName");

    $iaruRegion = $cr ? (int) $cr["IARURegion"] : null;

    if (in_array($queryMode, ["activity_directory", "activity_on_band"], true)) {
        $activityRows = [];

        if ($iaruRegion !== null) {
            $activityRows = $db->rawQuery(
                "SELECT
                    Band,StartHz,EndHz,DialHz,Activity,Mode,
                    Category,Priority,Notes,Country,IARURegion
                 FROM BandActivity
                 WHERE Enabled=1
                   AND Activity=?
                   AND (
                        Country=?
                        OR (Country IS NULL AND IARURegion=?)
                        OR (Country IS NULL AND IARURegion IS NULL)
                   )
                 ORDER BY
                   CASE
                     WHEN Country=? THEN 3
                     WHEN Country IS NULL AND IARURegion=? THEN 2
                     ELSE 1
                   END DESC,
                   StartHz ASC,
                   Priority DESC,
                   (EndHz-StartHz) ASC",
                [
                    $activityQuery,
                    $country,
                    $iaruRegion,
                    $country,
                    $iaruRegion
                ]
            );
        } else {
            $activityRows = $db->rawQuery(
                "SELECT
                    Band,StartHz,EndHz,DialHz,Activity,Mode,
                    Category,Priority,Notes,Country,IARURegion
                 FROM BandActivity
                 WHERE Enabled=1
                   AND Activity=?
                   AND (Country=? OR (Country IS NULL AND IARURegion IS NULL))
                 ORDER BY
                   CASE WHEN Country=? THEN 2 ELSE 1 END DESC,
                   StartHz ASC,
                   Priority DESC,
                   (EndHz-StartHz) ASC",
                [$activityQuery, $country, $country]
            );
        }

        $scopeByBand = [];
        $activities = [];

        foreach ($activityRows as $row) {
            $band = (string) $row["Band"];

            if ($queryMode === "activity_on_band" && $band !== $bandQuery) {
                continue;
            }

            $scope =
                (string) ($row["Country"] ?? "") === $country ? 3 :
                ($row["Country"] === null &&
                 $row["IARURegion"] !== null &&
                 (int) $row["IARURegion"] === $iaruRegion ? 2 : 1);

            if (!isset($scopeByBand[$band])) {
                $scopeByBand[$band] = $scope;
            }

            if ($scope !== $scopeByBand[$band]) {
                continue;
            }

            $activities[] = [
                "band" => $band,
                "start_hz" => (int) $row["StartHz"],
                "end_hz" => (int) $row["EndHz"],
                "dial_hz" =>
                    $row["DialHz"] === null ? null : (int) $row["DialHz"],
                "activity" => (string) $row["Activity"],
                "mode" => trim((string) ($row["Mode"] ?? "")),
                "category" => (string) $row["Category"],
                "priority" => (int) $row["Priority"],
                "notes" => trim((string) ($row["Notes"] ?? "")),
            ];
        }

        usort(
            $activities,
            static fn(array $a, array $b): int =>
                [$a["start_hz"], -$a["priority"]] <=>
                [$b["start_hz"], -$b["priority"]]
        );
        $activities = array_slice($activities, 0, 20);
        $primary = $activities[0] ?? null;
        $checkHz = $primary
            ? (int) ($primary["dial_hz"] ?? $primary["start_hz"])
            : 0;
        $requiredPrivilege = null;
        $privileges = [];
        $frequencyPermitted = null;
        $activityPermitted = null;

        if ($queryMode === "activity_on_band" && $primary) {
            $category = strtolower($primary["category"]);
            $activity = strtolower($primary["activity"]);
            $mode = strtoupper($primary["mode"]);

            if (in_array($category, ["digital", "data", "packet"], true)) {
                $requiredPrivilege = "Data";
            } elseif ($category === "image") {
                $requiredPrivilege = "Image";
            } elseif ($category === "cw" || $category === "beacon") {
                $requiredPrivilege = "CW";
            } elseif ($category === "phone") {
                $requiredPrivilege = "Phone";
            } elseif ($category === "calling") {
                if ($mode === "CW") {
                    $requiredPrivilege = "CW";
                } elseif (in_array($mode, ["USB", "LSB", "AM", "FM"], true)) {
                    $requiredPrivilege = "Phone";
                }
            }

            if (
                $requiredPrivilege === null &&
                in_array(
                    $activity,
                    ["ft8", "ft4", "rtty", "psk31", "js8", "js8call"],
                    true
                )
            ) {
                $requiredPrivilege = "Data";
            }

            if ($requiredPrivilege === null && $activity === "sstv") {
                $requiredPrivilege = "Image";
            }

            if ($country !== "" && $licenseClass !== "" && $checkHz > 0) {
                $privilegeRows = $db->rawQuery(
                    "SELECT
                        Band,StartHz,EndHz,Privilege,
                        MaxPowerW,PowerBasis,Notes
                     FROM BandPrivileges
                     WHERE Enabled=1
                       AND Country=?
                       AND LicenseClass=?
                       AND Band=?
                       AND ? BETWEEN StartHz AND EndHz
                     ORDER BY
                       (EndHz-StartHz) ASC,
                       Id ASC",
                    [$country, $licenseClass, $bandQuery, $checkHz]
                );

                foreach ($privilegeRows as $row) {
                    $privilegeText = trim((string) $row["Privilege"]);
                    $privileges[] = [
                        "band" => (string) $row["Band"],
                        "start_hz" => (int) $row["StartHz"],
                        "end_hz" => (int) $row["EndHz"],
                        "privilege" => $privilegeText,
                        "privileges" => array_values(
                            array_filter(
                                array_map("trim", explode(",", $privilegeText))
                            )
                        ),
                        "max_power_w" =>
                            $row["MaxPowerW"] === null
                                ? null
                                : (float) $row["MaxPowerW"],
                        "power_basis" =>
                            trim((string) ($row["PowerBasis"] ?? "")),
                        "notes" => trim((string) ($row["Notes"] ?? "")),
                    ];
                }

                $coverageRows = $db->rawQuery(
                    "SELECT Id FROM BandPrivileges
                     WHERE Enabled=1 AND Country=? AND LicenseClass=? AND Band=?
                     LIMIT 1",
                    [$country, $licenseClass, $bandQuery]
                );

                if (count($coverageRows) > 0) {
                    $frequencyPermitted = count($privileges) > 0;
                    $activityPermitted = false;

                    if ($frequencyPermitted && $requiredPrivilege !== null) {
                        foreach ($privileges as $privilege) {
                            $allowed = array_map("strtolower", $privilege["privileges"]);

                            if (
                                in_array("all", $allowed, true) ||
                                in_array(strtolower($requiredPrivilege), $allowed, true)
                            ) {
                                $activityPermitted = true;
                                break;
                            }
                        }
                    }
                }
            }
        } elseif ($queryMode === "activity_on_band") {
            $frequencyPermitted = false;
            $activityPermitted = false;
        }

        echo json_encode(
            [
                "query_mode" => $queryMode,
                "activity_query" => $activityQuery,
                "frequency_hz" =>
                    $queryMode === "activity_on_band" ? $checkHz : null,
                "frequency_source" =>
                    $queryMode === "activity_on_band" ? "activity_directory" : "",
                "selected_radio" => $selectedRadio,
                "band" =>
                    $queryMode === "activity_on_band" ? $bandQuery : null,
                "country" => $country,
                "license_class" => $licenseClass,
                "license_class_source" => $licenseClassSource,
                "signed_in_license_class" => $signedInLicenseClass,
                "iaru_region" => $iaruRegion,
                "primary_activity" => $primary,
                "activity_matches" => $activities,
                "frequency_permitted" => $frequencyPermitted,
                "required_privilege" => $requiredPrivilege,
                "activity_permitted" => $activityPermitted,
                "privilege_matches" => $privileges,
                "guidance" =>
                    "BandActivity entries are customary operating guidance, not exclusive regulatory allocations or authorization to transmit.",
                "source" => "RigPi frequency intelligence",
                "privacy_notice" =>
                    "Uses only the signed-in user country, license class, selected radio, and local RigPi band databases.",
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit();
    }

    /*
     * Activity matches:
     * country-specific > IARU region > worldwide,
     * then priority > narrowest range.
     */
    $activityRows = [];
    $activityToleranceHz = 100;

    if ($iaruRegion !== null) {
        $activityRows = $db->rawQuery(
            "SELECT
				Band,StartHz,EndHz,DialHz,Activity,Mode,
				Category,Priority,Notes,Country,IARURegion
			 FROM BandActivity
			 WHERE Enabled=1
			   AND ? BETWEEN (StartHz - ?) AND (EndHz + ?)
			   AND (
					Country=?
					OR (Country IS NULL AND IARURegion=?)
					OR (Country IS NULL AND IARURegion IS NULL)
			   )
			 ORDER BY
			   CASE
				 WHEN Country=? THEN 3
				 WHEN IARURegion=? THEN 2
				 ELSE 1
			   END DESC,
			   Priority DESC,
			   (EndHz-StartHz) ASC",
            [
                $freqHz,
                $activityToleranceHz,
                $activityToleranceHz,
                $country,
                $iaruRegion,
                $country,
                $iaruRegion
            ]
        );
    } else {
        $activityRows = $db->rawQuery(
            "SELECT
				Band,StartHz,EndHz,DialHz,Activity,Mode,
				Category,Priority,Notes,Country,IARURegion
			 FROM BandActivity
			 WHERE Enabled=1
			   AND ? BETWEEN (StartHz - ?) AND (EndHz + ?)
			   AND (Country=? OR (Country IS NULL AND IARURegion IS NULL))
			 ORDER BY
			   CASE WHEN Country=? THEN 2 ELSE 1 END DESC,
			   Priority DESC,
			   (EndHz-StartHz) ASC",
            [$freqHz, $activityToleranceHz, $activityToleranceHz, $country, $country]
        );
    }

    $activities = [];

    foreach ($activityRows as $row) {
        $activities[] = [
            "band" => (string) $row["Band"],
            "start_hz" => (int) $row["StartHz"],
            "end_hz" => (int) $row["EndHz"],
            "dial_hz" => $row["DialHz"] === null ? null : (int) $row["DialHz"],
            "activity" => (string) $row["Activity"],
            "mode" => trim((string) ($row["Mode"] ?? "")),
            "category" => (string) $row["Category"],
            "priority" => (int) $row["Priority"],
            "notes" => trim((string) ($row["Notes"] ?? "")),
        ];
    }

    $primary = $activities[0] ?? null;

    /*
     * Legal privilege matches.
     */
    $privilegeRows = [];

    if ($country !== "" && $licenseClass !== "") {
        $privilegeRows = $db->rawQuery(
            "SELECT
				Band,StartHz,EndHz,Privilege,
				MaxPowerW,PowerBasis,Notes
			 FROM BandPrivileges
			 WHERE Enabled=1
			   AND Country=?
			   AND LicenseClass=?
			   AND ? BETWEEN StartHz AND EndHz
			 ORDER BY
			   (EndHz-StartHz) ASC,
			   Id ASC",
            [$country, $licenseClass, $freqHz]
        );
    }

    $privileges = [];

    foreach ($privilegeRows as $row) {
        $privilegeText = trim((string) $row["Privilege"]);

        $privileges[] = [
            "band" => (string) $row["Band"],
            "start_hz" => (int) $row["StartHz"],
            "end_hz" => (int) $row["EndHz"],
            "privilege" => $privilegeText,
            "privileges" => array_values(
                array_filter(array_map("trim", explode(",", $privilegeText)))
            ),
            "max_power_w" =>
                $row["MaxPowerW"] === null ? null : (float) $row["MaxPowerW"],
            "power_basis" => trim((string) ($row["PowerBasis"] ?? "")),
            "notes" => trim((string) ($row["Notes"] ?? "")),
        ];
    }

    /*
     * Map the primary activity to the legal privilege terminology.
     */
    $requiredPrivilege = null;

    if ($primary) {
        $category = strtolower($primary["category"]);
        $activity = strtolower($primary["activity"]);
        $mode = strtoupper($primary["mode"]);

        if (in_array($category, ["digital", "data", "packet"], true)) {
            $requiredPrivilege = "Data";
        } elseif ($category === "image") {
            $requiredPrivilege = "Image";
        } elseif ($category === "cw" || $category === "beacon") {
            $requiredPrivilege = "CW";
        } elseif ($category === "phone") {
            $requiredPrivilege = "Phone";
        } elseif ($category === "calling") {
            if ($mode === "CW") {
                $requiredPrivilege = "CW";
            } elseif (in_array($mode, ["USB", "LSB", "AM", "FM"], true)) {
                $requiredPrivilege = "Phone";
            }
        }

        if (
            $requiredPrivilege === null &&
            in_array(
                $activity,
                ["ft8", "ft4", "rtty", "psk31", "js8", "js8call"],
                true
            )
        ) {
            $requiredPrivilege = "Data";
        }

        if ($requiredPrivilege === null && $activity === "sstv") {
            $requiredPrivilege = "Image";
        }
    }

    $frequencyPermitted = null;
    $activityPermitted = null;

    if ($country !== "" && $licenseClass !== "") {
        $coverageRows = $db->rawQuery(
            "SELECT Id FROM BandPrivileges
             WHERE Enabled=1 AND Country=? AND LicenseClass=?
             LIMIT 1",
            [$country, $licenseClass]
        );

        if (count($coverageRows) > 0) {
            $frequencyPermitted = count($privileges) > 0;

            if (!$frequencyPermitted) {
                $activityPermitted = false;
            } elseif ($requiredPrivilege !== null) {
                $activityPermitted = false;

                foreach ($privileges as $p) {
                    $allowed = array_map("strtolower", $p["privileges"]);

                    if (
                        in_array("all", $allowed, true) ||
                        in_array(strtolower($requiredPrivilege), $allowed, true)
                    ) {
                        $activityPermitted = true;
                        break;
                    }
                }
            }
        }
    }

    $band = $primary["band"] ?? ($privileges[0]["band"] ?? null);

    echo json_encode(
        [
            "frequency_hz" => $freqHz,
            "frequency_source" => $frequencySource,
            "selected_radio" => $selectedRadio,

            "band" => $band,
            "country" => $country,
            "license_class" => $licenseClass,
            "license_class_source" => $licenseClassSource,
            "signed_in_license_class" => $signedInLicenseClass,
            "iaru_region" => $iaruRegion,

            "primary_activity" => $primary,
            "activity_matches" => $activities,

            "frequency_permitted" => $frequencyPermitted,
            "required_privilege" => $requiredPrivilege,
            "activity_permitted" => $activityPermitted,
            "privilege_matches" => $privileges,

            "source" => "RigPi frequency intelligence",
            "privacy_notice" =>
                "Uses only the signed-in user country, license class, selected radio, and local RigPi band databases.",
        ],
        JSON_UNESCAPED_SLASHES
    );
} catch (JsonException | InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("ElmerFrequency.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "RigPi could not retrieve frequency intelligence.",
    ]);
}
