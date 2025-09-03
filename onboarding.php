<?php
/**
 * ONBOARDING PAGE - Pagina principale per la gestione delle configurazioni del chatbot VARK
 * 
 * Questa pagina funge da dashboard principale dove gli utenti possono:
 * - Visualizzare tutte le loro configurazioni esistenti
 * - Creare nuove configurazioni 
 * - Eliminare configurazioni esistenti
 * - Navigare verso la pagina di configurazione dettagliata
 */

// Carica la configurazione principale di Moodle (connessione DB, autenticazione, ecc.)
require_once('../../config.php');

// SICUREZZA: Verifica che l'utente sia autenticato
// Se non è loggato, viene reindirizzato automaticamente alla pagina di login
require_login();

/**
 * GESTIONE ELIMINAZIONE CONFIGURAZIONI
 * 
 * Questa sezione gestisce le richieste di eliminazione delle configurazioni tramite GET.
 * Implementa i seguenti controlli di sicurezza:
 * 1. Verifica del parametro 'delete' nell'URL
 * 2. Controllo del sesskey (protezione CSRF - Cross-Site Request Forgery)
 * 3. Verifica che la configurazione appartenga all'utente corrente
 */
if (isset($_GET['delete']) && confirm_sesskey()) {
    global $DB, $USER;
    $config_id = (int)$_GET['delete']; // Cast a intero per sicurezza
    
    // SICUREZZA: Verifica che la configurazione esista e appartenga all'utente corrente
    // Questo previene che un utente elimini configurazioni di altri utenti
    $config = $DB->get_record('local_configuratore_chatbot', array('id' => $config_id, 'userid' => $USER->id));
    if ($config) {
        // Eliminazione sicura dalla tabella del database
        $DB->delete_records('local_configuratore_chatbot', array('id' => $config_id));
        // Reindirizzamento con messaggio di successo
        redirect(new moodle_url('/local/configuratore/onboarding.php'), 'Configurazione eliminata con successo', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

/**
 * FUNZIONE UTILITY: Calcolo del tempo relativo
 * 
 * Converte un timestamp Unix in una rappresentazione user-friendly del tempo trascorso.
 * Esempi di output: "Ora", "5 minuti fa", "2 ore fa", "Ieri", "3 giorni fa", ecc.
 * 
 * @param int $timestamp - Timestamp Unix della data da confrontare
 * @return string - Stringa formattata con il tempo relativo in italiano
 */
function timeAgo($timestamp) {
    $time = time() - $timestamp; // Calcola la differenza in secondi
    
    // Logica di conversione progressiva da secondi a unità più grandi
    if ($time < 60) {
        return 'Ora'; // Meno di 1 minuto
    } elseif ($time < 3600) { // Meno di 1 ora
        $minutes = floor($time/60);
        return $minutes == 1 ? '1 minuto fa' : $minutes . ' minuti fa';
    } elseif ($time < 86400) { // Meno di 1 giorno
        $hours = floor($time/3600);
        return $hours == 1 ? '1 ora fa' : $hours . ' ore fa';
    } elseif ($time < 172800) { // Meno di 2 giorni
        return 'Ieri';
    } elseif ($time < 604800) { // Meno di 1 settimana
        $days = floor($time/86400);
        return $days . ' giorni fa';
    } elseif ($time < 2419200) { // Meno di 1 mese (28 giorni)
        $weeks = floor($time/604800);
        return $weeks == 1 ? '1 settimana fa' : $weeks . ' settimane fa';
    } else { // Più di 1 mese
        $months = floor($time/2419200);
        return $months == 1 ? '1 mese fa' : $months . ' mesi fa';
    }
}

/**
 * CONFIGURAZIONE DELLA PAGINA MOODLE
 * 
 * Imposta i parametri necessari per l'integrazione con il sistema Moodle:
 * - Contesto di sicurezza (sistema globale)
 * - URL della pagina (per navigazione e breadcrumb)
 * - Titolo e intestazione della pagina
 * - Collegamento ai file CSS personalizzati
 */

// Imposta il contesto di sicurezza a livello di sistema
$context = context_system::instance();
$PAGE->set_context($context);

// Definisce l'URL di questa pagina per la navigazione Moodle
$PAGE->set_url('/local/configuratore/onboarding.php');

// Imposta titolo e intestazione della pagina
$PAGE->set_title(get_string('pluginname', 'local_configuratore'));
$PAGE->set_heading('Configuratore Chatbot VARK');

// Collega il file CSS personalizzato per lo styling della pagina
$PAGE->requires->css('/local/configuratore/assets/onboarding.css');

// Genera l'header standard di Moodle (navbar, menu, ecc.)
echo $OUTPUT->header();
?>

<!-- 
    SEZIONE HTML: Interfaccia utente principale
    
    Struttura della pagina:
    1. Container principale con layout centrato
    2. Icona/logo del chatbot 
    3. Testo hero con call-to-action
    4. Pulsante per creare nuova configurazione
    5. Griglia delle configurazioni esistenti (generata dinamicamente)
-->

<div class="onboarding-container">
   
    <!-- Icona principale del chatbot -->
    <div class="onboarding-icon">
        <img src="img/testa.svg" alt="Chatbot VARK Icon">
    </div>
    
    <!-- Testo hero principale con elementi evidenziati -->
    <div class="hero-text">
        <p>
            Configura il tuo <span class="highlight-yellow">corso</span> di insegnamento,
        </p>
        <p>
            con associato il <span class="highlight-blue">chatbot</span> di autoapprendimento
        </p>
        <p>
            che aiuta gli studenti nello studio
        </p>
    </div>

    <!-- Pulsante call-to-action per creare nuova configurazione -->
    <div class="onboarding-button-container">
        <button onclick="window.location.href='index.php';" class="onboarding-button">
            <span>
                Nuova configuazione
            </span>
            <img src="img/freccia.svg" alt="Freccia" style="width: 20px; height:100%;">
        </button>
    </div>

    <?php
    /**
     * SEZIONE PHP: Recupero e visualizzazione delle configurazioni esistenti
     * 
     * Questa sezione:
     * 1. Esegue una query per recuperare tutte le configurazioni dell'utente corrente
     * 2. Le ordina per ID decrescente (più recenti per prime)
     * 3. Genera dinamicamente le card HTML per ogni configurazione
     * 4. Include funzionalità di visualizzazione, modifica ed eliminazione
     */
    
    // Accesso alle variabili globali di Moodle
    global $DB, $USER;
    
    // QUERY DATABASE: Recupera configurazioni dell'utente corrente ordinate per data creazione
    $sql = "SELECT * FROM {local_configuratore_chatbot} WHERE userid = :userid ORDER BY id DESC";
    $params = array('userid' => $USER->id);
    $configurations = $DB->get_records_sql($sql, $params);
    
    // GENERAZIONE DINAMICA DELL'INTERFACCIA: Solo se esistono configurazioni
    if (!empty($configurations)) {
        echo '<div class="configurations-section">';
        echo '<h3 class="configurations-title">Le tue configurazioni</h3>';
        echo '<div class="configurations-grid">';
        
        /**
         * LOOP DI GENERAZIONE DELLE CARD
         * 
         * Per ogni configurazione trovata nel database, genera una card interattiva che include:
         * - Calcolo del tempo relativo di creazione
         * - Pulsante di eliminazione con conferma
         * - Nome del corso (con escape HTML per sicurezza)
         * - Data di creazione in formato user-friendly
         * - Azioni disponibili (visualizza/modifica)
         */
        foreach ($configurations as $config) {
            // Calcola e formatta il tempo trascorso dalla creazione
            $timeAgoText = timeAgo($config->timecreated);
            
            // GENERAZIONE HTML DELLA CARD
            // onclick principale: visualizza la configurazione
            echo '<div class="configuration-card" onclick="viewConfiguration(' . $config->id . ')">';
            
            // Pulsante eliminazione con stopPropagation per evitare conflitti con onclick della card
            echo '<button class="config-delete-btn" onclick="event.stopPropagation(); deleteConfiguration(' . $config->id . ')" title="Elimina configurazione">';
            echo '<img src="img/trash.svg" alt="Elimina" style="width: 16px; height: 16px;">';
            echo '</button>';
            
            // Nome del corso (htmlspecialchars previene attacchi XSS)
            echo '<h4 class="configuration-course-name">' . htmlspecialchars($config->corsochatbot) . '</h4>';
            
            // Data di creazione in formato relativo (visibile solo al hover)
            echo '<div class="configuration-date">' . $timeAgoText . '</div>';
            
            // Sezione azioni (visibile al hover)
            echo '<div class="configuration-actions">';
            echo '<span class="config-view-text">Apri</span>';
            echo '</div>';
            
            echo '</div>';
        }
        
        echo '</div>'; // Chiude configurations-grid
        echo '</div>'; // Chiude configurations-section
    }
    ?>
    
</div>

<script>
/**
 * JAVASCRIPT: Gestione delle interazioni utente
 * 
 * Queste funzioni gestiscono le azioni principali sulle configurazioni:
 * - Modifica configurazione esistente
 * - Visualizzazione configurazione 
 * - Eliminazione con conferma utente
 */

/**
 * Reindirizza alla pagina di configurazione in modalità modifica
 * @param {number} configId - ID della configurazione da modificare
 */
function editConfiguration(configId) {
    window.location.href = 'index.php?edit=' + configId;
}

/**
 * Reindirizza alla pagina di visualizzazione della configurazione
 * @param {number} configId - ID della configurazione da visualizzare
 */
function viewConfiguration(configId) {
    window.location.href = 'index.php?view=' + configId;
}

/**
 * Gestisce l'eliminazione di una configurazione con conferma utente
 * Implementa protezione CSRF tramite sesskey di Moodle
 * @param {number} configId - ID della configurazione da eliminare
 */
function deleteConfiguration(configId) {
    // Conferma utente prima dell'eliminazione (UX safety)
    if (confirm('Sei sicuro di voler eliminare questa configurazione?')) {
        // Reindirizzamento con sesskey per protezione CSRF
        window.location.href = 'onboarding.php?delete=' + configId + '&sesskey=<?php echo sesskey(); ?>';
    }
}
</script>

<?php
/**
 * CHIUSURA DELLA PAGINA MOODLE
 * 
 * Genera il footer standard di Moodle che include:
 * - Script JavaScript di sistema
 * - Footer navigation 
 * - Chiusura dei tag HTML
 * - Debug info (se abilitato)
 */
echo $OUTPUT->footer();
?>
