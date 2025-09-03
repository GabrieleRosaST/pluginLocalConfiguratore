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
 * External functions and service declaration for Configurator local
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/external/description}
 *
 * @package    local_configuratore
 * @category   webservice
 * @copyright  2025 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_configuratore_save_chatbot_config' => [ // Funzione per salvare i dati.
        'classname'   => 'local_configuratore_external',
        'methodname'  => 'save_chatbot_config',
        'classpath'   => 'local/configuratore/externallib.php',
        'description' => 'Salva la configurazione del chatbot e i file associati.',
        'type'        => 'write',
        'capabilities' => 'local/configuratore:manage',
        'ajax'        => true,
    ],

    'local_configuratore_get_chatbot_config' => [ // Funzione per leggere i dati.
        'classname'   => 'local_configuratore_external',
        'methodname'  => 'get_chatbot_config',
        'classpath'   => 'local/configuratore/externallib.php',
        'description' => 'Restituisce la configurazione del chatbot e i suoi argomenti.',
        'type'        => 'read',
        'capabilities' => 'local/configuratore:manage',
        'ajax'        => true,
    ],

    'local_configuratore_update_chatbot_config' => [ // Funzione per aggiornare i dati.
        'classname'   => 'local_configuratore_external',
        'methodname'  => 'update_chatbot_config',
        'classpath'   => 'local/configuratore/externallib.php',
        'description' => 'Aggiorna la configurazione del chatbot con nuovi dati.',
        'type'        => 'write',
        'capabilities' => 'local/configuratore:manage',
        'ajax'        => true,
    ],

    'local_configuratore_get_chatbot_list' => [
        'classname'  => 'local_configuratore_external',
        'methodname' => 'get_chatbot_list',
        'classpath'   => 'local/configuratore/externallib.php',
        'description' => 'Restituisce un elenco dei chatbot con il nome, argomenti e id associato.',
        'type'        => 'read',
        'capabilities' => 'local/configuratore:manage',
        'ajax'        => true,
    ],
    'local_configuratore_delete_chatbot' => [
        'classname'  => 'local_configuratore_external',
        'methodname' => 'delete_chatbot',
        'classpath'   => 'local/configuratore/externallib.php',
        'description' => 'Elimina un chatbot specifico.',
        'type'        => 'write',
        'capabilities' => 'local/configuratore:manage',
        'ajax'        => true,
    ],
        'local_configuratore_get_pending_files' => [
        'classname'    => 'local_configuratore_external',
        'methodname'   => 'get_pending_files',
        'classpath'    => 'local/configuratore/externallib.php',
        'description'  => 'Restituisce i file con stato pending.',
        'type'         => 'read',
        'capabilities' => 'local/configuratore:manage',
        'ajax'         => true,
    ],
    'local_configuratore_update_file_status' => [
        'classname'    => 'local_configuratore_external',
        'methodname'   => 'update_file_status',
        'classpath'    => 'local/configuratore/externallib.php',
        'description'  => 'Aggiorna lo stato di un file.',
        'type'         => 'write',
        'capabilities' => 'local/configuratore:manage',
        'ajax'         => true,
    ],
    'local_configuratore_get_service_token' => [
        'classname'    => 'local_configuratore_external',
        'methodname'   => 'get_service_token',
        'classpath'    => 'local/configuratore/externallib.php',
        'description'  => 'Restituisce il token di servizio per l\'integrazione.',
        'type'         => 'read',
        'capabilities' => 'moodle/site:config', // solo admin
        'ajax'         => true,
    ],

];

$services = [
    'local_configuratore_service' => [
        'functions' => [
            'local_configuratore_save_chatbot_config',
            'local_configuratore_get_chatbot_config',
            'local_configuratore_update_chatbot_config',
            'local_configuratore_get_chatbot_list',
            'local_configuratore_delete_chatbot',
            'local_configuratore_get_pending_files',
            'local_configuratore_update_file_status',
            'local_configuratore_get_service_token',
            'core_course_create_courses',
        ],
        'restrictedusers' => 0, // Permette a qualsiasi utente con capability di usarlo.
        'enabled' => 1,
        'shortname' => 'local_configuratore_service',
    ],
];
