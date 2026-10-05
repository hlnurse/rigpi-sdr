<?php
/** Serve authenticated study questions and complete simulated NCVEC exams. */

function exam_question_payload(array $question, array $pool, string $licenseClass, bool $figuresOnly = false): array
{
    $choices = $question["choices"] ?? [];
    $questionId = (string) ($question["id"] ?? "");
    $figureFile = trim((string) ($question["figure_file"] ?? ""));
    $figureLabel = trim((string) ($question["figure_label"] ?? ""));
    if (
        $figureFile !== "" &&
        !preg_match('/^(technician|general|extra)\/[TGE][0-9-]+\.(?:png|jpe?g)$/', $figureFile)
    ) {
        throw new RuntimeException("The selected official figure is invalid.");
    }
    if (
        !preg_match('/^[TGE]\d[A-Z]\d{2}$/', $questionId) ||
        !is_array($choices) || count($choices) !== 4 ||
        !in_array((string) ($question["correct_letter"] ?? ""), ["A", "B", "C", "D"], true)
    ) {
        throw new RuntimeException("The selected official question is invalid.");
    }
    return [
        "license_class" => $licenseClass,
        "element" => (int) ($pool["element"] ?? 0),
        "pool_title" => (string) ($pool["title"] ?? ""),
        "effective_from" => (string) ($pool["effective_from"] ?? ""),
        "effective_to" => (string) ($pool["effective_to"] ?? ""),
        "errata_date" => (string) ($pool["errata_date"] ?? ""),
        "provider" => (string) ($pool["provider"] ?? ""),
        "source_url" => (string) ($pool["source_url"] ?? ""),
        "notice" => (string) ($pool["notice"] ?? ""),
        "question_id" => $questionId,
        "group" => (string) ($question["group"] ?? ""),
        "topic" => (string) ($question["topic"] ?? ""),
        "question" => (string) ($question["question"] ?? ""),
        "choices" => array_values(array_map("strval", $choices)),
        "correct_letter" => (string) $question["correct_letter"],
        "fcc_reference" => (string) ($question["fcc_reference"] ?? ""),
        "figure_label" => $figureLabel,
        "figure_url" => $figureFile !== "" ? "/images/elmer-exam/" . $figureFile : "",
        "figures_only" => $figuresOnly,
    ];
}

session_start();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if (empty($_SESSION["myUsername"])) {
    http_response_code(401);
    echo json_encode(["error" => "Please sign in to RigPi."]);
    exit();
}

ini_set("display_errors", "0");
ini_set("log_errors", "1");

try {
    $request = json_decode(
        file_get_contents("php://input"),
        true,
        4,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($request)) {
        throw new InvalidArgumentException("The study request is invalid.");
    }

    $aliases = [
        "t" => "technician", "tech" => "technician", "technician" => "technician",
        "g" => "general", "general" => "general",
        "e" => "extra", "amateur extra" => "extra", "extra" => "extra",
    ];
    $requested = strtolower(trim((string) ($request["license_class"] ?? "technician")));
    $licenseClass = $aliases[$requested] ?? "";
    if ($licenseClass === "") {
        throw new InvalidArgumentException("Choose Technician, General, or Extra.");
    }

    $poolPath = "/var/lib/rigpi/elmer/elmer_exam_pools.json";
    $raw = @file_get_contents($poolPath);
    if ($raw === false) {
        throw new RuntimeException("The official exam question pools are not installed.");
    }
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $pool = $data["pools"][$licenseClass] ?? null;
    $questions = is_array($pool) ? ($pool["questions"] ?? null) : null;
    if (!is_array($questions) || count($questions) < 1) {
        throw new RuntimeException("That exam question pool is unavailable.");
    }

    $mode = strtolower(trim((string) ($request["mode"] ?? "question")));
    if ($mode === "exam") {
        $byGroup = [];
        foreach ($questions as $row) {
            if (!is_array($row)) continue;
            $group = trim((string) ($row["group"] ?? ""));
            if ($group !== "") $byGroup[$group][] = $row;
        }
        ksort($byGroup, SORT_NATURAL);
        $expectedCount = $licenseClass === "extra" ? 50 : 35;
        $passingScore = $licenseClass === "extra" ? 37 : 26;
        if (count($byGroup) !== $expectedCount) {
            throw new RuntimeException("The official pool does not have the expected exam groups.");
        }
        $selected = [];
        foreach ($byGroup as $rows) {
            $selected[] = exam_question_payload(
                $rows[random_int(0, count($rows) - 1)],
                $pool,
                $licenseClass
            );
        }
        echo json_encode([
            "schema_version" => 1,
            "mode" => "exam",
            "exam_id" => bin2hex(random_bytes(8)),
            "license_class" => $licenseClass,
            "element" => (int) ($pool["element"] ?? 0),
            "pool_title" => (string) ($pool["title"] ?? ""),
            "source_url" => (string) ($pool["source_url"] ?? ""),
            "notice" => (string) ($pool["notice"] ?? ""),
            "question_count" => $expectedCount,
            "passing_score" => $passingScore,
            "questions" => $selected,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
    }
    if ($mode !== "question") {
        throw new InvalidArgumentException("Choose study-question or complete-exam mode.");
    }

    $figuresOnly = ($request["figures_only"] ?? false) === true;
    $questionSource = $figuresOnly
        ? array_values(array_filter($questions, static fn($row) => ($row["has_figure"] ?? false) === true))
        : $questions;
    if (!$questionSource) {
        throw new RuntimeException("That pool has no figure questions available.");
    }

    $sessionKey = "elmer_exam_recent_" . $licenseClass;
    $recent = isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])
        ? array_values(array_filter($_SESSION[$sessionKey], "is_string"))
        : [];
    $available = array_values(array_filter(
        $questionSource,
        static fn($row) => is_array($row) && !in_array((string) ($row["id"] ?? ""), $recent, true)
    ));
    if (!$available) {
        $available = $questionSource;
        $recent = [];
    }
    $question = $available[random_int(0, count($available) - 1)];
    $questionId = (string) ($question["id"] ?? "");
    $recent[] = $questionId;
    $_SESSION[$sessionKey] = array_slice(array_values(array_unique($recent)), -30);

    echo json_encode(["schema_version" => 1] + exam_question_payload(
        $question,
        $pool,
        $licenseClass,
        $figuresOnly
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(["error" => $error->getMessage()]);
} catch (Throwable $error) {
    error_log("ElmerExamQuestion: " . $error->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "RigPi could not retrieve an exam question."]);
}
