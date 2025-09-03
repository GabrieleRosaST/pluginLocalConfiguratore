<?php
require_once(dirname(__DIR__, 3) . '/config.php');
require_once 'creaCorsoFunctions.php';

function creaCorsoMoodle($fullname, $shortname, $categoryid, $argomenti, $chatbotid, $titoloid=[], $summary = '', $format = 'topics')
{
    global $DB,$CFG;
    $url = $CFG->wwwroot . '/webservice/rest/server.php';
    require_once($CFG->dirroot . '/local/configuratore/lib.php');
    $token = local_configuratore_get_webservice_token();

    file_put_contents(__DIR__ . '/debug.log', "URL creacorso: $url", FILE_APPEND);

    if (empty($token)) {
        throw new moodle_exception('No webservice token found in plugin config');
    }

    // CREA IL CORSO
    $createCourseResult = creaCorso($fullname, $shortname, $categoryid, $summary, $format, $token, $url);

    if (!$createCourseResult['success']) {
        return $createCourseResult;
    }
    $courseId = $createCourseResult['courseId'];
    file_put_contents(__DIR__ . '/debug.log', "Corso creato con ID: $courseId\n", FILE_APPEND);

    /*
    // Prendi la lista delle sezioni del corso
    $courseContentsCurl = curl_init();
    curl_setopt($courseContentsCurl, CURLOPT_URL, $url . '?wstoken=' . $token . '&wsfunction=core_course_get_contents&moodlewsrestformat=json&courseid=' . $courseId);
    curl_setopt($courseContentsCurl, CURLOPT_RETURNTRANSFER, true);
    $courseContentsResponse = curl_exec($courseContentsCurl);
    curl_close($courseContentsCurl);
    $courseContents = json_decode($courseContentsResponse, true);
    */

    // AGGIUNI SEZIONI +file
    foreach ($argomenti as $index => $argomento) {
        // 1. CREA LA SEZIONE
        $addSectionResult = aggiungiSezione($courseId, $index + 1, $token, $url);
        if (!$addSectionResult['success']) return $addSectionResult;

        // 2. AGGIORNA IL NOME DELLA SEZIONE
        $summary = isset($argomento['giorno'][0]) ? "Data: " . $argomento['giorno'][0] : '';
        $updateSectionResult = aggiornaNomeSezione($courseId, $index + 1, $argomento['titolo'], $summary);

        if (!$updateSectionResult['success']) return $updateSectionResult;

        // 3. Prendi tutti i file di QUESTO argomento/questo chatbot
        $sql = "SELECT * FROM {local_configuratore_files} WHERE chatbotid = ? AND argomentoid = ?";
        $argomentoId = $titoloid[$index];
        $files = $DB->get_records_sql($sql, [$chatbotid, $argomentoId]);
        file_put_contents(__DIR__ . '/debug.log', "Query files: chatbotid=$chatbotid, argomentoid={$titoloid[$index]}, count=".count($files)."\n", FILE_APPEND);


        // 4. Trova la section id
        file_put_contents(__DIR__ . '/debug.log', "FOREACH argomento: ".print_r($argomento, true), FILE_APPEND);
        file_put_contents(__DIR__ . '/debug.log', "TITOLOID: ".print_r($titoloid, true), FILE_APPEND);

        $sectionnum = $index + 1;
        $section = $DB->get_record('course_sections', ['course' => $courseId, 'section' => $sectionnum]);
        $sectionid = $section ? $section->id : null;

        // 5. Aggiungi i file come risorsa (resource) nella sezione
        foreach ($files as $file) {

            file_put_contents(__DIR__ . '/debug.log', "Aggiungo file come risorsa: ".$file->filename."\n", FILE_APPEND);
            aggiungiRisorsaFileAllaSezione($courseId, $sectionnum, $sectionid, $file); // NOTA L'ORDINE!
        }

    }
    //Aggiungo il chatbot al corso
    $chatbotResult = aggiungiBloccoChatbotAlCorso($courseId, 'above-content', 0, 0);
    if (!$chatbotResult['success']) {
        return $chatbotResult;
    }

    return ['success' => true, 'course' => ['id' => $courseId]];
}