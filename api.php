<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'DBUSERNAME');
define('DB_PASSWORD', 'DBPASSWORD');
define('DB_NAME', 'db92');
define('API_SECRET_KEY', 'ali');

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}
$conn->set_charset("utf8mb4");

function getOrCreateGenreId($conn, $name) {
    $name = trim($name);
    $stmt = $conn->prepare("SELECT genre_id FROM genre WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['genre_id'];
    } else {
        $slug = str_replace(' ', '-', strtolower($name));
        $description = '';
        $order = 0;
        $stmt_insert = $conn->prepare("INSERT INTO genre (name, description, slug, publication, `order`) VALUES (?, ?, ?, 1, ?)");
        $stmt_insert->bind_param("sssi", $name, $description, $slug, $order);
        $stmt_insert->execute();
        return $conn->insert_id;
    }
}
function getOrCreateCountryId($conn, $name) {
    $name = trim($name);
    $stmt = $conn->prepare("SELECT country_id FROM country WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['country_id'];
    } else {
        $slug = str_replace(' ', '-', strtolower($name));
        $description = '';
        $stmt_insert = $conn->prepare("INSERT INTO country (name, description, slug, publication) VALUES (?, ?, ?, 1)");
        $stmt_insert->bind_param("sss", $name, $description, $slug);
        $stmt_insert->execute();
        return $conn->insert_id;
    }
}
function getOrCreateStarId($conn, $name, $type) {
    $name = trim($name);
    $stmt = $conn->prepare("SELECT star_id FROM star WHERE star_name = ? AND star_type = ?");
    $stmt->bind_param("ss", $name, $type);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['star_id'];
    } else {
        $slug = str_replace(' ', '-', strtolower($name));
        $stmt_insert = $conn->prepare("INSERT INTO star (star_name, star_type, slug) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("sss", $name, $type, $slug);
        $stmt_insert->execute();
        return $conn->insert_id;
    }
}
function convertSizeToMB($sizeStr, $fullSizeText) {
    $size = floatval($sizeStr);
    if ($fullSizeText === null) { return round($size); }
    if (stripos($fullSizeText, 'گیگابایت') !== false || stripos($fullSizeText, 'gb') !== false) {
        return round($size * 1024);
    }
    return round($size);
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['key']) || !isset($data['imdb_id'])) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid or missing input data.']); exit();
}
if ($data['key'] !== API_SECRET_KEY) {
    http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'Forbidden: Invalid API key.']); exit();
}

$conn->begin_transaction();

try {
    $imdbid = $data['imdb_id'];
    $title = $data['title'];
    $description = $data['dsc'] ?? '';
    $rating = $data['rate'] ?? '0';
    $release_year = $data['year'] ?? '';
    $runtime = isset($data['time']) ? preg_replace('/[^0-9]/', '', $data['time']) : null;
    $is_tvseries = isset($data['seasons']) && !empty($data['seasons']) ? 1 : 0;
    $url_source = $data['url'] ?? '';
    $site_source = $data['site_source'] ?? 0;
    $genre_ids = []; if (!empty($data['ganre'])) { foreach ($data['ganre'] as $genre_name) { $genre_ids[] = getOrCreateGenreId($conn, $genre_name); } }
    $country_ids = []; if (!empty($data['country'])) { foreach ($data['country'] as $country_name) { $country_ids[] = getOrCreateCountryId($conn, $country_name); } }
    $director_ids = []; if (!empty($data['director'])) { foreach ($data['director'] as $director_name) { $director_ids[] = getOrCreateStarId($conn, $director_name, 'director'); } }
    $star_ids = []; if (!empty($data['stars'])) { foreach ($data['stars'] as $star_name) { $star_ids[] = getOrCreateStarId($conn, $star_name, 'actor'); } }
    $genre_str = implode(',', $genre_ids);
    $country_str = implode(',', $country_ids);
    $director_str = implode(',', $director_ids);
    $stars_str = implode(',', $star_ids);
    $slug = str_replace(' ', '-', strtolower(preg_replace('/[^A-Za-z0-9 ]/', '', $title))) . '-' . $release_year;

    $videos_id = null;
    $stmt_check = $conn->prepare("SELECT videos_id FROM videos WHERE imdbid = ?");
    $stmt_check->bind_param("s", $imdbid);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows > 0) {
        $videos_id = $result_check->fetch_assoc()['videos_id'];
        $stmt_video = $conn->prepare("UPDATE videos SET title=?, seo_title=?, slug=?, description=?, stars=?, director=?, rating=?, `release`=?, country=?, genre=?, runtime=?, is_tvseries=?, url_source=?, site_source=? WHERE videos_id=?");
        $stmt_video->bind_param("ssssssssssssisi", $title, $title, $slug, $description, $stars_str, $director_str, $rating, $release_year, $country_str, $genre_str, $runtime, $is_tvseries, $url_source, $site_source, $videos_id);
        $stmt_video->execute();
    } else {
        $publication = 1;
        $last_ep_added = date('Y-m-d H:i:s');
        $total_view_admin = 0;
        $imdbid_safe = $conn->real_escape_string($imdbid);
        $title_safe = $conn->real_escape_string($title);
        $slug_safe = $conn->real_escape_string($slug);
        $description_safe = $conn->real_escape_string($description);
        $stars_str_safe = $conn->real_escape_string($stars_str);
        $director_str_safe = $conn->real_escape_string($director_str);
        $rating_safe = $conn->real_escape_string($rating);
        $release_year_safe = $conn->real_escape_string($release_year);
        $country_str_safe = $conn->real_escape_string($country_str);
        $genre_str_safe = $conn->real_escape_string($genre_str);
        $runtime_safe = $conn->real_escape_string($runtime);
        $url_source_safe = $conn->real_escape_string($url_source);
        $last_ep_added_safe = $conn->real_escape_string($last_ep_added);

        $sql = "INSERT INTO videos (imdbid, title, seo_title, slug, description, stars, director, rating, `release`, country, genre, runtime, is_tvseries, publication, last_ep_added, url_source, site_source, total_view_admin) VALUES ('{$imdbid_safe}', '{$title_safe}', '{$title_safe}', '{$slug_safe}', '{$description_safe}', '{$stars_str_safe}', '{$director_str_safe}', '{$rating_safe}', '{$release_year_safe}', '{$country_str_safe}', '{$genre_str_safe}', '{$runtime_safe}', {$is_tvseries}, {$publication}, '{$last_ep_added_safe}', '{$url_source_safe}', {$site_source}, {$total_view_admin})";
        if (!$conn->query($sql)) { throw new Exception("Direct Query Failed (videos): " . $conn->error); }
        $videos_id = $conn->insert_id;
    }

    if (!$is_tvseries && isset($data['links']) && is_array($data['links'])) {
        $conn->query("DELETE FROM video_file WHERE videos_id = {$videos_id}");
        foreach ($data['links'] as $link) {
            $file_url_safe = $conn->real_escape_string($link['link_href'] ?? '');
            $label_safe = $conn->real_escape_string($link['label'] ?? '');
            $quality_safe = $conn->real_escape_string($link['quality'] ?? '');
            $quality_text_safe = $conn->real_escape_string($link['link_quality'] ?? '');
            $dubbed = (isset($link['ptype']) && stripos($link['ptype'], 'دوبله') !== false) ? 1 : 0;
            $file_size = (int)convertSizeToMB($link['size'] ?? 0, $link['link_size'] ?? null);
            $size_text_safe = $conn->real_escape_string($link['link_size'] ?? '');

            $sql_file = "INSERT INTO video_file (videos_id, file_source, file_url, source_type, label, label_text, quality, quality_text, dubbed, file_size, size_text, site_source, add_datetime) VALUES ({$videos_id}, 'url', '{$file_url_safe}', 'mp4', '{$label_safe}', '{$label_safe}', '{$quality_safe}', '{$quality_text_safe}', {$dubbed}, {$file_size}, '{$size_text_safe}', {$site_source}, NOW())";
            if (!$conn->query($sql_file)) { throw new Exception("Direct Query Failed (video_file): " . $conn->error); }
        }
    }

    if ($is_tvseries && isset($data['seasons']) && is_array($data['seasons'])) {
        foreach ($data['seasons'] as $season_data) {
            $season_num = (int)($season_data['season_number'] ?? 0);
            $seasons_name_safe = $conn->real_escape_string($season_data['title'] ?? "Season {$season_num}");
            $dubbed = (isset($season_data['ptype']) && stripos($season_data['ptype'], 'دوبله') !== false) ? 1 : 0;

            $seasons_id = null;
            $stmt_check_season = $conn->prepare("SELECT seasons_id FROM seasons WHERE videos_id = ? AND seasons_num = ? AND dubbed = ?");
            $stmt_check_season->bind_param("iii", $videos_id, $season_num, $dubbed);
            $stmt_check_season->execute();
            $result_season = $stmt_check_season->get_result();
            if($result_season->num_rows > 0){
                $seasons_id = $result_season->fetch_assoc()['seasons_id'];
            } else {
                $quality_safe = $conn->real_escape_string($season_data['quality'] ?? '');
                $quality_text_safe = $conn->real_escape_string($season_data['quality_text'] ?? '');
                $file_size = isset($season_data['size']) ? intval($season_data['size']) : 0;
                $size_text_safe = $conn->real_escape_string($season_data['size_text'] ?? '');

                $sql_season = "INSERT INTO seasons (videos_id, seasons_name, seasons_num, dubbed, quality, quality_text, file_size, size_text, site_source) VALUES ({$videos_id}, '{$seasons_name_safe}', {$season_num}, {$dubbed}, '{$quality_safe}', '{$quality_text_safe}', {$file_size}, '{$size_text_safe}', {$site_source})";
                if (!$conn->query($sql_season)) { throw new Exception("Direct Query Failed (seasons): " . $conn->error); }
                $seasons_id = $conn->insert_id;
            }

            $conn->query("DELETE FROM episodes WHERE seasons_id = {$seasons_id}");

            if (isset($season_data['links'])) {
                $episodes = json_decode($season_data['links'], true);
                if (is_array($episodes)) {
                    foreach ($episodes as $episode_data) {
                        $episode_name_safe = $conn->real_escape_string("Episode " . ($episode_data['title'] ?? 'N/A'));
                        $file_url_safe = $conn->real_escape_string($episode_data['url']);
                        $ep_order = intval($episode_data['title'] ?? 0);

                        $sql_episode = "INSERT INTO episodes (videos_id, seasons_id, episodes_name, file_source, file_url, source_type, `order`, date_added, site_source) VALUES ({$videos_id}, {$seasons_id}, '{$episode_name_safe}', 'url', '{$file_url_safe}', 'mp4', {$ep_order}, NOW(), {$site_source})";
                        if (!$conn->query($sql_episode)) { throw new Exception("Direct Query Failed (episodes): " . $conn->error); }
                    }
                }
            }
        }
    }

    $conn->commit();
    http_response_code(201);
    echo json_encode(['status' => 'success', 'message' => "Video '{$title}' processed successfully.", 'videos_id' => $videos_id]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
}

$conn->close();
?>