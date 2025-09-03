<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();
//prova

/**
 * Serve files for local_configuratore.
 *
 * @package    local_configuratore
 */
function local_configuratore_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG, $USER, $DB;

    require_once($CFG->libdir . '/filelib.php');

    // Debug
    error_log(">>> local_configuratore_pluginfile called: contextid={$context->id}, filearea={$filearea}, args=" . json_encode($args));

    // 1️⃣ Verifica context corretto
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        error_log(">>> local_configuratore_pluginfile: WRONG CONTEXT");
        return false;
    }

    // 2️⃣ Solo attachments
    if ($filearea !== 'attachments') {
        error_log(">>> local_configuratore_pluginfile: INVALID FILEAREA $filearea");
        return false;
    }

    // 3️⃣ Utente loggato oppure token valido
    if (!isloggedin()) {
        // Se non loggato, verifica token ufficiale Moodle
        $usertoken = optional_param('token', '', PARAM_ALPHANUM);
        $tokenrecord = $DB->get_record('external_tokens', ['token' => $usertoken]);

        if (!$tokenrecord) {
            error_log(">>> local_configuratore_pluginfile: NO LOGIN / INVALID MOODLE TOKEN: $usertoken");
            return false;
        }

        // Forza user login come owner del token
        $user = $DB->get_record('user', ['id' => $tokenrecord->userid]);
        if (!$user) {
            error_log(">>> local_configuratore_pluginfile: TOKEN OK, BUT USER NOT FOUND");
            return false;
        }

        \core\session\manager::set_user($user);
        error_log(">>> local_configuratore_pluginfile: TOKEN OK, USER={$user->id}");
    }

    // 4️⃣ Recupera parametri
    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = '/';
    if ($args) {
        $filepath .= implode('/', $args) . '/';
    }

    // 5️⃣ Recupera file
    $fs = get_file_storage();
    $file = $fs->get_file(
        $context->id,
        'local_configuratore',
        'attachments',
        $itemid,
        $filepath,
        $filename
    );

    if (!$file || $file->is_directory()) {
        error_log(">>> local_configuratore_pluginfile: FILE NOT FOUND itemid=$itemid, filename=$filename");
        return false;
    }

    // 6️⃣ Serve il file
    error_log(">>> local_configuratore_pluginfile: SERVING FILE itemid=$itemid, filename=$filename");
    send_stored_file($file, 0, 0, true, $options);
}


/**
 * Genera URL firmata per file (con token)
 * @package local_configuratore
 */
function local_configuratore_pluginfile_url($file) {
    global $CFG, $DB;


    require_once($CFG->libdir . '/filelib.php');

    $envpath = __DIR__ . '/.env';
    if ($envpath && file_exists($envpath)) {
        $lines = file($envpath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
            list($name, $value) = array_map('trim', explode('=', $line, 2));
            putenv("$name=$value");
        }
    } else {
        error_log("[WARNING] .env non trovato in $envpath");
    }

    $url = moodle_url::make_pluginfile_url(
        $file->get_contextid(),
        $file->get_component(),
        $file->get_filearea(),
        $file->get_itemid(),
        $file->get_filepath(),
        $file->get_filename(),
        false
    );

    $urlstring = $url->out(true);
    error_log(">>> local_configuratore_pluginfile_url ORIG: $urlstring");

    // SOLO dominio
    $realdomain = getenv('PUBLIC_MOODLE_URL');

    error_log(">>> local_configuratore_pluginfile_url REALDOMAIN: $realdomain");//controllare questo che non viene messo nella urlstring

    $urlstring = preg_replace('#^http://localhost/moodle#', $realdomain . '/moodle', $urlstring);

    error_log(">>> URL finale: $urlstring");

    // Prendo un token valido
    $service = $DB->get_record('external_services', ['shortname' => 'local_configuratore_service']);
    error_log(">>> DEBUG: SERVICE FOUND: " . print_r($service, true));
    if ($service) {
        $tokenreclist = $DB->get_records_sql("
            SELECT t.*, u.username
            FROM {external_tokens} t
            JOIN {user} u ON u.id = t.userid
            WHERE t.externalserviceid = :serviceid
            ORDER BY t.timecreated DESC
        ", ['serviceid' => $service->id]);
        error_log('>>> DEBUG: TOKENRECLIST: '.print_r($tokenreclist, true));
        $tokenrec = reset($tokenreclist);
    } else {
        $tokenrec = false;
    }



    if ($tokenrec && !empty($tokenrec->token)) {
        $urlstring .= (strpos($urlstring, '?') === false ? '?' : '&') . 'token=' . $tokenrec->token;
        error_log(">>> local_configuratore_pluginfile_url TOKEN: {$tokenrec->token}");
    } else {
        error_log(">>> local_configuratore_pluginfile_url NO VALID TOKEN FOUND!");
    }

    error_log(">>> local_configuratore_pluginfile_url FINAL: $urlstring");

    return $urlstring;
}

function local_configuratore_get_webservice_token() {
    global $DB, $USER, $CFG;

    $token = get_config('local_configuratore', 'webservicetoken');
    if (!empty($token)) {
        return $token;
    }

    // Prova a prendere il servizio (deve essere già stato creato da services.php)
    require_once($CFG->libdir . '/externallib.php');
    $service = $DB->get_record('external_services', ['shortname' => 'local_configuratore_service']);
    if ($service) {
        $token = external_generate_token(EXTERNAL_TOKEN_PERMANENT, $service->id, $USER->id, context_system::instance());
        set_config('webservicetoken', $token, 'local_configuratore');
        return $token;
    } else {
        throw new moodle_exception('Service local_configuratore_service not found.');
    }
}



function local_generate_signed_url($file) {
    return local_configuratore_pluginfile_url($file);
}

function local_configuratore_extend_navigation(global_navigation $navigation) {
    $url = new moodle_url('/local/configuratore/index.php');
    $navigation->add(
        get_string('pluginname', 'local_configuratore'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_configuratore'
    );
}

function local_configuratore_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    if (is_siteadmin()) {
        $nodeadmin = $settingsnav->find('root', navigation_node::TYPE_SITE_ADMIN);
        if ($nodeadmin) {
            $url = new moodle_url('/local/configuratore/index.php');
            $nodeadmin->add(
                get_string('pluginname', 'local_configuratore'),
                $url,
                navigation_node::TYPE_CUSTOM,
                null,
                'local_configuratore'
            );
        }
    }
}


