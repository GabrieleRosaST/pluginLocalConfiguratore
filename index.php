<?php
require('../../config.php');

require_login(); // o rimuovi se vuoi accesso pubblico

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
  src="index.html?sesskey=<?php echo $USER->sesskey; ?>&wwwroot=<?php echo $CFG->wwwroot; ?>"
  style="width:100%; border:none; min-height:1050px" scrolling="no">
</iframe>

<?php



echo $OUTPUT->footer();