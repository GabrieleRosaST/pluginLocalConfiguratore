<?php


function creaCorso($fullname, $shortname, $categoryid, $summary, $format, $token, $url) {
    global $DB, $CFG;
    $functionname = 'core_course_create_courses';
    file_put_contents(__DIR__ . '/debug.log', "\n---\ncreaCorso: fullname=$fullname, shortname=$shortname, categoryid=$categoryid, summary=$summary, format=$format\n", FILE_APPEND);
    $postdata = [
        'courses' => [
            [
                'fullname' => $fullname,
                'shortname' => $shortname,
                'categoryid' => $categoryid,
                'summary' => $summary,
                'format' => $format,
                'newsitems'  => 0
            ]
        ]
    ];

    // Qui salvi l'array per debug
    file_put_contents(__DIR__ . '/debug.log', "POSTDATA: " . print_r($postdata, true) . "\n", FILE_APPEND);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url . '?wstoken=' . $token . '&wsfunction=' . $functionname . '&moodlewsrestformat=json');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postdata, '', '&'));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    file_put_contents(__DIR__ . '/debug.log', "RISPOSTA CURL: " . $response . "\n", FILE_APPEND);

    if ($response === false) {
        file_put_contents(__DIR__ . '/debug.log', "cURL ERROR: " . curl_error($curl) . "\n", FILE_APPEND);
        return ['success' => false, 'error' => 'Errore cURL: ' . curl_error($curl)];
    }

    curl_close($curl);

    $decodedResponse = json_decode($response, true);

    if (isset($decodedResponse['exception'])) {
        return ['success' => false, 'error' => $decodedResponse['message']];
    }

    if (!isset($decodedResponse[0]['id'])) {
        return ['success' => false, 'error' => 'Risposta API non valida: ID del corso mancante'];
    }

    $courseId = $decodedResponse[0]['id'];

    $DB->set_field('course_sections', 'visible', 0, ['course' => $courseId, 'section' => 0]);

    require_once($CFG->dirroot . '/course/lib.php');
    rebuild_course_cache($courseId, true);

    return ['success' => true, 'courseId' => $decodedResponse[0]['id']];
}





function aggiungiSezione($courseId, $sectionNum) {
    global $DB;

    file_put_contents(__DIR__ . '/debug.log', "aggiungiSezione: courseId=$courseId, sectionNum=$sectionNum\n", FILE_APPEND);

    // Verifica se la sezione esiste già
    $existing = $DB->get_record('course_sections', ['course' => $courseId, 'section' => $sectionNum]);
    if ($existing) {
        file_put_contents(__DIR__ . '/debug.log', "Sezione esistente trovata: id={$existing->id}\n", FILE_APPEND);
        return ['success' => true, 'sectionid' => $existing->id];
    }

    // Crea nuova sezione
    $newsection = new stdClass();
    $newsection->course  = $courseId;
    $newsection->section = $sectionNum;
    $newsection->name    = '';
    $newsection->summary = '';
    $newsection->summaryformat = FORMAT_HTML;
    $newsection->visible = 1;
    $newsection->timemodified = time();

    $sectionid = $DB->insert_record('course_sections', $newsection);
    file_put_contents(__DIR__ . '/debug.log', "Nuova sezione creata: id=$sectionid\n", FILE_APPEND);
    return ['success' => true, 'sectionid' => $sectionid];
}

function aggiungiRisorsaFileAllaSezione($courseid, $sectionnum, $sectionid, $file) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/mod/resource/lib.php');
    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/lib/resourcelib.php');
    require_once($CFG->dirroot . '/course/modlib.php');

    file_put_contents(__DIR__ . '/debug.log', "==== INIZIO aggiungiRisorsaFileAllaSezione ====\n", FILE_APPEND);
    file_put_contents(__DIR__ . '/debug.log', "courseid=$courseid, sectionnum=$sectionnum, sectionid=$sectionid, fileid={$file->moodlefileid}, filename={$file->filename}\n", FILE_APPEND);

    $fs = get_file_storage();

    // 1. Prendi il file dall'area permanente del plugin
    $permanentfile = $fs->get_file(
        context_system::instance()->id,
        'local_configuratore',
        'attachments',
        $file->argomentoid,
        '/',
        $file->filename
    );

    if (!$permanentfile) {
        file_put_contents(__DIR__ . '/debug.log', "ERRORE: File NON trovato nell'area permanente! Filename: {$file->filename}\n", FILE_APPEND);
        return;
    }

    // 2. Copia file nella draft area dell'utente
    $usercontext = context_user::instance($USER->id);
    $draftid = file_get_unused_draft_itemid();
    $fs->create_file_from_storedfile([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea'  => 'draft',
        'itemid'    => $draftid,
        'filepath'  => '/',
        'filename'  => $file->filename,
    ], $permanentfile);

    file_put_contents(__DIR__ . '/debug.log', "File copiato nella draft area user/{$draftid}\n", FILE_APPEND);

    $files_in_draft = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, '', false);
    file_put_contents(__DIR__ . '/debug.log', "File effettivamente nella draft area: ".print_r($files_in_draft, true), FILE_APPEND);

     // Recupera il NUMERO di sezione (non l'id)
    $sectionnumreal = $DB->get_field('course_sections', 'section', ['id' => $sectionid]);
    file_put_contents(__DIR__ . '/debug.log', "Section id=$sectionid corrisponde a sectionnum=$sectionnumreal\n", FILE_APPEND);

    // 3. Prepara l'oggetto moduleinfo (come se fosse il $_POST del form)
    $moduleinfo = new stdClass();
    $moduleinfo->course       = $courseid;
    $moduleinfo->name         = $file->filename;
    $moduleinfo->intro        = '';
    $moduleinfo->introformat  = FORMAT_HTML;
    $moduleinfo->visible      = 1;
    $moduleinfo->files        = $draftid;
    $moduleinfo->section      = $sectionnumreal; // <-- ID della sezione!
    $moduleinfo->display      = RESOURCELIB_DISPLAY_AUTO;

    // Campi per add_moduleinfo:
    $moduleinfo->modulename   = 'resource';
    $moduleinfo->module       = $DB->get_field('modules', 'id', ['name' => 'resource']);
    $moduleinfo->add          = 'resource';
    $moduleinfo->coursemodule = 0; // nuovo modulo

    file_put_contents(__DIR__.'/debug.log', "moduleinfo prima di add_moduleinfo: ".print_r($moduleinfo, true), FILE_APPEND);


    // Prepara il corso
    $courseobj = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

    file_put_contents(__DIR__ . '/debug.log', "Chiamo add_moduleinfo (sectionid=$sectionid)...\n", FILE_APPEND);

    // 4. Chiama add_moduleinfo (con moduleinfo, NON con $resource!)
    $cm = add_moduleinfo($moduleinfo, $courseobj, $sectionid);

    file_put_contents(__DIR__ . '/debug.log', "Chiamato add_moduleinfo per sectionid=$sectionid, cmid=" . ($cm && isset($cm->id) ? $cm->id : 'null') . "\n", FILE_APPEND);

    if ($cm && isset($cm->id)) {
        file_put_contents(__DIR__ . '/debug.log', "Modulo resource aggiunto correttamente! cmid={$cm->id}\n", FILE_APPEND);
    } else {
        file_put_contents(__DIR__ . '/debug.log', "ERRORE: add_moduleinfo ha fallito\n", FILE_APPEND);
    }

    file_put_contents(__DIR__ . '/debug.log', "==== FINE aggiungiRisorsaFileAllaSezione ====\n", FILE_APPEND);
}










function aggiornaNomeSezione($courseId, $sectionNumber, $name, $summary) {
    global $DB;

    file_put_contents(__DIR__ . '/debug.log', "aggiornaNomeSezione: courseId=$courseId, sectionNumber=$sectionNumber, name=$name, summary=$summary\n", FILE_APPEND);

    // Recupera la sezione giusta dal DB
    $section = $DB->get_record('course_sections', [
        'course' => $courseId,
        'section' => $sectionNumber
    ]);

    if (!$section) {
        file_put_contents(__DIR__ . '/debug.log', "Sezione non trovata\n", FILE_APPEND);
        return ['success' => false, 'error' => "Sezione non trovata per course=$courseId section=$sectionNumber"];
    }

    $section->name = $name;
    $section->summary = $summary;
    $section->summaryformat = FORMAT_HTML;

    $DB->update_record('course_sections', $section);
    file_put_contents(__DIR__ . '/debug.log', "Sezione aggiornata: id={$section->id}\n", FILE_APPEND);

    return ['success' => true];
}


function aggiungiModuloMoodle($courseId, $targetSectionId, $token, $url) {


    $moduleData = [
        'courseid' => $courseId,
        'targetsectionid' => $targetSectionId,
        'modname' => 'label',
    ];


    $moduleFunctionName = 'core_courseformat_new_module';
    $moduleCurl = curl_init();
    curl_setopt($moduleCurl, CURLOPT_URL, $url . '?wstoken=' . $token . '&wsfunction=' . $moduleFunctionName . '&moodlewsrestformat=json');
    curl_setopt($moduleCurl, CURLOPT_POST, true);
    curl_setopt($moduleCurl, CURLOPT_POSTFIELDS, http_build_query($moduleData));
    curl_setopt($moduleCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($moduleCurl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $moduleResponse = curl_exec($moduleCurl);
    $moduleResult = json_decode($moduleResponse, true);

    file_put_contents(__DIR__ . '/debug.log', "Risultato creazione modulo: " . print_r($moduleResult, true) . "\n", FILE_APPEND);

    if (isset($moduleResult['exception'])) {
        file_put_contents(__DIR__ . '/debug.log', "Errore creazione modulo: " . $moduleResult['message'] . "\n", FILE_APPEND);
        return ['success' => false, 'error' => $moduleResult['message']];
    }

    return ['success' => true, 'moduleid' => $moduleResult['id']];
}

function aggiungiBloccoChatbotAlCorso(int $courseid, string $region = 'above-content', int $weight = 0, int $showinsubcontexts = 0) {
    global $DB,$CFG;
    require_once($CFG->dirroot . '/course/lib.php');

    // Contesti & corso
    $course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context  = context_course::instance($courseid);
    $now      = time();

    // 1) Crea l'istanza del blocco (tabella block_instances)
    $bi = new stdClass();
    $bi->blockname         = 'chatbot';            // NOME DEL TUO BLOCCO: block_chatbot => 'chatbot'
    $bi->parentcontextid   = $context->id;
    $bi->showinsubcontexts = $showinsubcontexts;   // 0 di solito
    $bi->requiredbytheme   = 0;
    $bi->pagetypepattern   = 'course-view-*';      // visibile in tutte le view corso
    $bi->subpagepattern    = '';
    $bi->defaultregion     = $region;              //above-content per metterlo a lcentro cel corso
    $bi->defaultweight     = $weight;              // ordine
    $bi->configdata        = null;                 // se ti servono config specifiche, serializzale qui
    $bi->timecreated       = $now;
    $bi->timemodified      = $now;

    // Evita duplicati: se già c'è un'istanza di block_chatbot per questo corso, esci
    $giacenti = $DB->get_records('block_instances', [
        'blockname'       => 'chatbot',
        'parentcontextid' => $context->id,
        'pagetypepattern' => 'course-view-*'
    ], '', 'id');
    if (!empty($giacenti)) {
        // Già presente, ritorna OK con l'id esistente
        $existingid = array_key_first($giacenti);
        return ['success' => true, 'blockinstanceid' => $existingid, 'note' => 'già presente'];
    }

    $blockinstanceid = $DB->insert_record('block_instances', $bi);

    // 2) Posiziona il blocco nel layout (tabella block_positions)
    $bp = new stdClass();
    $bp->blockinstanceid = $blockinstanceid;
    $bp->contextid       = $context->id;
    $bp->pagetype        = 'course-view-' . $course->format; // es. course-view-topics
    $bp->subpage         = '';
    $bp->visible         = 1;
    $bp->region          = $region;   // 'above-content' = colonna centrale
    $bp->weight          = $weight;

    $DB->insert_record('block_positions', $bp);

    rebuild_course_cache($courseid, true);

    return ['success' => true, 'blockinstanceid' => $blockinstanceid];
}


function aggiungiChatbotAlCorso($courseid, $chatbotconfigid, $shortname, $description) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/course/modlib.php');

    file_put_contents(__DIR__ . '/debug.log', "[aggiungiChatbotAlCorso] courseid=$courseid, chatbotconfigid=$chatbotconfigid, shortname=$shortname\n", FILE_APPEND);

    // Carica il corso
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    // Recupera l'ID del modulo chatbot
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'chatbot'], MUST_EXIST);

    // Prepara i dati per il modulo chatbot
    $moduledata = new stdClass();
    $moduledata->modulename   = 'chatbot';         // nome tecnico del modulo
    $moduledata->module       = $moduleid;         // ID numerico del modulo
    $moduledata->section      = 0;                 // sezione generale
    $moduledata->course       = $courseid;         // ID corso
    $moduledata->name         = $shortname;        // nome visualizzato
    $moduledata->intro        = $description;      // descrizione visualizzata
    $moduledata->introformat  = FORMAT_HTML;
    $moduledata->chatbotid    = $chatbotconfigid;  // ID configurazione chatbot

    $moduledata->visible      = 1;
    $moduledata->groupmode    = 0;
    $moduledata->groupingid   = 0;

    // Aggiungi il modulo al corso
    $cm = add_moduleinfo($moduledata, $course);

    // Verifica cmid e fallback
    if ($cm && isset($cm->id) && $cm->id > 0) {
        file_put_contents(__DIR__ . '/debug.log', "[aggiungiChatbotAlCorso] Chatbot creato: cmid={$cm->id}\n", FILE_APPEND);
        return ['success' => true, 'cmid' => $cm->id];
    }

    // fallback: cerca nel DB
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'chatbot']);
    $instanceid = $DB->get_field('chatbot', 'id', ['chatbotid' => $chatbotconfigid]);
    $cmid = null;

    if ($moduleid && $instanceid) {
        $cmid = $DB->get_field('course_modules', 'id', [
            'course' => $courseid,
            'module' => $moduleid,
            'instance' => $instanceid
        ]);
    }

    if ($cmid) {
        file_put_contents(__DIR__ . '/debug.log', "[aggiungiChatbotAlCorso] Chatbot creato (fallback): cmid={$cmid}\n", FILE_APPEND);
        return ['success' => true, 'cmid' => $cmid];
    }

    file_put_contents(__DIR__ . '/debug.log', "[aggiungiChatbotAlCorso] ERRORE: impossibile aggiungere il chatbot\n", FILE_APPEND);
    return ['success' => false, 'error' => 'Errore nell’aggiunta del chatbot'];
}




