<?php
require('../../config.php');

require_login();

// GESTIONE VISUALIZZAZIONE CONFIGURAZIONE ESISTENTE
global $DB, $USER;
$config_data = null;

// DEBUG: Log dei parametri ricevuti
error_log("DEBUG index.php - Parametri URL ricevuti: " . print_r($_GET, true));

// Controlla se è stata richiesta la visualizzazione di una configurazione esistente
if (isset($_GET['view'])) {
    $config_id = (int)$_GET['view'];
    error_log("DEBUG index.php - Richiesta visualizzazione configurazione ID: " . $config_id);
    
    // SICUREZZA: Verifica che la configurazione esista e appartenga all'utente corrente
    $config_data = $DB->get_record('local_configuratore_chatbot', 
        array('id' => $config_id, 'userid' => $USER->id)
    );
    
    if ($config_data) {
        error_log("DEBUG index.php - Dati configurazione trovati: " . print_r($config_data, true));
    } else {
        error_log("DEBUG index.php - Nessuna configurazione trovata per ID: " . $config_id . " e user: " . $USER->id);
    }
    
    // Se la configurazione non esiste o non appartiene all'utente, reindirizza
    if (!$config_data) {
        redirect(new moodle_url('/local/configuratore/onboarding.php'), 
                'Configurazione non trovata o accesso negato', 
                null, \core\output\notification::NOTIFY_ERROR);
    }
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/configuratore/index.php'));
$PAGE->set_title('La mia app full width');
$PAGE->set_heading('Configuratore Corsi');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

?>
<style>
/* Nasconde i titoli predefiniti di Moodle */
#region-main h1:first-of-type,
.page-header-headings h1:not(.main-header) {
    display: none !important;
}

/* CSS per rimuovere tutti i bordi e ombre */
*,
*::before,
*::after {
    border: none !important;
    border-top: none !important;
    border-bottom: none !important;
    box-shadow: none !important;
    outline: none !important;
}

/* Rimuove lo spazio bianco sopra il contenuto */
#page,
#page-wrapper,
#page-content,
#region-main,
.region-main {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

/* Riduce padding del body se necessario */
body {
    padding-top: 0 !important;
}
</style>

<iframe
  id="myAppFrame"
  src="<?php 
    // Costruisce l'URL base dell'iframe
    $iframe_url = "index.html?sesskey=" . $USER->sesskey . "&wwwroot=" . urlencode($CFG->wwwroot) . "&userid=" . $USER->id;
    
    // Aggiunge il parametro mode se presente
    if (isset($_GET['mode'])) {
        $mode = clean_param($_GET['mode'], PARAM_ALPHA);
        $iframe_url .= "&mode=" . urlencode($mode);
    }

    
    // Se ci sono dati da pre-compilare, aggiungili all'URL
    if ($config_data) {
        // Converti i timestamp in formato yyyy-MM-dd per i campi date HTML
        $data_inizio_formatted = !empty($config_data->datainizio) ? date('Y-m-d', $config_data->datainizio) : '';
        $data_fine_formatted = !empty($config_data->datafine) ? date('Y-m-d', $config_data->datafine) : '';
        
        $params = array(
            'corsoChatbot' => urlencode($config_data->corsochatbot),
            'nomeChatbot' => urlencode($config_data->nomechatbot),
            'descrizioneChatbot' => urlencode($config_data->descrizionechatbot),
            'istruzioniChatbot' => urlencode($config_data->istruzionichatbot),
            'dataInizio' => $data_inizio_formatted,
            'dataFine' => $data_fine_formatted,
            'configId' => $config_data->id,
            'courseId' => urlencode($config_data->courseid),
        );
        
        foreach ($params as $key => $value) {
            if (!empty($value)) {
                $iframe_url .= "&" . $key . "=" . $value;
            }
        }
    }
    
    echo $iframe_url;
  ?>"
  style="width:100%; border:none; min-height:1050px" scrolling="no">
</iframe>

<script>
console.log('Index.php Debug - Configuration data being passed to React iframe:');
<?php if ($mode === 'edit' && $config_data): ?>
console.log({
    mode: '<?php echo $mode; ?>',
    corsoChatbot: '<?php echo htmlspecialchars($config_data->corsochatbot); ?>',
    nomeChatbot: '<?php echo htmlspecialchars($config_data->nomechatbot); ?>',
    descrizioneChatbot: '<?php echo htmlspecialchars($config_data->descrizionechatbot); ?>',
    istruzioniChatbot: '<?php echo htmlspecialchars($config_data->istruzionichatbot); ?>',
    dataInizio: '<?php echo $data_inizio_formatted; ?>',
    dataFine: '<?php echo $data_fine_formatted; ?>',
    configId: '<?php echo $config_data->id; ?>',
    courseId: '<?php echo $config_data->courseid; ?>',
    userId: '<?php echo $config_data->userid; ?>'
});
<?php else: ?>
console.log({
    mode: 'create',
    courseId: '<?php echo $course_id; ?>',
    userId: '<?php echo $user_id; ?>'
});
<?php endif; ?>
</script>

<?php
echo $OUTPUT->footer();