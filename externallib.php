<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Libreria esterna per il blocco configuratore del chatbot.
 * Gestisce le chiamate API per salvare la configurazione.
 *
 * @package    local_configuratore
 * @copyright  2025 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Assicurati che questo file sia chiamato solo dall'interno di Moodle.
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(dirname(__DIR__, 2) . '/config.php');
require_once(__DIR__ . '/api/creaCorso.php');

$envpath = __DIR__ . '/.env';
if (file_exists($envpath)) {
    $lines = file($envpath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($name, $value) = array_map('trim', explode('=', $line, 2));
        putenv("$name=$value");
    }
}

/**
 * endpoint per le operazioni esterne del blocco configuratore del chatbot
 *
 * Questa classe definisce le funzioni API esterne (web service) che consentono
 * di interagire con i dati di configurazione  del chatbot e i file associati
 * all'interno del blocco 'configuratore'. Include funzionalità per creare,
 * leggere, aggiornare ed eliminare le configurazioni del chatbot.
 *
 * @package    local_configuratore
 * @copyright  2025 YOUR NAME <your@mail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_configuratore_external extends external_api {

    /**
     * Salva una nuova configurazione di una chatbot nel database.
     *
     * Questa API permette di creare una nuova configurazione di un chatbot
     * con i dati iniziali, gli argomenti e i file associati.
     * Vengono gestite le transazioni per garantire l'integrità dei dati.
     * i file caricati vengono spostati dalla draft all'area file permanente di Moodle.
     *
     * @param string $data JSON contenente i dati di configurazione del chatbot e degli argomenti.
     * @param array $filedata Array di oggetti contenenti i dettagli dei file caricati.
     * @return array Un array contenente 'success' (bool) e 'configid' (int) della configurazione creata.
     * @throws moodle_exception Se i dati JSON non sono validi , mancano capability o se si verificano errori durante il salvataggio.
     */
    public static function save_chatbot_config($data, $filedata = []) {
        global $DB, $USER, $CFG;
        file_put_contents(__DIR__ . '/debug.log', "\n--------------------------------------------------------------------------------\n", FILE_APPEND);
        file_put_contents(__DIR__ . '/debug.log', "INIZIO SAVE_CHATBOT_CONFIG\n", FILE_APPEND);

        //error_log('FILEDATA: ' . print_r($filedata, true));
        error_log('RAW INPUT: ' . file_get_contents('php://input'));

        self::validate_parameters(
            self::save_chatbot_config_parameters(),
            ['data' => $data, 'filedata' => $filedata]
        );
        //file_put_contents(__DIR__ . '/debug.log', "FILEDATA: " . print_r($filedata, true), FILE_APPEND);

        self::validate_context(context_system::instance());
        require_capability('local/configuratore:manage', context_system::instance());

        $decodeddata = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidjson', 'local_configuratore', '', json_last_error_msg());
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $dati = $decodeddata['DatiIniziali'];
            $argomenti = $decodeddata['argomenti'];

            // 1. Crea chatbot
            $record = (object)[
                'courseid' => 1,
                'userid' => $USER->id,
                'nomechatbot' => $dati['nomeChatbot'],
                'corsochatbot' => $dati['corsoChatbot'],
                'descrizionechatbot' => $dati['descrizioneChatbot'],
                'istruzionichatbot' => $dati['istruzioniChatbot'],
                'datainizio' => strtotime($dati['dataInizio']),
                'datafine' => strtotime($dati['dataFine']),
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $chatbotid = $DB->insert_record('local_configuratore_chatbot', $record);

            $context = context_system::instance();

            // 2. Crea argomenti
            $context = context_system::instance();
            $titolo2id = [];
            $fs = get_file_storage();

            foreach ($argomenti as $index => $args) {
                file_put_contents(
                    __DIR__.'/debug.log',
                    "🧪 INIZIO ARGOMENTO: index=$index, titolo={$args['titolo']}\n",
                    FILE_APPEND
                );
                // 1. Crea argomento
                $argomento = (object)[
                    'chatbotid' => $chatbotid,
                    'titolo' => $args['titolo'],
                    'giorno' => isset($args['giorno'][0]) ? strtotime($args['giorno'][0]) : 0,
                    'timecreated' => time(),
                ];
                $argomentoid = $DB->insert_record('local_configuratore_argomenti', $argomento);
                $titolo2id[$args['titolo']] = $argomentoid;

                // Trova l’itemid associato a questo argomento (tutti i file di un argomento condividono lo stesso itemid)
                $argomentoitemid = null;
                foreach ($filedata as $f) {
                    file_put_contents(
                        __DIR__.'/debug.log',
                        "🔍 Confronto filedata: file='{$f['filename']}', file argomento={$f['argomento']} vs index=" . ($index+1) . "\n",
                        FILE_APPEND
                    );
                    if ((string)$f['argomento'] === (string)($index + 1)) {
                        file_put_contents(
                            __DIR__.'/debug.log',
                            "✅ MATCH trovato: itemid={$f['itemid']} per argomento index=" . ($index+1) . "\n",
                            FILE_APPEND
                        );
                        $argomentoitemid = $f['itemid'];
                        break;
                    }
                }

                if (!empty($argomentoitemid)) {
                    file_save_draft_area_files(
                        $argomentoitemid,
                        $context->id,
                        'local_configuratore',
                        'attachments',
                        $argomentoid,
                        [
                            'subdirs' => 0,
                            'maxfiles' => -1,
                            'maxbytes' => 0,
                        ]
                    );

                    // Registra i file in DB
                    $files = $fs->get_area_files(
                        $context->id,
                        'local_configuratore',
                        'attachments',
                        $argomentoid,
                        'sortorder, timemodified',
                        false
                    );

                    //file_put_contents(__DIR__ . '/debug.log', "FILES AFTER SAVE: " . print_r($files, true), FILE_APPEND);

                    foreach ($files as $file) {
                        if ($file->is_directory()) continue;

                        $DB->insert_record('local_configuratore_files', [
                            'chatbotid'    => $chatbotid,
                            'argomentoid'  => $argomentoid,
                            'moodlefileid' => $file->get_id(),
                            'filename'     => $file->get_filename(),
                            'mimetype'     => $file->get_mimetype(),
                            'status'       => 'pending',
                            'url'          => null,
                            'timecreated'  => time(),
                            'timemodified' => time(),
                        ]);
                    }
                }
            }
            $transaction->allow_commit();
        } catch (Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // Chiamo creaCorsoMoodle qui

        $risultato_corso = creaCorsoMoodle(
            $dati['corsoChatbot'],           // fullname
            $dati['corsoChatbot'],            // shortname
            4,                               // categoryid
            $argomenti,                      // array argomenti così come hai in input
            $chatbotid,                      // chatbot id appena creato
            array_values($titolo2id),        // array degli id argomento, in ordine!
            $dati['descrizioneChatbot']      // summary (opzionale)
        );
        // Qui puoi loggare il risultato, se vuoi
        //file_put_contents(__DIR__ . '/debug.log', "Risultato creazione corso: " . print_r($risultato_corso, true), FILE_APPEND);

        //aggiorno il courseid della tabella configuratore chatbot
        if (!empty($risultato_corso['course']['id'])) {
            $DB->set_field('local_configuratore_chatbot', 'courseid', $risultato_corso['course']['id'], ['id' => $chatbotid]);
        }


        file_put_contents(
            __DIR__ . '/debug.log',
            //"Prima del return - risultato_corso: " . print_r($risultato_corso, true) . " | chatbotid: $chatbotid\n",
            FILE_APPEND
        );

        // ---- TRIGGER EMBEDDING PIPELINE SUL BACKEND NODE ----
        try {
            $token = get_config('local_configuratore', 'webservicetoken');
            $content = json_encode([
                'token' => $token
            ]);

            $backend_url = getenv('BACKEND_URL') ?: 'http://localhost:3002'; // fallback
            $trigger_url = $backend_url . '/trigger-embeddings';

            file_put_contents(__DIR__ . '/debug.log', "Chiamo backend Node: $trigger_url con token=$token e payload=$content\n", FILE_APPEND);

            // Chiamata POST asincrona
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => $content,
                    'timeout' => 2,    // breve timeout, non aspettare risposta
                ],
            ];
            $context = stream_context_create($opts);

            @file_get_contents($trigger_url, false, $context); // silenzia eventuali warning

            file_put_contents(__DIR__ . '/debug.log', "Chiamata HTTP a backend Node completata\n", FILE_APPEND);

        } catch (Exception $ex) {
            error_log("[local_CONFIGURATORE] Errore trigger embeddings backend: " . $ex->getMessage());
        }

        $redirecturl = null;
        if (!empty($risultato_corso['course']['id'])) {
            $redirecturl = (new moodle_url('/course/view.php', ['id' => $risultato_corso['course']['id']]))->out(false);
        }


        return [
            'success' => true,
            'configid' => $chatbotid,
            'courseid' => isset($risultato_corso['course']['id']) ? $risultato_corso['course']['id'] : null,
            'redirecturl'  => $redirecturl,
            'userid'     => $USER->id,
            'username'   => fullname($USER),
            'email'      => $USER->email
            ];
    }


    /**
     * Definisce i parametri per la funzione 'save_chatbot_config'.
     *
     * @return external_function_parameters I parametri richiesti dalla funzione.
     */
    public static function save_chatbot_config_parameters() {
        return new external_function_parameters([
            'data' => new external_value(PARAM_RAW, 'Configurazione JSON'),
            'filedata' => new external_multiple_structure(
                new external_single_structure([
                    'itemid' => new external_value(PARAM_INT, 'Draft item ID'),
                    'filename' => new external_value(PARAM_TEXT, 'Nome file'),
                    'filepath' => new external_value(PARAM_TEXT, 'Percorso file'),
                    'mimetype' => new external_value(PARAM_TEXT, 'MIME type'),
                    'argomento' => new external_value(PARAM_TEXT, 'Titolo argomento associato'),
                ]),
                'Array file associati ad argomenti',
                VALUE_DEFAULT, []
            ),
        ]);
    }
    /**
     * Definisce il tipo di ritorno della funzione 'save_chatbot_config'.
     *
     * @return external_single_structure La struttura dei dati restituiti dalla funzione.
     */
    public static function save_chatbot_config_returns() {
        return new external_single_structure([
        'success' => new external_value(PARAM_BOOL, 'Esito della richiesta'),
        'configid' => new external_value(PARAM_INT, 'ID della configurazione creata'),
        'courseid'  => new external_value(PARAM_INT, 'ID del corso creato', VALUE_OPTIONAL),
        'redirecturl' => new external_value(PARAM_URL, 'url di redirect', VALUE_OPTIONAL),
        'userid'    => new external_value(PARAM_INT, 'ID utente Moodle'),
        'username'  => new external_value(PARAM_TEXT, 'Nome completo utente'),
        'email'     => new external_value(PARAM_TEXT, 'Email utente'),
        ]);
    }

    public static function get_pending_files() {
        global $DB,$CFG;
        require_once($CFG->dirroot . '/local/configuratore/lib.php');

        $pending = $DB->get_records('local_configuratore_files', ['status' => 'pending']);
        $out = [];
        $fs = get_file_storage();
        foreach ($pending as $file) {
            // Recupera il vero oggetto file Moodle
            $stored_file = $fs->get_file(
                context_system::instance()->id,  // oppure il contextid corretto!
                'local_configuratore',
                'attachments',
                $file->argomentoid,
                '/',
                $file->filename
            );

            $signed_url = null;
            if ($stored_file) {
                $signed_url = local_generate_signed_url($stored_file);
            }

            $out[] = [
                'id' => $file->id,
                'filename' => $file->filename,
                'chatbotid' => $file->chatbotid,
                'argomentoid' => $file->argomentoid,
                'moodlefileid' => $file->moodlefileid,
                'signed_url' => $signed_url,
                'status' => $file->status,
            ];
        }

        return ['files' => $out];
    }

    public static function get_pending_files_parameters() {
        return new external_function_parameters([]);
    }

    public static function get_pending_files_returns() {
        return new external_single_structure([
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'File ID'),
                    'filename' => new external_value(PARAM_TEXT, 'Nome file'),
                    'chatbotid' => new external_value(PARAM_INT, 'ID chatbot'),
                    'argomentoid' => new external_value(PARAM_INT, 'ID argomento'),
                    'moodlefileid' => new external_value(PARAM_INT, 'ID moodle file'),
                    'signed_url' => new external_value(PARAM_TEXT, 'Signed URL per download'),
                    'status' => new external_value(PARAM_TEXT, 'Stato file'),
                ])
            )
        ]);
    }

    public static function update_file_status_parameters() {
        return new external_function_parameters([
            'fileid' => new external_value(PARAM_INT, 'ID del file'),
            'status' => new external_value(PARAM_TEXT, 'Nuovo stato'),
            'embeddingurl' => new external_value(PARAM_TEXT, 'URL embedding', VALUE_DEFAULT,''),
        ]);
    }

    public static function update_file_status($fileid, $status, $embeddingurl = null) {
        global $DB;
        // qui devo mettere i controlli delle capability/sicurzza
        $data = [
            'id' => $fileid,
            'status' => $status,
            'timemodified' => time()
        ];
        if ($embeddingurl) {
            $data['url'] = $embeddingurl;
        }
        $DB->update_record('local_configuratore_files', $data);
        return ['success' => true];
    }

    public static function update_file_status_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Risultato')
        ]);
    }

    public static function get_service_token_parameters() {
        return new external_function_parameters([]);
    }

    public static function get_service_token() {
        global $USER;
        // Solo admin può recuperare il token!
        require_capability('moodle/site:config', context_system::instance());

        $token = get_config('local_configuratore', 'webservicetoken');
        if (!$token) {
            throw new moodle_exception('No webservice token found for local_configuratore.');
        }
        return ['token' => $token];
    }

    public static function get_service_token_returns() {
        return new external_single_structure([
            'token' => new external_value(PARAM_TEXT, 'Webservice Token')
        ]);
    }



    /**
     * Recupera una specifica configurazione del chatbot e i suoi argomenti.
     *
     * @param int $chatbotid ID della configurazione del chatbot da recuperare.
     * @return array Un array contenente la configurazione del chatbot e i suoi argomenti.
     * @throws moodle_exception Se la configurazione non viene trovata o mancano capability.
     */
    public static function get_chatbot_config($chatbotid) {
        global $DB;

        self::validate_parameters(
            self::get_chatbot_config_parameters(),
            ['chatbotid' => $chatbotid]
        );

        self::validate_context(context_system::instance());

        $config = $DB->get_record('local_configuratore_chatbot', ['id' => $chatbotid], '*', MUST_EXIST);
        $argomenti = $DB->get_records('local_configuratore_argomenti', ['chatbotid' => $chatbotid]);

        return [
            'config' => (array)$config,
            'argomenti' => array_map(function($arg) {
                return (array)$arg;
            }, $argomenti),
        ];
    }
    /**
     * Undocumented Function
     *
     * @return void
     */
    public static function get_chatbot_config_parameters() {
        return new external_function_parameters([
            'chatbotid' => new external_value(PARAM_INT, 'ID configurazione'),
        ]);
    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function get_chatbot_config_returns() {
        return new external_single_structure([
            'config' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID'),
                'courseid' => new external_value(PARAM_INT, 'ID corso'),
                'userid' => new external_value(PARAM_INT, 'Creato da'),
                'nomechatbot' => new external_value(PARAM_TEXT, 'Nome chatbot'),
                'corsochatbot' => new external_value(PARAM_TEXT, 'Corso'),
                'descrizionechatbot' => new external_value(PARAM_TEXT, 'Descrizione'),
                'istruzionichatbot' => new external_value(PARAM_TEXT, 'Istruzioni'),
                'datainizio' => new external_value(PARAM_TEXT, 'Data inizio'),
                'datafine' => new external_value(PARAM_TEXT, 'Data fine'),
                'timecreated' => new external_value(PARAM_INT, 'Creato il'),
                'timemodified' => new external_value(PARAM_INT, 'Ultima modifica'),
            ]),
            'argomenti' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'chatbotid' => new external_value(PARAM_INT, 'ID chatbot'),
                    'titolo' => new external_value(PARAM_TEXT, 'Titolo'),
                    'giorno' => new external_value(PARAM_TEXT, 'Giorno'),
                    'timecreated' => new external_value(PARAM_INT, 'Creato il'),
                ])
            ),
        ]);
    }

    /**
     * Undocumented function
     *
     * @param [type] $chatbotid
     * @param [type] $data
     * @param array $filedata
     * @return void
     */
    public static function update_chatbot_config($chatbotid, $data, $filedata = []) {
        global $DB, $USER, $CFG;

        self::validate_parameters(
        self::update_chatbot_config_parameters(),
        ['chatbotid' => $chatbotid, 'data' => $data, 'filedata' => $filedata]
        );

        self::validate_context(context_system::instance());
        require_capability('local/configuratore:manage', context_system::instance());

        $decodeddata = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidjson', 'local_configuratore', '', json_last_error_msg());
        }

        $dati = $decodeddata['DatiIniziali'];
        $argomenti = $decodeddata['argomenti'];

        $transaction = $DB->start_delegated_transaction();

        try {
            // Aggiorna configurazione chatbot.
            $record = $DB->get_record('local_configuratore_chatbot', ['id' => $chatbotid], '*', MUST_EXIST);
            $record->nomechatbot = $dati['nomeChatbot'];
            $record->corsochatbot = $dati['corsoChatbot'];
            $record->descrizionechatbot = $dati['descrizioneChatbot'];
            $record->istruzionichatbot = $dati['istruzioniChatbot'];
            $record->datainizio = strtotime($dati['dataInizio']);
            $record->datafine = strtotime($dati['dataFine']);
            $record->timemodified = time();
            $DB->update_record('local_configuratore_chatbot', $record);

            $fs = get_file_storage();
            $context = context_system::instance();

            // Cancella su GCS
            // $cloudrunurl = 'https://pdf-vectorizer-741129648502.europe-west1.run.app/clear_chatbot_folders';//gcs personale
            $cloudrunurl = 'https://configuratore-chatbot-backend-126516041456.europe-west1.run.app/clear_chatbot_folders';
            $payload = ['chatbotid' => $chatbotid];
            $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($payload),
                'timeout' => 60,
            ],
            ];
            $ctx = stream_context_create($opts);
            $response = file_get_contents($cloudrunurl, false, $ctx);
            error_log(">>> clear_chatbot_folders response: $response");

            // Elimina record files e argomenti su Moodle
            $DB->delete_records('local_configuratore_files', ['chatbotid' => $chatbotid]);

            $oldargs = $DB->get_records('local_configuratore_argomenti', ['chatbotid' => $chatbotid]);
            foreach ($oldargs as $old) {
                $fs->delete_area_files($context->id, 'local_configuratore', 'attachments', $old->id);
            }

            $DB->delete_records('local_configuratore_argomenti', ['chatbotid' => $chatbotid]);

            // Inserisci nuovi argomenti e file
            $draftperargomento = [];
            foreach ($filedata as $fileinfo) {
                $argomentoid = (int)$fileinfo['argomento'];
                $draftperargomento[$argomentoid] = $fileinfo['itemid'];
            }

            foreach ($argomenti as $args) {
                $argomento = (object)[
                'chatbotid' => $chatbotid,
                'titolo'    => $args['titolo'],
                'giorno'    => isset($args['giorno'][0]) ? strtotime($args['giorno'][0]) : 0,
                'timecreated' => time(),
                ];
                $argomentoid = $DB->insert_record('local_configuratore_argomenti', $argomento);

                if (isset($draftperargomento[$argomentoid])) {
                    $draftitemid = $draftperargomento[$argomentoid];

                    file_save_draft_area_files(
                    $draftitemid,
                    $context->id,
                    'local_configuratore',
                    'attachments',
                    $argomentoid,
                    [
                        'subdirs' => 0,
                        'maxfiles' => -1,
                        'maxbytes' => 0,
                    ]
                    );

                    $files = $fs->get_area_files(
                        $context->id,
                        'local_configuratore',
                        'attachments',
                        $argomentoid,
                        'sortorder, timemodified',
                        false
                    );

                    foreach ($files as $file) {
                        if ($file->is_directory()) { continue;
                        }

                        $DB->insert_record('local_configuratore_files', [
                            'chatbotid'    => $chatbotid,
                            'argomentoid'  => $argomentoid,
                            'moodlefileid' => $file->get_id(),
                            'filename'     => $file->get_filename(),
                            'mimetype'     => $file->get_mimetype(),
                            'status'       => 'pending',
                            'url'          => null,
                            'timecreated'  => time(),
                            'timemodified' => time(),
                        ]);
                    }
                }
            }

            $transaction->allow_commit();

            // Lancia il process_embeddings.php in background
            $phpbin_config = get_config('local_configuratore', 'phpbin');

            if (!empty($phpbin_config)) {
                $phpbin = $phpbin_config;
                file_put_contents($CFG->dirroot . '/local/configuratore/scripts/process_embeddings_async.log', "USO il php configurato dal plugin", FILE_APPEND);
            } else {
                $phpbin = 'php';
                file_put_contents($CFG->dirroot . '/local/configuratore/scripts/process_embeddings_async.log', "USO php di default (PATH)", FILE_APPEND);
            }
            $script = $CFG->dirroot . '/local/configuratore/scripts/process_embeddings.php';
            $logfile = $CFG->dirroot . '/local/configuratore/scripts/process_embeddings_async.log';

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = "start /B \"\" \"$phpbin\" \"$script\" > \"$logfile\" 2>&1";
            } else {
                $cmd = "$phpbin \"$script\" > /dev/null 2>&1 &";
            }

            file_put_contents($CFG->dirroot . '/local/configuratore/scripts/process_embeddings_debug.log', "Lancio comando: $cmd\n", FILE_APPEND);

            pclose(popen($cmd, 'r'));
            exec($cmd);

            return ['success' => true, 'message' => 'Configurazione aggiornata con successo.'];

        } catch (Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }


    /**
     * Undocumented function
     *
     * @return void
     */
    public static function update_chatbot_config_parameters() {
        return new external_function_parameters([
        'chatbotid' => new external_value(PARAM_INT, 'ID configurazione da aggiornare'),
        'data' => new external_value(PARAM_RAW, 'Configurazione JSON aggiornata'),
        'filedata' => new external_multiple_structure(
            new external_single_structure([
                'itemid' => new external_value(PARAM_INT, 'Draft item ID'),
                'filename' => new external_value(PARAM_TEXT, 'Nome file'),
                'filepath' => new external_value(PARAM_TEXT, 'Percorso file'),
                'mimetype' => new external_value(PARAM_TEXT, 'Tipo MIME'),
                'argomento_index' => new external_value(PARAM_INT, 'Indice dell\'argomento associato'),
            ]),
            'File da salvare',
            VALUE_DEFAULT, []
        ),
        ]);
    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function update_chatbot_config_returns() {
        return new external_single_structure([
        'success' => new external_value(PARAM_BOOL, 'Esito'),
        'message' => new external_value(PARAM_TEXT, 'Messaggio'),
        ]);
    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function get_chatbot_list() {
        global $DB;

        self::validate_context(context_system::instance());

        $chatbots = $DB->get_records('local_configuratore_chatbot', null, 'timecreated DESC');
        $result = [];

        foreach ($chatbots as $chatbot) {
            $argomenti = $DB->get_records('local_configuratore_argomenti', ['chatbotid' => $chatbot->id]);
            $arglist = [];
            foreach ($argomenti as $arg) {
                $arglist[] = [
                    'id' => $arg->id,
                    'titolo' => $arg->titolo,
                ];
            }

            $result[] = [
                'id' => $chatbot->id,
                'nomechatbot' => $chatbot->nomechatbot,
                'corsochatbot' => $chatbot->corsochatbot,
                'argomenti' => $arglist,
                'datainizio' => date('Y-m-d', $chatbot->datainizio),
                'datafine' => date('Y-m-d', $chatbot->datafine),
                'timecreated' => userdate($chatbot->timecreated),
            ];
        }

        return ['chatbots' => $result];
    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function get_chatbot_list_parameters() {
        return new external_function_parameters([]);
    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function get_chatbot_list_returns() {
        return new external_single_structure([
            'chatbots' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID del chatbot'),
                    'nomechatbot' => new external_value(PARAM_TEXT, 'Nome del chatbot'),
                    'corsochatbot' => new external_value(PARAM_TEXT, 'Corso associato'),
                    'argomenti' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'ID dell\'argomento'),
                            'titolo' => new external_value(PARAM_TEXT, 'Titolo dell\'argomento'),
                        ])
                    ),
                    'datainizio' => new external_value(PARAM_TEXT, 'Data inizio'),
                    'datafine' => new external_value(PARAM_TEXT, 'Data fine'),
                    'timecreated' => new external_value(PARAM_TEXT, 'Data creazione'),
                ])
            ),
        ]);
    }
    /**
     * Undocumented function
     *
     * @param [type] $chatbotid
     * @return void
     */
    public static function delete_chatbot($chatbotid) {
        global $DB;
        self::validate_context(context_system::instance());
        self::validate_parameters(
            self::get_chatbot_config_parameters(),
            ['chatbotid' => $chatbotid]
        );
        require_capability('local/configuratore:manage', context_system::instance());
        $chatbot = $DB->get_record('local_configuratore_chatbot', ['id' => $chatbotid]);
        if (!$chatbot) {
            throw new moodle_exception('invalidid', 'error', '', 'Chatbot con id ' . $chatbotid . ' non trovato.');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $context = context_system::instance();
            $fs = get_file_storage();

            // Elimino i file associati agli argomenti del chatbot.
            $argomenti = $DB->get_records('local_configuratore_argomenti', ['chatbotid' => $chatbotid]);
            foreach ($argomenti as $argomento) {
                $fs->delete_area_files($context->id, 'local_configuratore', 'attachments', $argomento->id);
            }

            $argcount = $DB->count_records('local_configuratore_argomenti', ['chatbotid' => $chatbotid]);
            if ($argcount > 0) {
                if (!$DB->delete_records('local_configuratore_argomenti', ['chatbotid' => $chatbotid])) {
                    throw new moodle_exception('deleteargomenterror', 'local_configuratore', '', 'Impossibile eliminare gli argomenti del chatbot.');
                }
            }

            // Elimina configurazione chatbot.
            if (!$DB->delete_records('local_configuratore_chatbot', ['id' => $chatbotid])) {
                throw new moodle_exception('deletechatboterror', 'local_configuratore', '', 'Impossibile eliminare il chatbot con id ' . $chatbotid);
            }

            $transaction->allow_commit();

            return ['success' => true, 'message' => 'Chatbot eliminato con successo.'];

        }catch (moodle_exception $e) {
            $transaction->rollback($e);
            throw $e;
        }catch (Exception $e) {
            $transaction->rollback($e);
            throw new moodle_exception('deletechatboterror', 'local_configuratore', '', 'Errore durante l\'eliminazione del chatbot: ' . $e->getMessage());
        }

    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function delete_chatbot_parameters() {
        return new external_function_parameters([
            'chatbotid' => new external_value(PARAM_INT, 'ID del chatbot da eliminare'),
        ]);
    }
    /**
     * Undocumented function
     *
     * @return void
     */
    public static function delete_chatbot_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Esito'),
            'message' => new external_value(PARAM_TEXT, 'Messaggio'),
        ]);
    }
}
